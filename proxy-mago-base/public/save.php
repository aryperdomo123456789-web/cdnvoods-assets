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
$originHost = trim((string) ($_POST['origin_host'] ?? '127.0.0.1'));
$originPort = (int) ($_POST['origin_port'] ?? 80);
$allowedUserAgent = trim((string) ($_POST['allowed_user_agent'] ?? ''));
$tokenTtl = (int) ($_POST['token_ttl'] ?? Config::get('token_ttl'));
$rateLimit = (int) ($_POST['rate_limit_per_minute'] ?? Config::get('rate_limit_per_minute'));
$appSecret = trim((string) ($_POST['app_secret'] ?? ''));

if ($adminUser === '') {
    http_response_code(422);
    exit('Dados inválidos.');
}

if ($panelDomain !== '' && !filter_var($panelDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
    http_response_code(422);
    exit('Domínio do painel inválido.');
}

if ($appSecret !== '' && !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $appSecret)) {
    http_response_code(422);
    exit('O segredo deve ter entre 32 e 128 caracteres (letras, números, _ ou -).');
}

SettingsRepository::set('admin_user', $adminUser);
if ($adminPass !== '') {
    SettingsRepository::set('admin_password_hash', password_hash($adminPass, PASSWORD_DEFAULT));
}

SettingsRepository::set('panel_domain', $panelDomain);
SettingsRepository::set('origin_host', $originHost);
SettingsRepository::set('origin_port', $originPort);
SettingsRepository::set('allowed_user_agent', $allowedUserAgent);
SettingsRepository::set('token_ttl', max(60, $tokenTtl));
SettingsRepository::set('rate_limit_per_minute', max(0, $rateLimit));

if ($appSecret === '') {
    $appSecret = bin2hex(random_bytes(32));
}
SettingsRepository::set('app_secret', $appSecret);

Audit::log('settings_update', 'Configuration updated', $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');

header('Location: /dashboard.php');
exit;
