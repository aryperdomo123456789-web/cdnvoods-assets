<?php
/**
 * LABORATÓRIO REAL — contador inteligente de conexões por usuário.
 *
 * Conecta no MySQL REAL do XUI, espelha usuários + sessões ativas, simula
 * consumo pela CDN (sessões locais) e imprime a tabela de inteligência
 * exatamente como o painel vai mostrar.
 *
 *   php bin/lab/real-users-lab.php <host> <db> <user> <pass> [porta] [username-teste]
 */
require __DIR__ . '/../../app/bootstrap-cli.php';

$host = $argv[1] ?? '';
$db   = $argv[2] ?? 'xui';
$user = $argv[3] ?? '';
$pass = $argv[4] ?? '';
$port = (int) ($argv[5] ?? 3306);
$probe = $argv[6] ?? '';

if ($host === '') { fwrite(STDERR, "uso: php bin/lab/real-users-lab.php <host> <db> <user> <pass> [porta] [username]\n"); exit(2); }

function line(string $t): void { echo "\n== $t\n"; }

XuiSyncConfig::save([
    'host' => $host, 'port' => $port, 'database_name' => $db,
    'username' => $user, 'password' => $pass, 'sync_enabled' => 1,
]);

line('1. ping no XUI real');
$ping = XuiReadOnly::ping();
printf("   ok=%s ms=%s %s\n", $ping['ok'] ? 'sim' : 'NAO', $ping['ms'], $ping['error'] ?? '');
if (!$ping['ok']) { exit(1); }

line('2. espelhando usuários (read-only)');
$stats = ['processed' => 0, 'failed' => 0, 'details' => []];
XuiSyncService::syncUsers($stats);
printf("   usuários espelhados: %d (tabela: %s)\n", $stats['processed'], $stats['details']['users_table'] ?? '?');

line('3. espelhando sessões ativas (user_activity_now)');
$s2 = ['processed' => 0, 'failed' => 0, 'details' => []];
try { XuiSyncService::syncActivity($s2); printf("   sessões ativas no XUI: %d\n", $s2['processed']); }
catch (Throwable $e) { printf("   AVISO: %s\n", $e->getMessage()); }

line('4. totais do parque');
foreach (UserIntelligence::totals() as $k => $v) { printf("   %-20s %s\n", $k, is_scalar($v) ? $v : json_encode($v)); }

line('5. top 15 usuários por conexão em uso');
printf("   %-28s %5s %5s %5s %6s %6s %-12s %s\n", 'usuário', 'uso', 'max', 'livre', 'cdn', 'xui', 'status', 'visto');
foreach (UserIntelligence::users([], 15) as $u) {
    printf("   %-28s %5d %5s %5s %6d %6d %-12s %s\n",
        substr((string) $u['username'], 0, 28),
        $u['connections_used'],
        (int) $u['max_connections'] ?: '-',
        $u['connections_free'] === null ? '-' : $u['connections_free'],
        $u['cdn_connections_now'], $u['xui_connections_now'],
        $u['status'],
        $u['last_epoch'] ? date('H:i:s', (int) $u['last_epoch']) : '-'
    );
}

if ($probe !== '') {
    line("6. detalhe do usuário real: $probe");
    $d = UserIntelligence::detail($probe);
    echo '   plano: ' . json_encode($d['user'] ? array_intersect_key($d['user'], array_flip([
        'user_id','max_connections','enabled','exp_date','connections_used','connections_free','status','count_source',
    ])) : null) . "\n";
    foreach ($d['connections'] as $c) {
        printf("   sessão %-10s stream=%-8s ip=%-15s reqs=%-4d bytes=%-10d direct=%s\n",
            $c['session_kind'], $c['stream_id'] ?: '-', $c['client_ip'], $c['requests'], $c['bytes'],
            $c['direct_source'] ? ($c['direct_host'] ?: 'sim') : '-');
    }
}
echo "\nlab concluído.\n";
