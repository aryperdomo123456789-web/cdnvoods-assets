<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

$action = (string) ($_POST['action'] ?? '');
$lbId = (int) ($_POST['lb_id'] ?? 0);

try {
    switch ($action) {
        case 'test':
            $r = LbInstaller::testConnection($lbId);
            $_SESSION['flash'] = ($r['ok'] ? 'Conexão OK: ' : 'Falha na conexão: ') . substr((string) $r['message'], 0, 400);
            break;

        case 'keygen':
            $k = LbKeyring::ensure(((string) ($_POST['value'] ?? '')) === 'rotate');
            $_SESSION['flash'] = $k['ok']
                ? 'Chave Ed25519 do cérebro pronta: ' . (string) $k['fingerprint']
                : 'Falha no chaveiro: ' . (string) $k['message'];
            break;

        case 'promote_key':
            $runId = 'lbkey-' . bin2hex(random_bytes(6));
            $r = LbInstaller::promoteKey($lbId, $runId);
            LbNode::update($lbId, ['install_run_id' => $runId]);
            $_SESSION['flash'] = (string) $r['message'];
            break;

        case 'forget_password':
            $node = LbNode::find($lbId);
            if (!$node || (int) ($node['key_installed'] ?? 0) !== 1) {
                $_SESSION['flash'] = 'Descarte bloqueado: a chave ainda não foi confirmada neste LB.';
                break;
            }
            LbNode::forgetPassword($lbId);
            $_SESSION['flash'] = 'Senha root descartada. Este LB passa a responder somente por chave.';
            break;

        case 'install':
            if (!LbSsh::available()) {
                $_SESSION['flash'] = LbSsh::missingHint();
                break;
            }
            LbNode::update($lbId, ['install_status' => 'queued', 'install_step' => 'validate']);
            $_SESSION['flash'] = LbInstaller::spawn($lbId, 'install')
                ? 'Instalação disparada. Acompanhe o log ao vivo abaixo.'
                : 'Não foi possível disparar a instalação em background.';
            break;

        case 'sync':
            $_SESSION['flash'] = LbInstaller::spawn($lbId, 'sync')
                ? 'Sincronização disparada (pacote mínimo + configuração).'
                : 'Não foi possível disparar o sync.';
            break;

        case 'enable':
            LbNode::setEnabled($lbId, true);
            $_SESSION['flash'] = 'LB ativado.';
            break;

        case 'disable':
            LbNode::setEnabled($lbId, false);
            $_SESSION['flash'] = 'LB desativado (não recebe mais usuários novos).';
            break;

        case 'drain':
            LbNode::setDrain($lbId, ((string) ($_POST['value'] ?? '1')) === '1');
            $_SESSION['flash'] = 'Modo drenagem atualizado.';
            break;

        case 'delete':
            LbNode::delete($lbId);
            $_SESSION['flash'] = 'LB removido do inventário.';
            break;

        default:
            $_SESSION['flash'] = 'Ação de LB desconhecida.';
    }
} catch (Throwable $e) {
    error_log('[lb-action] ' . $e->getMessage());
    $_SESSION['flash'] = 'Erro na ação do LB: ' . $e->getMessage();
}

header('Location: /lb.php');
exit;