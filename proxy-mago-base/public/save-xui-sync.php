<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

$current = XuiSyncConfig::get();

XuiSyncConfig::save([
    'host' => $_POST['host'] ?? '',
    'port' => $_POST['port'] ?? 3306,
    'database_name' => $_POST['database_name'] ?? 'xtream_iptvpro',
    'username' => $_POST['username'] ?? '',
    'password' => $_POST['password'] ?? '',
    'api_url' => $_POST['api_url'] ?? '',
    'api_token' => $_POST['api_token'] ?? '',
    'use_tls' => $_POST['use_tls'] ?? 0,
    'sync_enabled' => $_POST['sync_enabled'] ?? 0,
    'sync_interval_seconds' => $_POST['sync_interval_seconds'] ?? ($current['sync_interval_seconds'] ?? 5),
    'users_interval_seconds' => $_POST['users_interval_seconds'] ?? ($current['users_interval_seconds'] ?? 60),
    'streams_interval_seconds' => $_POST['streams_interval_seconds'] ?? ($current['streams_interval_seconds'] ?? 300),
    'connect_timeout_seconds' => $_POST['connect_timeout_seconds'] ?? ($current['connect_timeout_seconds'] ?? 3),
    'read_timeout_seconds' => $_POST['read_timeout_seconds'] ?? ($current['read_timeout_seconds'] ?? 5),
]);

// Nunca registra credencial em log; só host/porta/base.
Audit::log('xui_sync_config_saved', sprintf(
    'host=%s port=%s db=%s enabled=%s api_url=%s api_token=%s',
    (string) ($_POST['host'] ?? ''),
    (string) ($_POST['port'] ?? ''),
    (string) ($_POST['database_name'] ?? ''),
    empty($_POST['sync_enabled']) ? '0' : '1',
    (string) ($_POST['api_url'] ?? ''),
    empty($_POST['api_token']) ? '0' : '1'
));

$_SESSION['flash'] = 'Configuração do XUI salva.';
header('Location: /xui.php');
exit;
