<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_seeded_or_setup();
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
verify_csrf($_POST['csrf_token'] ?? '');
$id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
$label = trim((string) ($_POST['label'] ?? ''));
$host = trim((string) ($_POST['host'] ?? ''));
$port = (int) ($_POST['port'] ?? 80);
$scheme = in_array($_POST['scheme'] ?? 'http', ['http', 'https'], true) ? $_POST['scheme'] : 'http';
$active = !empty($_POST['active']);
if ($label === '' || $host === '' || $port < 1 || $port > 65535) { header('Location: /dashboard.php?err=origin'); exit; }
$data = ['label' => $label, 'host' => $host, 'port' => $port, 'scheme' => $scheme, 'active' => $active];
if ($id) { OriginRepository::update($id, $data); Audit::log('origin.update', 'origin #' . $id); } else { $newId = OriginRepository::create($data); Audit::log('origin.create', 'origin #' . $newId); }
header('Location: /dashboard.php?origin=saved');
