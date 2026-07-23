<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard.php');
    exit;
}

csrf_verify();

$adminUser = trim((string) ($_POST['admin_user'] ?? ''));
$adminPass = (string) ($_POST['admin_pass'] ?? '');
$panelDomain = trim((string) ($_POST['panel_domain'] ?? ''));
$originHost = trim((string) ($_POST['origin_host'] ?? ''));
$originPort = (int) ($_POST['origin_port'] ?? 80);
$allowedUserAgent = trim((string) ($_POST['allowed_user_agent'] ?? ''));
$tokenTtl = (int) ($_POST['token_ttl'] ?? 3600);
$appSecret = trim((string) ($_POST['app_secret'] ?? ''));

if ($adminUser === '' || $originHost === '' || $originPort < 1) {
    http_response_code(422);
    exit('Dados inválidos.');
}

SettingsRepository::set('admin_user', $adminUser);
if ($adminPass !== '') {
    SettingsRepository::set('admin_password_hash', password_hash($adminPass, PASSWORD_DEFAULT));
}

SettingsRepository::set('panel_domain', $panelDomain);
SettingsRepository::set('origin_host', $originHost);
SettingsRepository::set('origin_port', $originPort);
SettingsRepository::set('allowed_user_agent', $allowedUserAgent !== '' ? $allowedUserAgent : Config::get('allowed_user_agent'));
SettingsRepository::set('token_ttl', max(60, $tokenTtl));

if ($appSecret === '') {
    $appSecret = bin2hex(random_bytes(32));
}
SettingsRepository::set('app_secret', $appSecret);

Audit::log('settings_update', 'Configuration updated', $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');

header('Location: /dashboard.php');
exit;
