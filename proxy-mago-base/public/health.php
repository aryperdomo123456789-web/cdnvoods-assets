<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_seeded_or_setup();
Auth::requireLogin();

$checks = HealthCheck::run();
$ok = !in_array(false, array_column($checks, 'ok'), true);
http_response_code($ok ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => $ok, 'checked_at' => date('c'), 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
