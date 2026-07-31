<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$view = (string) ($_GET['view'] ?? 'nodes');

try {
    switch ($view) {
        case 'log':
            $lbId = (int) ($_GET['lb_id'] ?? 0);
            $node = LbNode::find($lbId);
            echo json_encode([
                'ok' => true,
                'install_status' => (string) ($node['install_status'] ?? ''),
                'install_step' => (string) ($node['install_step'] ?? ''),
                'run_id' => (string) ($node['install_run_id'] ?? ''),
                'items' => LbInstaller::log($lbId, (string) ($_GET['run_id'] ?? '')),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'routes':
            echo json_encode(['ok' => true, 'items' => LbRouter::routes(300)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'nodes':
        default:
            $items = [];
            foreach (LbNode::all() as $node) {
                $row = LbNode::publicView($node);
                $row['metrics'] = LbTelemetry::latest((int) $node['id']);
                $row['score'] = round(LbRouter::score($node), 1);
                $items[] = $row;
            }
            echo json_encode([
                'ok' => true,
                'ssh_ready' => LbSsh::available(),
                'ssh_hint' => LbSsh::missingHint(),
                'keyring' => [
                    'exists' => LbKeyring::hasKey(),
                    'fingerprint' => (string) LbKeyring::info()['fingerprint'],
                ],
                'totals' => LbRouter::totals(),
                'items' => $items,
                'ts' => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}