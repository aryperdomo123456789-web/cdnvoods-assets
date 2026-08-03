<?php

declare(strict_types=1);

/**
 * SNAPSHOT do contrato v1 (cérebro -> músculo).
 *
 * O músculo (PHP hoje, Go depois) chama isto a cada SNAPSHOT_TTL e passa a
 * decidir sozinho no caminho quente. Sem token válido não devolve NADA: o
 * snapshot contém host/porta da origem XUI.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/RedisClient.php';
require_once dirname(__DIR__) . '/app/StateStore.php';
require_once dirname(__DIR__) . '/app/Cache.php';
require_once dirname(__DIR__) . '/app/LbContract.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = (string) ($_SERVER['HTTP_X_LB_TOKEN'] ?? '');
$node = $token !== '' ? LbNode::findByToken($token) : null;

if (!$node) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'token inválido']);
    exit;
}

$reported = (string) ($_GET['contract_version'] ?? LbContract::VERSION);
if (!LbContract::versionCompatible($reported)) {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => 'versão de contrato incompatível',
        'brain_version' => LbContract::VERSION,
    ]);
    exit;
}

$cacheKey = 'lb-contract-' . substr(hash('sha1', (string) ($node['id'] ?? '0') . '|' . (string) ($node['token'] ?? $token)), 0, 24);

try {
    $snapshot = Cache::remember($cacheKey, 5, static function () use ($node): array {
        return ['ok' => true] + LbContract::snapshot($node);
    });
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[lb-contract] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'falha ao montar snapshot']);
}
