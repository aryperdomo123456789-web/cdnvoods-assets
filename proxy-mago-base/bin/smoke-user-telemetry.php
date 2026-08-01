<?php
/**
 * Smoke da telemetria por usuário/conexão (aba viva do assinante).
 *
 * Prova, sem depender de job atrasado:
 *   - a CDN resolve o conteúdo ANTES do XUI (canal/filme/série + nome)
 *   - episódio vira "Série · S01E02" usando o espelho de streams_episodes
 *   - cada conexão aparece separada com uptime próprio
 *   - o resumo por usuário fecha (em uso / livres / por tipo)
 *   - os totais do painel são vivos (não dependem de metrics_rollup)
 *   - a trava por IP é por usuário (IP personalizado de cada um)
 */
require __DIR__ . '/../app/bootstrap-cli.php';
require __DIR__ . '/lib/smoke-sessions.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $extra = ''): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [ok]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label" . ($extra !== '' ? " ($extra)" : '') . "\n"; }
}

$user = 'smoke_tel_user';
$pdo = Database::pdo();
$restore = smoke_sessions_force_enabled();
check('pré-condição: coleta de sessões ligada', CdnSession::enabled());

$clean = static function () use ($pdo, $user): void {
    $pdo->prepare('DELETE FROM cdn_sessions WHERE username = :u')->execute([':u' => $user]);
    $pdo->prepare('DELETE FROM xui_users_cache WHERE username = :u')->execute([':u' => $user]);
    $pdo->prepare('DELETE FROM cdn_user_ip_lock WHERE username = :u')->execute([':u' => $user]);
    $pdo->exec('DELETE FROM xui_streams_cache WHERE stream_id IN (990001,990002,990003)');
    $pdo->exec('DELETE FROM xui_episodes_cache WHERE stream_id = 990003');
    $pdo->exec('DELETE FROM xui_series_cache WHERE series_id = 4242');
};
$clean();

echo "\n== 1. espelho local do catálogo (o que a CDN sabe sem perguntar ao XUI)\n";
$ins = $pdo->prepare(
    'INSERT INTO xui_streams_cache (stream_id, type, stream_display_name, category_id, target_container, direct_source, direct_proxy, synced_at)
     VALUES (:id,:t,:n,\'1\',:c,0,0,:sy)'
);
$now = date('c');
$ins->execute([':id' => 990001, ':t' => '1', ':n' => 'SPORTS HD', ':c' => 'ts', ':sy' => $now]);
$ins->execute([':id' => 990002, ':t' => '2', ':n' => 'O Filme Teste (2026)', ':c' => 'mp4', ':sy' => $now]);
$ins->execute([':id' => 990003, ':t' => '5', ':n' => 'Piloto', ':c' => 'mkv', ':sy' => $now]);
$pdo->prepare('INSERT INTO xui_series_cache (series_id, title, category_id, synced_at) VALUES (4242,\'Serie Teste\',\'9\',:sy)')
    ->execute([':sy' => $now]);
$pdo->prepare('INSERT INTO xui_episodes_cache (stream_id, series_id, season_num, episode_num, synced_at) VALUES (990003,4242,1,2,:sy)')
    ->execute([':sy' => $now]);

check('type=1 é canal', StreamCatalog::resolve(990001)['content_kind'] === 'live');
check('type=2 é filme', StreamCatalog::resolve(990002)['content_kind'] === 'movie');
$ep = StreamCatalog::resolve(990003);
check('type=5 é série (não 3)', $ep['content_kind'] === 'series', $ep['content_kind']);
check('episódio mostra série + S/E', str_contains($ep['content_label'], 'Serie Teste') && str_contains($ep['content_label'], 'S01E02'), $ep['content_label']);
check('stream fora do espelho não quebra', StreamCatalog::resolve(999999)['known'] === 0);

echo "\n== 2. três conexões vivas do mesmo usuário, cada uma com o seu conteúdo\n";
$pdo->prepare(
    'INSERT INTO xui_users_cache (user_id, username, credential_fingerprint, max_connections, enabled, is_trial, is_restreamer, exp_date, synced_at)
     VALUES (91001,:u,\'fp-smoke\',4,1,0,0,\'\',:sy)'
)->execute([':u' => $user, ':sy' => $now]);

$touch = static function (string $path, string $ip, string $ua): string {
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    $key = CdnSession::touch(RequestContext::build('smoke.local', $ip, $path, []));
    CdnSession::record($key, 200, 4096);
    return $key;
};
$k1 = $touch("/live/$user/pass/990001.ts", '203.0.113.10', 'TiviMate/4.7');
$k2 = $touch("/movie/$user/pass/990002.mp4", '203.0.113.11', 'VLC/3.0');
$k3 = $touch("/series/$user/pass/990003.mkv", '203.0.113.10', 'TiviMate/4.7');

// Envelhece a primeira para provar uptime individual por conexão.
$pdo->prepare(
    'UPDATE cdn_sessions SET uptime_start_epoch = uptime_start_epoch - 3725,
            started_epoch = started_epoch - 3725 WHERE session_key = :k'
)->execute([':k' => $k1]);

Cache::flush();
$conns = UserIntelligence::connections($user, 50);
check('3 conexões separadas', count($conns) === 3, (string) count($conns));

$byStream = [];
foreach ($conns as $c) { $byStream[(int) $c['stream_id']] = $c; }
check('conexão de canal traz o nome do canal', ($byStream[990001]['content_name'] ?? '') === 'SPORTS HD');
check('conexão de filme classificada como filme', ($byStream[990002]['content_kind'] ?? '') === 'movie');
check('conexão de série traz S01E02', str_contains((string) ($byStream[990003]['content_label'] ?? ''), 'S01E02'));
check('uptime individual > 1h na conexão antiga', (int) ($byStream[990001]['uptime_seconds'] ?? 0) >= 3725);
check('uptime humano legível', str_contains((string) ($byStream[990001]['uptime_human'] ?? ''), 'h '), (string) ($byStream[990001]['uptime_human'] ?? ''));
check('conexão nova tem uptime curto', (int) ($byStream[990002]['uptime_seconds'] ?? 999) < 60);
check('resolução vem do catálogo, não do XUI', ($byStream[990001]['content_source'] ?? '') === 'catalog');

echo "\n== 3. resumo do usuário fecha com o plano\n";
$d = UserIntelligence::detail($user);
$s = $d['summary'];
check('em uso = 3', (int) $s['in_use'] === 3, (string) $s['in_use']);
check('limite = 4', (int) $s['limit'] === 4, (string) $s['limit']);
check('livres = 1', (int) $s['free'] === 1, (string) $s['free']);
check('IPs distintos = 2', (int) $s['distinct_ips'] === 2, (string) $s['distinct_ips']);
check('por tipo: 1 canal, 1 filme, 1 série',
    (int) $s['by_kind']['live'] === 1 && (int) $s['by_kind']['movie'] === 1 && (int) $s['by_kind']['series'] === 1);
check('não marcado acima do limite', (int) $s['over_limit'] === 0);

echo "\n== 4. totais do painel são vivos (sem depender de rollup atrasado)\n";
$pdo->exec('DELETE FROM proxy_metrics');
Cache::flush();
$t = UserIntelligence::totalsFresh();
check('fonte é cdn_sessions ao vivo', ($t['source'] ?? '') === 'cdn_sessions_live');
check('conta as 3 conexões mesmo com métricas zeradas', (int) $t['connections_video'] >= 3, (string) $t['connections_video']);
check('usuário aparece online', (int) $t['users_online'] >= 1);

echo "\n== 5. IP personalizado por usuário continua individual\n";
UserIpLock::save($user, '203.0.113.10, 198.51.100.0/24', 'smoke');
Cache::flush();
$d2 = UserIntelligence::detail($user);
check('detalhe expõe a trava do usuário', str_contains((string) ($d2['ip_lock']['allowed_ips'] ?? ''), '203.0.113.10'));
check('IP travado permitido', UserIpLock::allows($user, '203.0.113.10'));
check('faixa CIDR permitida', UserIpLock::allows($user, '198.51.100.77'));
check('IP fora da regra bloqueado', !UserIpLock::allows($user, '45.33.1.2'));
check('outro usuário não herda a trava', UserIpLock::allows('smoke_tel_outro', '45.33.1.2'));

$clean();
$pdo->prepare('DELETE FROM cdn_user_ip_lock WHERE username = :u')->execute([':u' => $user]);
$restore();
echo "\n== telemetria por usuário: $ok ok / $fail falhas\n";
exit($fail > 0 ? 1 : 0);
