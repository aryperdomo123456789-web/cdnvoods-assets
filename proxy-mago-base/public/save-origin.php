<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_seeded_or_setup();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
verify_csrf($_POST['csrf_token'] ?? '');

$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
$name = trim((string) ($_POST['name'] ?? ''));
$host = trim((string) ($_POST['host'] ?? ''));
$port = (int) ($_POST['port'] ?? 80);
$scheme = in_array($_POST['scheme'] ?? 'http', ['http', 'https'], true) ? $_POST['scheme'] : 'http';
$type = strtolower(trim((string) ($_POST['type'] ?? 'a'))) === 'cname' ? 'cname' : 'a';
$basePath = trim((string) ($_POST['base_path'] ?? ''));
$hostHeader = trim((string) ($_POST['host_header'] ?? ''));
$authUser = (string) ($_POST['auth_user'] ?? '');
$authPass = (string) ($_POST['auth_pass'] ?? '');
$active = !empty($_POST['active']);

if ($name === '' || $host === '' || $port < 1 || $port > 65535) {
    header('Location: /dashboard.php?err=origin#origins');
    exit;
}

// Validação por tipo: A exige IP; CNAME exige hostname.
$isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
$isHost = (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host);
if ($type === 'a' && !$isIp) {
    header('Location: /dashboard.php?err=origin_ip#origins');
    exit;
}
if ($type === 'cname' && !$isHost) {
    header('Location: /dashboard.php?err=origin_host#origins');
    exit;
}
if ($hostHeader !== '' && !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $hostHeader)) {
    header('Location: /dashboard.php?err=origin_hosthdr#origins');
    exit;
}

$data = [
    'name' => $name,
    'host' => $host,
    'port' => $port,
    'scheme' => $scheme,
    'base_path' => $basePath,
    'host_header' => $hostHeader,
    'auth_user' => $authUser,
    'auth_pass' => $authPass,
    'active' => $active,
    'type' => $type,
];

if ($id) {
    OriginRepository::update($id, $data);
    Audit::log('origin.update', 'origin #' . $id . ' type=' . $type);
} else {
    $newId = OriginRepository::create($data);
    Audit::log('origin.create', 'origin #' . $newId . ' type=' . $type);
}
header('Location: /dashboard.php?origin=saved#origins');
