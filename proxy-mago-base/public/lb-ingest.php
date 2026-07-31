<?php

/**
 * Endpoint de heartbeat: o músculo empurra telemetria para o cérebro.
 * Autenticação por token de agente (gerado na instalação), nunca por sessão.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = (string) ($_SERVER['HTTP_X_LB_TOKEN'] ?? '');
$node = $token !== '' ? LbNode::findByToken($token) : null;

if (!$node) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'token inválido']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 65536) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload grande demais']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'json inválido']);
    exit;
}

$num = static fn (string $k, float $max): float => max(0.0, min($max, (float) ($payload[$k] ?? 0)));

try {
    LbTelemetry::record((int) $node['id'], [
        'cpu_pct' => $num('cpu_pct', 100),
        'ram_used_mb' => $num('ram_used_mb', 1048576),
        'ram_free_mb' => $num('ram_free_mb', 1048576),
        'disk_used_gb' => $num('disk_used_gb', 1048576),
        'rx_mbps' => $num('rx_mbps', 1000000),
        'tx_mbps' => $num('tx_mbps', 1000000),
        'sessions_active' => (int) $num('sessions_active', 1000000),
        'users_active' => (int) $num('users_active', 1000000),
        'errors_5m' => (int) $num('errors_5m', 1000000),
    ], 'heartbeat');

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[lb-ingest] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'falha ao registrar telemetria']);
}