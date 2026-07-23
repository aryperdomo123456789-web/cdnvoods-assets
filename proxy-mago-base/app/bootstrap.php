<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

require_once dirname(__DIR__) . '/app/Config.php';
require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/SettingsRepository.php';
require_once dirname(__DIR__) . '/app/Audit.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/NginxGenerator.php';
require_once dirname(__DIR__) . '/app/OriginRepository.php';
require_once dirname(__DIR__) . '/app/AliasRepository.php';
require_once dirname(__DIR__) . '/app/Tokens.php';
require_once dirname(__DIR__) . '/app/AccessGuard.php';
require_once dirname(__DIR__) . '/app/PlaylistRewriter.php';
require_once dirname(__DIR__) . '/app/StreamProxy.php';
require_once dirname(__DIR__) . '/app/HealthCheck.php';

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

function require_seeded_or_setup(): void
{
    if (!SettingsRepository::seeded() && basename($_SERVER['SCRIPT_NAME']) !== 'setup.php') {
        header('Location: /setup.php');
        exit;
    }
}
