<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

$id = (int) ($_POST['id'] ?? 0);
$alias = $id > 0 ? AliasRepository::find($id) : null;
if ($alias) {
    AliasRepository::delete($id);
    Audit::log('domain.delete', 'domínio ' . $alias['hostname']);
    $_SESSION['flash'] = 'Domínio ' . $alias['hostname'] . ' removido.';
}
header('Location: /dashboard.php');
