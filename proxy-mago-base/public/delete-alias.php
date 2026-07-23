<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard.php');
    exit;
}
csrf_verify();
$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    AliasRepository::delete($id);
    Audit::log('alias_delete', 'Alias #' . $id . ' deleted', $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');
}
header('Location: /dashboard.php#aliases');
exit;
