<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$view = (string) ($_GET['view'] ?? 'nodes');
$t0 = microtime(true);

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
            echo json_encode([
                'ok' => true,
                'items' => LbRouter::routes(300),
                '_meta' => Freshness::meta('routes', (int) round((microtime(true) - $t0) * 1000)),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'nodes':
        default:
            // Micro-cache de 3s: várias abas abertas deixam de multiplicar a
            // mesma leitura de telemetria a cada tick (Fase 1.4).
            $items = Cache::remember('lb-nodes-view', 3, static function (): array {
                $out = [];
                $now = time();
                foreach (LbNode::all() as $node) {
                    $row = LbNode::publicView($node);
                    $metrics = LbTelemetry::latest((int) $node['id']);
                    $row['metrics'] = $metrics;
                    $row['score'] = round(LbRouter::score($node), 1);
                    // Frescor por nó: telemetria velha não pode passar por
                    // "servidor saudável".
                    $ts = (int) ($metrics['ts_epoch'] ?? 0);
                    $probe = (int) ($node['last_probe_epoch'] ?? 0);
                    $row['metrics_age_seconds'] = $ts > 0 ? max(0, $now - $ts) : null;
                    $row['probe_age_seconds'] = $probe > 0 ? max(0, $now - $probe) : null;
                    $row['stale'] = $ts <= 0 || ($now - $ts) > 180;
                    $out[] = $row;
                }
                return $out;
            });

            $stale = 0;
            $unhealthy = 0;
            foreach ($items as $row) {
                if (!empty($row['stale'])) { $stale++; }
                if (($row['health_status'] ?? '') !== 'ok') { $unhealthy++; }
            }
            $meta = Freshness::meta('nodes', (int) round((microtime(true) - $t0) * 1000));
            $meta['stale_nodes'] = $stale;
            $meta['unhealthy_nodes'] = $unhealthy;
            if ($stale > 0) {
                $meta['degraded'] = true;
                $meta['reasons'][] = $stale . ' LB com telemetria acima de 3min';
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
                '_meta' => $meta,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}