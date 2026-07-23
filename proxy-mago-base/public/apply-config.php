<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard.php');
    exit;
}

csrf_verify();
$pending = dirname(__DIR__) . '/storage/nginx.pending.conf';
$config = NginxGenerator::render(SettingsRepository::all());
if (file_put_contents($pending, $config, LOCK_EX) === false) {
    $_SESSION['nginx_apply_result'] = ['ok' => false, 'message' => 'Não foi possível preparar a configuração.'];
    header('Location: /dashboard.php#nginx');
    exit;
}
chmod($pending, 0640);
exec('sudo -n /usr/bin/php /opt/proxy-mago/proxy-mago-base/bin/apply-nginx.php 2>&1', $output, $code);
$message = implode("\n", $output);
$_SESSION['nginx_apply_result'] = ['ok' => $code === 0, 'message' => $message];
Audit::log($code === 0 ? 'nginx_apply' : 'nginx_apply_failed', $message, $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');
header('Location: /dashboard.php#nginx');
exit;
