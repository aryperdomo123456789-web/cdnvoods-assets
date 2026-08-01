<?php

declare(strict_types=1);

/**
 * INGEST de eventos do contrato v1 (músculo -> cérebro).
 *
 * O músculo serve o stream e empurra o que aconteceu em LOTE. Aqui o cérebro
 * reaplica as regras (CdnSession/RequestLog/LbTelemetry), então a trilha de
 * auditoria fica idêntica à de um request servido pelo main.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/RedisClient.php';
require_once dirname(__DIR__) . '/app/StateStore.php';
require_once dirname(__DIR__) . '/app/LbContract.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'use POST']);
    exit;
}

$token = (string) ($_SERVER['HTTP_X_LB_TOKEN'] ?? '');
$node = $token !== '' ? LbNode::findByToken($token) : null;

if (!$node) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'token inválido']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 2097152) { // 2 MB: 500 eventos folgados
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

$reported = (string) ($payload['contract_version'] ?? LbContract::VERSION);
if (!LbContract::versionCompatible($reported)) {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => 'versão de contrato incompatível',
        'brain_version' => LbContract::VERSION,
    ]);
    exit;
}

$events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
if (count($events) > 500) {
    $events = array_slice($events, 0, 500);
}

try {
    $result = LbContract::applyEvents((int) $node['id'], $events);
    echo json_encode(['ok' => true, 'contract_version' => LbContract::VERSION] + $result);
} catch (Throwable $e) {
    error_log('[lb-events] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'falha ao aplicar eventos']);
}