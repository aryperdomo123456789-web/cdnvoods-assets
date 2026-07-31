<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /xui.php');
    exit;
}

try {
    $created = XuiAdmin::createLine([
        'username' => $_POST['username'] ?? '',
        'password' => $_POST['password'] ?? '',
        'max_connections' => $_POST['max_connections'] ?? 1,
        'exp_date' => $_POST['exp_date'] ?? '',
        'bouquets' => $_POST['bouquets'] ?? [],
        'allowed_outputs' => $_POST['allowed_outputs'] ?? [],
        'enabled' => $_POST['enabled'] ?? 0,
        'admin_enabled' => $_POST['admin_enabled'] ?? 0,
        'is_trial' => $_POST['is_trial'] ?? 0,
        'is_restreamer' => $_POST['is_restreamer'] ?? 0,
        'allowed_ips' => $_POST['allowed_ips'] ?? '',
        'allowed_ua' => $_POST['allowed_ua'] ?? '',
        'admin_notes' => $_POST['admin_notes'] ?? '',
        'member_id' => $_POST['member_id'] ?? 0,
        'force_server_id' => $_POST['force_server_id'] ?? 0,
    ]);
    $stats = ['processed' => 0, 'details' => []];
    XuiSyncService::syncUsers($stats);
    $_SESSION['flash'] = sprintf(
        'Usuário %s criado no XUI com %d conexão(ões).',
        $created['username'],
        $created['max_connections']
    );
} catch (Throwable $e) {
    $_SESSION['flash'] = 'Falha ao criar usuário no XUI: ' . substr($e->getMessage(), 0, 220);
}

header('Location: /xui.php');
exit;
