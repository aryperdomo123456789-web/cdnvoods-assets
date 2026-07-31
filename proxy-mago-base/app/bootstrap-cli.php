<?php

declare(strict_types=1);

/**
 * Bootstrap para comandos de linha (jobs, sync, smoke tests).
 * Não abre sessão HTTP e não depende de $_SERVER de request.
 */

date_default_timezone_set('America/Sao_Paulo');

$root = dirname(__DIR__);

foreach ([
    'Config', 'Cache', 'Freshness', 'Database', 'DbLockDiag', 'SettingsRepository', 'Audit', 'NginxGenerator',
    'OriginRepository', 'AliasRepository', 'XuiOrigin', 'Tokens', 'AccessGuard',
    'RequestContext', 'RequestLog', 'CredentialGuard', 'CdnSession', 'AuditTimeline',
    'DirectSourceParser', 'DirectCatalog', 'DirectSource', 'DirectHostHealth', 'XuiSeriesCompat',
    'Divergence', 'PlaylistRewriter',
    'StreamProxy', 'HealthCheck', 'JobRunner', 'XuiSyncConfig', 'XuiReadOnly', 'XuiAdmin', 'UserIpLock',
    'XuiSyncService', 'RestreamRuntime', 'UserIntelligence',
    'LbCrypto', 'LbKeyring', 'LbSsh', 'LbNode', 'LbPackageBuilder', 'LbInstaller', 'LbTelemetry', 'LbRouter',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

if (!is_dir($root . '/storage/logs')) {
    mkdir($root . '/storage/logs', 0775, true);
}
ini_set('error_log', $root . '/storage/logs/php-error.log');

Database::pdo();
