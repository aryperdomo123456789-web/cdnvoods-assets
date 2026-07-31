<?php
/**
 * LABORATÓRIO REAL DE TRÁFEGO.
 *
 * Configura a origem XUI real + domínio público de proteção, dispara requests
 * REAIS pela CDN local (127.0.0.1:8080 com Host do domínio público) e mostra
 * o contador inteligente por usuário reagindo ao vivo.
 *
 *   php bin/lab/real-traffic-lab.php <origem-xui> <dominio-publico> <user> <pass> [base]
 */
require __DIR__ . '/../../app/bootstrap-cli.php';

$origin = $argv[1] ?? '38.190.176.170';
$public = $argv[2] ?? 'voods.suafontee.com';
$user   = $argv[3] ?? '';
$pass   = $argv[4] ?? '';
$base   = $argv[5] ?? 'http://127.0.0.1:8080';

function line(string $t): void { echo "\n== $t\n"; }

line('1. origem XUI real e domínio público');
$id = XuiOrigin::save('a', $origin, 80);
if (!AliasRepository::findByHostname($public)) {
    AliasRepository::create(['hostname' => $public, 'origin_id' => $id, 'active' => 1, 'is_primary' => 1]);
}
printf("   origem #%d -> %s | público: %s\n", $id, $origin, $public);

function hit(string $base, string $public, string $path, string $ua): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: ' . $public],
        CURLOPT_USERAGENT => $ua,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_WRITEFUNCTION => function ($c, $d) { return strlen($d); }, // descarta corpo
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $bytes = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    return [$code, $bytes];
}

$q = 'username=' . rawurlencode($user) . '&password=' . rawurlencode($pass);

line('2. request de PLAYLIST (não pode ocupar slot do plano)');
[$c, $b] = hit($base, $public, '/get.php?' . $q . '&type=m3u_plus&output=hls', 'LabCurl/1.0');
printf("   HTTP %d, %s bytes\n", $c, number_format($b));
$t = UserIntelligence::totals();
printf("   conexões de vídeo=%d | playlist/api=%d | usuários online=%d\n",
    $t['connections_video'], $t['sessions_fetch'], $t['users_online']);

line('3. request de API (player_api) — mesmo player, mesma sessão de fetch');
[$c, $b] = hit($base, $public, '/player_api.php?' . $q, 'LabCurl/1.0');
printf("   HTTP %d, %s bytes\n", $c, number_format($b));

line('4. 3 requests de VÍDEO em streams diferentes (deve virar 3 conexões)');
$stream = Database::pdo()->query('SELECT stream_id FROM xui_streams_cache LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
if (!$stream) { $stream = [1, 2, 3]; }
foreach ($stream as $sid) {
    [$c, $b] = hit($base, $public, '/live/' . rawurlencode($user) . '/' . rawurlencode($pass) . '/' . (int) $sid . '.ts', 'TiviMate/4.7.0');
    printf("   stream %-8s HTTP %d, %s bytes\n", $sid, $c, number_format($b));
}

line('5. mesmo stream, 5 requests (HLS burst) — tem que continuar 1 conexão');
$sid = (int) ($stream[0] ?? 1);
for ($i = 0; $i < 5; $i++) {
    hit($base, $public, '/live/' . rawurlencode($user) . '/' . rawurlencode($pass) . '/' . $sid . '.ts', 'TiviMate/4.7.0');
}

line('6. contador inteligente depois do tráfego real');
$d = UserIntelligence::detail($user);
$u = $d['user'];
if ($u) {
    printf("   %s: em uso=%d de %s (livres %s) | cdn=%d xui=%d fonte=%s status=%s\n",
        $u['username'], $u['connections_used'], $u['max_connections'], $u['connections_free'],
        $u['cdn_connections_now'], $u['xui_connections_now'], $u['count_source'], $u['status']);
    printf("   playlist/api abertas: %d | direct source: %d\n", $u['fetch_sessions_now'], $u['direct_sessions_now']);
} else {
    echo "   usuário não encontrado no espelho do XUI\n";
}
foreach ($d['connections'] as $s) {
    printf("   sessão %-9s stream=%-8s reqs=%-3d bytes=%-12s direct=%s\n",
        $s['session_kind'], $s['stream_id'] ?: '-', $s['requests'], number_format((int) $s['bytes']),
        $s['direct_source'] ? ($s['direct_host'] ?: 'sim') : '-');
}

line('7. totais do parque');
foreach (UserIntelligence::totals() as $k => $v) { printf("   %-20s %s\n", $k, $v); }
echo "\nlab de tráfego concluído.\n";
