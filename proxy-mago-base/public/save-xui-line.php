<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

$id = max(0, (int) ($_POST['id'] ?? 0));
$action = trim((string) ($_POST['action'] ?? ''));

if ($id <= 0) {
    $_SESSION['flash'] = 'Linha inválida.';
    header('Location: /xui.php');
    exit;
}

try {
    // Trava de IP: regra inválida nunca é engolida em silêncio, o painel avisa.
    $ipLockFlash = static function (array $result): string {
        $msg = sprintf(' Trava CDN por IP: %d regra(s) ativa(s).', count($result['valid']));
        if ($result['invalid'] !== []) {
            $msg .= ' Recusadas (formato inválido): ' . implode(', ', array_slice($result['invalid'], 0, 5)) . '.';
        }
        return $msg;
    };

    if ($action === 'update') {
        $updated = XuiAdmin::updateLine($id, $_POST);
        $ipResult = UserIpLock::save(
            (string) ($_POST['username'] ?? $updated['username']),
            (string) ($_POST['cdn_allowed_ips'] ?? ''),
            (string) ($_POST['cdn_ip_notes'] ?? '')
        );
        $stats = ['processed' => 0, 'details' => []];
        XuiSyncService::syncUsers($stats);
        $_SESSION['flash'] = 'Linha ' . $updated['username'] . ' atualizada no XUI.' . $ipLockFlash($ipResult);
        header('Location: /xui-user.php?id=' . $id);
        exit;
    }
    if ($action === 'toggle') {
        XuiAdmin::setLineEnabled($id, !empty($_POST['enabled']));
        $stats = ['processed' => 0, 'details' => []];
        XuiSyncService::syncUsers($stats);
        $_SESSION['flash'] = 'Status da linha atualizado no XUI.';
        header('Location: /xui-user.php?id=' . $id);
        exit;
    }
    if ($action === 'ip_lock') {
        $line = XuiAdmin::findLine($id);
        $ipResult = UserIpLock::save(
            (string) $line['username'],
            (string) ($_POST['cdn_allowed_ips'] ?? ''),
            (string) ($_POST['cdn_ip_notes'] ?? '')
        );
        $_SESSION['flash'] = 'Trava CDN por IP atualizada para o usuário ' . $line['username'] . '.'
            . $ipLockFlash($ipResult);
        header('Location: /xui-user.php?id=' . $id);
        exit;
    }
    if ($action === 'delete') {
        XuiAdmin::deleteLine($id);
        $stats = ['processed' => 0, 'details' => []];
        XuiSyncService::syncUsers($stats);
        $_SESSION['flash'] = 'Linha removida do XUI.';
        header('Location: /xui.php');
        exit;
    }
    throw new RuntimeException('ação inválida');
} catch (Throwable $e) {
    $_SESSION['flash'] = 'Falha ao operar a linha no XUI: ' . substr($e->getMessage(), 0, 220);
    header('Location: /xui-user.php?id=' . $id);
    exit;
}
