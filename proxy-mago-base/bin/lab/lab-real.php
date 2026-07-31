<?php
/**
 * LABORATÓRIO REAL ÚNICO — valida aqui o que vai rodar na VPS de produção.
 *
 * Faz, em uma só passada e sem mock:
 *   1. conecta no MySQL real do XUI (read-only) e espelha usuários
 *   2. espelha catálogo de streams (amostra) e sessões ativas do XUI
 *   3. dispara tráfego REAL pela CDN local (Host = domínio público)
 *   4. confere o contador inteligente por usuário (playlist x vídeo)
 *   5. confere saúde do direct source e nós de LB
 *
 * Uso:
 *   php bin/lab/lab-real.php            (lê variáveis de ambiente)
 *
 * Variáveis:
 *   LAB_DB_HOST LAB_DB_PORT LAB_DB_NAME LAB_DB_USER LAB_DB_PASS
 *   LAB_ORIGIN LAB_PUBLIC LAB_USER LAB_PASS LAB_BASE LAB_STREAMS
 */
require __DIR__ . '/../../app/bootstrap-cli.php';

putenv('CDN_LAB_COUNT_LOOPBACK=1');
SettingsRepository::set('lab_count_loopback', 1);

function env(string $k, string $default = ''): string
{
    $v = getenv($k);
    return $v === false || $v === '' ? $default : (string) $v;
}

function line(string $t): void { echo "\n== $t\n"; }
function fail(string $t): void { echo "   FALHA: $t\n"; }

$dbHost = env('LAB_DB_HOST', env('LAB_ORIGIN', '38.190.176.170'));
$dbPort = (int) env('LAB_DB_PORT', '3306');
$dbName = env('LAB_DB_NAME', 'xui');
$dbUser = env('LAB_DB_USER');
$dbPass = env('LAB_DB_PASS');
$origin = env('LAB_ORIGIN', $dbHost);
$public = env('LAB_PUBLIC', 'voods.suafontee.com');
$user   = env('LAB_USER');
$pass   = env('LAB_PASS');
$base   = env('LAB_BASE', 'http://127.0.0.1:8080');
$maxStreams = (int) env('LAB_STREAMS', '3');

if ($dbUser === '' || $user === '') {
    fwrite(STDERR, "defina LAB_DB_USER/LAB_DB_PASS e LAB_USER/LAB_PASS no ambiente.\n");
    exit(2);
}

$ok = 0;
$bad = 0;
function check(bool $cond, string $label, string $extra = ''): void
{
    global $ok, $bad;
    if ($cond) { $ok++; printf("   ok   %s %s\n", $label, $extra); }
    else { $bad++; printf("   FALHA %s %s\n", $label, $extra); }
}

line('0. configuração real do XUI (read-only)');
XuiSyncConfig::save([
    'host' => $dbHost, 'port' => $dbPort, 'database_name' => $dbName,
    'username' => $dbUser, 'password' => $dbPass, 'sync_enabled' => 1,
]);
$ping = XuiReadOnly::ping();
check((bool) $ping['ok'], 'ping MySQL do XUI', 'ms=' . $ping['ms'] . ' ' . (string) ($ping['error'] ?? ''));
if (!$ping['ok']) { exit(1); }

line('1. espelho de usuários e catálogo');
$s = ['processed' => 0, 'failed' => 0, 'details' => []];
XuiSyncService::syncUsers($s);
check($s['processed'] > 0, 'usuários espelhados', '=' . $s['processed']);

$s2 = ['processed' => 0, 'failed' => 0, 'details' => []];
try { XuiSyncService::syncStreams($s2); } catch (Throwable $e) { fail('streams: ' . $e->getMessage()); }
$streamsCached = (int) Database::pdo()->query('SELECT COUNT(*) FROM xui_streams_cache')->fetchColumn();
check($streamsCached > 0, 'catálogo de streams espelhado', '=' . $streamsCached);

$s3 = ['processed' => 0, 'failed' => 0, 'details' => []];
try { XuiSyncService::syncActivity($s3); check(true, 'sessões ativas lidas do XUI', '=' . $s3['processed']); }
catch (Throwable $e) { fail('activity: ' . $e->getMessage()); }

line('2. origem protegida e domínio público');
$originId = XuiOrigin::save('a', $origin, 80);
if (!AliasRepository::findByHostname($public)) {
    AliasRepository::create(['hostname' => $public, 'origin_id' => $originId, 'active' => 1, 'is_primary' => 1]);
}
check($originId > 0, 'origem XUI mapeada', $origin . ' -> ' . $public);

function hit(string $base, string $public, string $path, string $ua): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: ' . $public],
        CURLOPT_USERAGENT => $ua,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_WRITEFUNCTION => static fn ($c, $d) => strlen($d),
        CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$hdr) { $hdr .= $h; return strlen($h); },
    ]);
    $hdr = '';
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $bytes = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    return [$code, $bytes, $hdr];
}

$q = 'username=' . rawurlencode($user) . '&password=' . rawurlencode($pass);

line('3. playlist real (get.php) — deve baixar como arquivo e NÃO ocupar slot');
[$c, $b, $h] = hit($base, $public, '/get.php?' . $q . '&type=m3u_plus&output=hls', 'LabCurl/1.0');
check($c === 200, 'HTTP da playlist', '=' . $c . ' bytes=' . number_format($b));
check(stripos($h, 'content-disposition: attachment') !== false, 'playlist vem como download');
check(stripos($h, (string) $origin) === false && stripos($h, 'dafonte') === false, 'headers sem vazar origem');

line('4. player_api real');
[$c] = hit($base, $public, '/player_api.php?' . $q, 'LabCurl/1.0');
check($c === 200, 'HTTP do player_api', '=' . $c);

line('5. vídeo real em streams distintos do catálogo');
$ids = Database::pdo()->query(
    'SELECT stream_id FROM xui_streams_cache WHERE stream_id > 0 ORDER BY stream_id LIMIT ' . max(1, $maxStreams)
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $sid) {
    [$c, $b] = hit($base, $public, '/live/' . rawurlencode($user) . '/' . rawurlencode($pass) . '/' . (int) $sid . '.ts', 'TiviMate/4.7.0');
    printf("   stream %-9s HTTP %d %s bytes\n", $sid, $c, number_format($b));
}
$videoSessions = (int) Database::pdo()->query(
    "SELECT COUNT(*) FROM cdn_sessions WHERE username = " . Database::pdo()->quote($user) .
    " AND session_kind NOT IN ('playlist','api')"
)->fetchColumn();
check($videoSessions >= count($ids) && count($ids) > 0, 'uma conexão por stream distinto', '=' . $videoSessions . ' de ' . count($ids));

line('6. burst HLS no mesmo stream — não pode inflar conexão');
$sid = (int) ($ids[0] ?? 1);
for ($i = 0; $i < 5; $i++) {
    hit($base, $public, '/live/' . rawurlencode($user) . '/' . rawurlencode($pass) . '/' . $sid . '.ts', 'TiviMate/4.7.0');
}
$after = (int) Database::pdo()->query(
    "SELECT COUNT(*) FROM cdn_sessions WHERE username = " . Database::pdo()->quote($user) .
    " AND session_kind NOT IN ('playlist','api')"
)->fetchColumn();
check($after === $videoSessions, 'burst manteve o mesmo total de conexões', $videoSessions . ' -> ' . $after);

line('7. contador inteligente do usuário real');
$d = UserIntelligence::detail($user);
$u = $d['user'] ?? null;
if ($u) {
    printf("   %s: em uso=%d de %s (livres %s) | cdn=%d xui=%d fonte=%s status=%s\n",
        $u['username'], $u['connections_used'], $u['max_connections'], (string) $u['connections_free'],
        $u['cdn_connections_now'], $u['xui_connections_now'], $u['count_source'], $u['status']);
    printf("   playlist/api abertas: %d | direct source: %d\n", $u['fetch_sessions_now'], $u['direct_sessions_now']);
    check((int) $u['cdn_connections_now'] > 0, 'painel enxerga conexão de vídeo do laboratório');
    check((int) $u['fetch_sessions_now'] > 0, 'painel enxerga playlist/api separada do plano');
} else {
    fail('usuário não encontrado no espelho do XUI');
    $bad++;
}

line('8. jobs de consolidação (o painel lê KPI consolidado, não conta na hora)');
foreach ([
    'consolidate_runtime' => static fn (array &$s) => RestreamRuntime::consolidate($s),
    'metrics_rollup_light' => static fn (array &$s) => RestreamRuntime::metricsRollupLight($s),
] as $job => $fn) {
    $r = JobRunner::run($job, 'lab', $fn);
    printf("   %-22s status=%s processados=%d falhas=%d %dms %s\n", $job, $r['status'],
        $r['processed'], $r['failed'], $r['duration_ms'], (string) $r['error']);
    check($r['status'] === 'ok', 'job ' . $job);
}
Cache::flush();

line('9. totais do parque (é isso que o painel mostra)');
$t = UserIntelligence::totalsFresh();
foreach ($t as $k => $v) { printf("   %-20s %s\n", $k, is_scalar($v) ? $v : json_encode($v)); }
check((int) $t['connections_video'] > 0, 'KPI de conexões de vídeo no painel', '=' . $t['connections_video']);
check((int) $t['sessions_fetch'] > 0, 'KPI de playlist/api no painel', '=' . $t['sessions_fetch']);
check((int) $t['users_online'] > 0, 'KPI de usuários online', '=' . $t['users_online']);

line('10. saúde do direct source');
$sum = DirectHostHealth::summary(60);
printf("   %s\n", json_encode($sum, JSON_UNESCAPED_UNICODE));
foreach (DirectHostHealth::hosts(60, 10) as $h2) {
    printf("   host=%-28s veredito=%-12s culpa=%-12s amostras=%s\n",
        substr((string) ($h2['host'] ?? '-'), 0, 28),
        (string) ($h2['verdict'] ?? '-'), (string) ($h2['blame'] ?? '-'), (string) ($h2['samples'] ?? 0));
}

line('11. nós de LB registrados');
$nodes = LbNode::all();
printf("   nós=%d\n", count($nodes));
foreach ($nodes as $n) {
    printf("   lb #%d %s:%s enabled=%s drain=%s auth=%s\n", $n['id'], $n['ip'], $n['ssh_port'] ?? 22,
        $n['enabled'] ?? 0, $n['drain'] ?? 0, $n['auth_mode'] ?? '-');
}

printf("\nRESULTADO DO LABORATÓRIO: %d ok / %d falhas\n", $ok, $bad);
exit($bad === 0 ? 0 : 1);