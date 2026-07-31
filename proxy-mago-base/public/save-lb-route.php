<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

try {
    $scope = (string) ($_POST['scope'] ?? 'selected');
    $usernames = preg_split('/[\s,;]+/', trim((string) ($_POST['usernames'] ?? ''))) ?: [];
    $usernames = array_values(array_filter(array_map('trim', $usernames)));
    $mode = (string) ($_POST['mode'] ?? 'main_only');
    $lbId = (int) ($_POST['lb_id'] ?? 0);

    if ($scope === 'all') {
        $rows = Database::pdo()->query(
            'SELECT username FROM xui_users_cache
              WHERE username <> "" AND enabled = 1
              ORDER BY username ASC'
        )->fetchAll();
        $usernames = array_values(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['username'] ?? '')),
            $rows ?: []
        )));
        if (!$usernames) {
            throw new InvalidArgumentException('Nenhum usuário ativo foi encontrado no espelho do XUI.');
        }
    }

    if (!$usernames) {
        throw new InvalidArgumentException('Informe pelo menos um usuário do XUI ou escolha "todos".');
    }

    $n = 0;
    foreach ($usernames as $u) {
        LbRouter::assign($u, $mode, $lbId, 'painel');
        $n++;
    }
    $_SESSION['flash'] = sprintf(
        '%d usuário(s) roteado(s) em modo %s%s.',
        $n,
        $mode,
        $scope === 'all' ? ' (todos do XUI)' : ''
    );
} catch (Throwable $e) {
    $_SESSION['flash'] = 'Falha ao rotear usuário: ' . $e->getMessage();
}

header('Location: /lb.php');
exit;
