<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

try {
    $usernames = preg_split('/[\s,;]+/', trim((string) ($_POST['usernames'] ?? ''))) ?: [];
    $usernames = array_values(array_filter(array_map('trim', $usernames)));
    $mode = (string) ($_POST['mode'] ?? 'main_only');
    $lbId = (int) ($_POST['lb_id'] ?? 0);

    if (!$usernames) {
        throw new InvalidArgumentException('Informe pelo menos um usuário do XUI.');
    }

    $n = 0;
    foreach ($usernames as $u) {
        LbRouter::assign($u, $mode, $lbId, 'painel');
        $n++;
    }
    $_SESSION['flash'] = sprintf('%d usuário(s) roteado(s) em modo %s.', $n, $mode);
} catch (Throwable $e) {
    $_SESSION['flash'] = 'Falha ao rotear usuário: ' . $e->getMessage();
}

header('Location: /lb.php');
exit;