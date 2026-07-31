<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/Cache.php';
require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/SettingsRepository.php';
require_once dirname(__DIR__) . '/app/Audit.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/NginxGenerator.php';
require_once dirname(__DIR__) . '/app/OriginRepository.php';
require_once dirname(__DIR__) . '/app/AliasRepository.php';
require_once dirname(__DIR__) . '/app/XuiOrigin.php';

require_once dirname(__DIR__) . '/app/Tokens.php';
require_once dirname(__DIR__) . '/app/AccessGuard.php';
require_once dirname(__DIR__) . '/app/RequestContext.php';
require_once dirname(__DIR__) . '/app/RequestLog.php';
require_once dirname(__DIR__) . '/app/CredentialGuard.php';
require_once dirname(__DIR__) . '/app/CdnSession.php';
require_once dirname(__DIR__) . '/app/AuditTimeline.php';
require_once dirname(__DIR__) . '/app/DirectSourceParser.php';
require_once dirname(__DIR__) . '/app/DirectCatalog.php';
require_once dirname(__DIR__) . '/app/DirectSource.php';
require_once dirname(__DIR__) . '/app/Divergence.php';
require_once dirname(__DIR__) . '/app/PlaylistRewriter.php';
require_once dirname(__DIR__) . '/app/StreamProxy.php';
require_once dirname(__DIR__) . '/app/HealthCheck.php';
require_once dirname(__DIR__) . '/app/JobRunner.php';
require_once dirname(__DIR__) . '/app/XuiSyncConfig.php';
require_once dirname(__DIR__) . '/app/XuiReadOnly.php';
require_once dirname(__DIR__) . '/app/XuiAdmin.php';
require_once dirname(__DIR__) . '/app/UserIpLock.php';
require_once dirname(__DIR__) . '/app/XuiSyncService.php';
require_once dirname(__DIR__) . '/app/RestreamRuntime.php';
require_once dirname(__DIR__) . '/app/UserIntelligence.php';
require_once dirname(__DIR__) . '/app/LbCrypto.php';
require_once dirname(__DIR__) . '/app/LbKeyring.php';
require_once dirname(__DIR__) . '/app/LbSsh.php';
require_once dirname(__DIR__) . '/app/LbNode.php';
require_once dirname(__DIR__) . '/app/LbPackageBuilder.php';
require_once dirname(__DIR__) . '/app/LbInstaller.php';
require_once dirname(__DIR__) . '/app/LbTelemetry.php';
require_once dirname(__DIR__) . '/app/LbRouter.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');

if (!is_dir(dirname(__DIR__) . '/storage/logs')) {
    mkdir(dirname(__DIR__) . '/storage/logs', 0775, true);
}

if (!is_dir(dirname(__DIR__) . '/storage/cache')) {
    mkdir(dirname(__DIR__) . '/storage/cache', 0775, true);
}

Auth::startSession();
Database::pdo();

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

function csrf_verify(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';

    if ($posted === '' || $session === '' || !hash_equals($session, $posted)) {
        http_response_code(419);
        exit('CSRF validation failed.');
    }
}

// Alias retrocompatível para save-origin.php e código antigo que ainda usa verify_csrf($token).
function verify_csrf(?string $token = null): void
{
    if ($token !== null && $token !== '') {
        $session = $_SESSION['csrf_token'] ?? '';
        if ($session === '' || !hash_equals($session, $token)) {
            http_response_code(419);
            exit('CSRF validation failed.');
        }
        return;
    }
    csrf_verify();
}

function require_seeded_or_setup(): void
{
    if (!SettingsRepository::seeded() && basename($_SERVER['SCRIPT_NAME']) !== 'setup.php') {
        header('Location: /setup.php');
        exit;
    }
}
