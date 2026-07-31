<?php

/**
 * Executor da instalação/sync remota do LB.
 *
 * O painel dispara este processo em background (nohup) para não travar o
 * PHP-FPM: instalação real leva minutos. O log ao vivo sai por lb_installs.
 *
 * uso: php bin/lb-install-run.php --lb=1 [--action=install|sync]
 */

require_once dirname(__DIR__) . '/app/bootstrap-cli.php';

$opts = getopt('', ['lb:', 'action::']);
$lbId = (int) ($opts['lb'] ?? 0);
$action = (string) ($opts['action'] ?? 'install');

if ($lbId <= 0) {
    fwrite(STDERR, "uso: php bin/lb-install-run.php --lb=<id> [--action=install|sync]\n");
    exit(2);
}

try {
    $r = $action === 'sync' ? LbInstaller::sync($lbId) : LbInstaller::install($lbId);
    printf("[%s] lb=%d %s\n", $r['ok'] ? 'ok' : 'error', $lbId, $r['message']);
    exit($r['ok'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[fatal] ' . LbSsh::redact($e->getMessage()) . "\n");
    exit(1);
}