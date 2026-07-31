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
$data = [
    'hostname' => strtolower(trim((string) ($_POST['hostname'] ?? ''))),
    'origin_id' => (int) ($_POST['origin_id'] ?? 0),
    'is_primary' => !empty($_POST['is_primary']),
    'active' => !empty($_POST['active']),
    'require_token' => !empty($_POST['require_token']),
];

if ($data['hostname'] === '' || $data['origin_id'] < 1) {
    http_response_code(422);
    exit('Dados inválidos.');
}

if ($id > 0) {
    AliasRepository::update($id, $data);
    Audit::log('alias_update', 'Alias #' . $id . ' -> ' . $data['hostname'], $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');
} else {
    $newId = AliasRepository::create($data);
    Audit::log('alias_create', 'Alias #' . $newId . ' -> ' . $data['hostname'], $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');
}

header('Location: /dashboard.php#aliases');
exit;
