<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /usuario.php');
    exit;
}

csrf_verify();

$adminUser = trim((string) ($_POST['admin_user'] ?? ''));
$adminPass = (string) ($_POST['admin_pass'] ?? '');
$verifyCode = trim((string) ($_POST['verify_2fa_code'] ?? ''));
$enable2fa = isset($_POST['admin_2fa_enabled']) ? 1 : 0;
$regen2fa = isset($_POST['regenerate_2fa_secret']) ? 1 : 0;
$secret = (string) SettingsRepository::get('admin_2fa_secret', '');

if ($adminUser === '') {
    http_response_code(422);
    exit('Usuário inválido.');
}

if ($adminPass !== '') {
    SettingsRepository::set('admin_password_hash', password_hash($adminPass, PASSWORD_DEFAULT));
}
SettingsRepository::set('admin_user', $adminUser);

if ($regen2fa || $secret === '') {
    $secret = TotpAuth::randomSecret();
}

if ($enable2fa === 1) {
    if ($verifyCode === '') {
        http_response_code(422);
        exit('Digite o código do autenticador para ativar o 2FA.');
    }
    if (!TotpAuth::verify($secret, $verifyCode, 1)) {
        http_response_code(422);
        exit('Código 2FA inválido.');
    }
    $_SESSION['flash'] = 'Código 2FA validado com sucesso.';
}

SettingsRepository::set('admin_2fa_secret', $secret);
if ($enable2fa === 1 && $secret !== '') {
    SettingsRepository::set('admin_2fa_enabled', 1);
} elseif ($enable2fa === 0) {
    SettingsRepository::set('admin_2fa_enabled', 0);
}

Audit::log('user_update', 'Admin user/2FA updated', $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');
if (!isset($_SESSION['flash'])) {
    $_SESSION['flash'] = 'Conta e 2FA atualizadas.';
}
header('Location: /usuario.php');
exit;
