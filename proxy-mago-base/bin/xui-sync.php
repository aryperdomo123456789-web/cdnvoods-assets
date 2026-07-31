#!/usr/bin/env php
<?php

/**
 * Sync manual read-only do XUI — VPS 45.140.192.237 / /opt/proxy-mago/proxy-mago-base
 *
 *   php bin/xui-sync.php --test        # só testa a conexão read-only
 *   php bin/xui-sync.php --all         # users + streams + activity agora
 *   php bin/xui-sync.php --activity    # só sessões ativas
 */

require_once dirname(__DIR__) . '/app/bootstrap-cli.php';

$opts = getopt('', ['test', 'all', 'users', 'streams', 'activity']);

if (!XuiReadOnly::available()) {
    fwrite(STDERR, "pdo_mysql ausente. Na VPS rode:\n  apt-get install -y php8.1-mysql && phpenmod pdo_mysql && systemctl reload php8.1-fpm\n");
    exit(2);
}

$cfg = XuiSyncConfig::get();
printf("XUI: %s:%d db=%s user=%s enabled=%d\n", $cfg['host'], (int) $cfg['port'], $cfg['database_name'], $cfg['username'], (int) $cfg['sync_enabled']);

$ping = XuiReadOnly::ping();
printf("ping: %s (%dms) %s\n", $ping['ok'] ? 'OK' : 'FALHOU', $ping['ms'], $ping['error']);
if (!$ping['ok']) {
    XuiSyncConfig::markSync('error', $ping['error']);
    exit(1);
}
if (isset($opts['test'])) {
    $checks = [
        'users|lines' => XuiReadOnly::hasTable('users') || XuiReadOnly::hasTable('lines'),
        'streams' => XuiReadOnly::hasTable('streams'),
        'user_activity_now|lines_live' => XuiReadOnly::hasTable('user_activity_now') || XuiReadOnly::hasTable('lines_live'),
    ];
    foreach ($checks as $label => $ok) {
        printf("tabela %-24s %s\n", $label, $ok ? 'ok' : 'AUSENTE');
    }
    exit(0);
}

$jobs = [];
if (isset($opts['all']) || $opts === []) { $jobs = ['xui_sync_users', 'xui_sync_streams', 'xui_sync_activity']; }
if (isset($opts['users'])) { $jobs[] = 'xui_sync_users'; }
if (isset($opts['streams'])) { $jobs[] = 'xui_sync_streams'; }
if (isset($opts['activity'])) { $jobs[] = 'xui_sync_activity'; }

$exit = 0;
foreach (array_unique($jobs) as $job) {
    $fn = match ($job) {
        'xui_sync_users' => fn (array &$s) => XuiSyncService::syncUsers($s),
        'xui_sync_streams' => fn (array &$s) => XuiSyncService::syncStreams($s),
        default => fn (array &$s) => XuiSyncService::syncActivity($s),
    };
    $r = JobRunner::run($job, 'manual', $fn);
    printf("[%s] %-18s processed=%d failed=%d %dms %s\n", $r['status'], $job, $r['processed'], $r['failed'], $r['duration_ms'], $r['error']);
    if ($r['status'] === 'error') { $exit = 1; XuiSyncConfig::markSync('error', $r['error']); }
}
if ($exit === 0) { XuiSyncConfig::markSync('ok'); }
exit($exit);
