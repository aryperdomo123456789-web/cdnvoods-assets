<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

try {
    $id = LbNode::save([
        'id' => (int) ($_POST['id'] ?? 0),
        'label' => (string) ($_POST['label'] ?? ''),
        'public_ip' => (string) ($_POST['public_ip'] ?? ''),
        'ssh_host' => (string) ($_POST['ssh_host'] ?? ''),
        'ssh_port' => (int) ($_POST['ssh_port'] ?? 22),
        'ssh_user' => (string) ($_POST['ssh_user'] ?? 'root'),
        'ssh_password' => (string) ($_POST['ssh_password'] ?? ''),
        'declared_bandwidth_mbps' => (int) ($_POST['declared_bandwidth_mbps'] ?? 10000),
        'weight' => (int) ($_POST['weight'] ?? 100),
        'enabled' => isset($_POST['enabled']),
        'drain_mode' => isset($_POST['drain_mode']),
        'max_users_soft' => (int) ($_POST['max_users_soft'] ?? 0),
        'max_users_hard' => (int) ($_POST['max_users_hard'] ?? 0),
        'max_mbps_soft' => (int) ($_POST['max_mbps_soft'] ?? 0),
        'max_mbps_hard' => (int) ($_POST['max_mbps_hard'] ?? 0),
        'auto_install' => isset($_POST['auto_install']),
    ]);

    if (isset($_POST['auto_install'])) {
        LbNode::update($id, ['install_status' => 'queued', 'install_step' => 'validate']);
        $_SESSION['flash'] = LbInstaller::spawn($id, 'install')
            ? 'LB #' . $id . ' salvo e instalação automática disparada. Acompanhe o log ao vivo.'
            : 'LB #' . $id . ' salvo, mas não foi possível disparar a instalação automática. Use o botão "Instalar".';
    } else {
        $_SESSION['flash'] = 'LB #' . $id . ' salvo. Use "Testar" antes de instalar.';
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = 'Falha ao salvar LB: ' . $e->getMessage();
}

header('Location: /lb.php?lb=' . (int) ($id ?? 0));
exit;