<?php

/**
 * API JSON do painel de restreamento (polling de 3–5s).
 * Sempre exige login: nunca é servida em domínio público de stream.
 *
 *  ?view=live      lista consolidada de usuários ao vivo
 *  ?view=summary   indicadores
 *  ?view=events    últimos requests (com filtros)
 *  ?view=jobs      estado + histórico dos jobs internos
 *  ?view=dim&by=client_ip|public_host|user_agent|route_kind
 *  ?view=sync      status da integração read-only com o XUI
 *  ?view=sessions  sessões lógicas ativas da própria CDN
 *  ?view=users     parque completo de usuários do XUI + conexões em uso agora
 *  ?view=divergences  quadro de divergências abertas (CDN x XUI x limite)
 *  ?view=direct    rastreio de direct source (hosts finais e hops bloqueados)
 *  ?view=timeline  TRILHA ÚNICA (uma linha por sessão: quem, de onde, por qual
 *                  host público, por qual LB, direct source, divergência)
 *  ?view=lb        estado do balanceamento + histórico de decisões por usuário
 *  ?view=health    saúde do cérebro (SQLite, WAL, jobs atrasados, disjuntores)
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$view = (string) ($_GET['view'] ?? 'live');
$filters = [
    'username' => trim((string) ($_GET['username'] ?? '')),
    'host' => trim((string) ($_GET['host'] ?? '')),
    'ip' => trim((string) ($_GET['ip'] ?? '')),
    'kind' => trim((string) ($_GET['kind'] ?? '')),
    'player' => trim((string) ($_GET['player'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'over' => !empty($_GET['over']),
    'only_problems' => !empty($_GET['only_problems']),
    'current_only' => !empty($_GET['current_only']),
    'severity' => trim((string) ($_GET['severity'] ?? '')),
    'direct' => !empty($_GET['direct']),
];
$limit = (int) ($_GET['limit'] ?? 200);
$t0 = microtime(true);

try {
    switch ($view) {
        case 'summary':
            $out = RestreamRuntime::summary();
            break;
        case 'events':
            $out = ['events' => RestreamRuntime::events($filters, $limit)];
            break;
        case 'jobs':
            $out = [
                'states' => JobRunner::states(),
                'history' => JobRunner::history((int) ($_GET['history'] ?? 40), (string) ($_GET['job'] ?? '')),
                'steps' => !empty($_GET['run_id']) ? JobRunner::steps((string) $_GET['run_id']) : [],
                'now' => time(),
            ];
            break;
        case 'timeline':
            $out = [
                'summary' => AuditTimeline::summary(),
                'rows' => AuditTimeline::search([
                    'username' => $filters['username'],
                    'ip' => $filters['ip'],
                    'host' => $filters['host'],
                    'player' => $filters['player'],
                    'kind' => $filters['kind'],
                    'direct' => $filters['direct'],
                    'only_problems' => $filters['only_problems'],
                    'request_id' => trim((string) ($_GET['request_id'] ?? '')),
                    'lb_id' => (int) ($_GET['lb_id'] ?? 0),
                    'since_minutes' => (int) ($_GET['since'] ?? 60),
                ], $limit),
            ];
            break;
        case 'lb':
            $out = [
                'totals' => LbRouter::totals(),
                'nodes' => LbNode::all(),
                'routes' => LbRouter::routes($limit),
                'history' => LbRouter::historyRows($filters['username'], 100),
            ];
            break;
        case 'health':
            $out = RestreamRuntime::healthView();
            break;
        case 'dim':
            $out = ['rows' => RestreamRuntime::byDimension((string) ($_GET['by'] ?? 'client_ip'), $limit)];
            break;
        case 'sessions':
            $limit = max(10, min(80, $limit));
            $out = [
                'rows' => CdnSession::live($filters, $limit),
                'kpis' => RestreamRuntime::kpis(),
            ];
            break;
        case 'users':
            $limit = max(10, min(200, $limit));
            $out = [
                'rows' => UserIntelligence::users([
                    'q' => trim((string) ($_GET['q'] ?? $filters['username'])),
                    'only_active' => !empty($_GET['only_active']),
                    'over_limit' => !empty($_GET['over']),
                    'enabled_only' => !empty($_GET['enabled_only']),
                ], $limit),
                'totals' => UserIntelligence::totals(),
            ];
            break;
        case 'user_connections':
            $out = UserIntelligence::detail((string) ($_GET['username'] ?? ''));
            break;
        case 'divergences':
            $limit = max(10, min(100, $limit));
            $out = [
                'rows' => Divergence::open($filters, $limit),
                'counters' => Divergence::counters(),
                'mode' => Divergence::mode(),
                'tolerance' => Divergence::tolerance(),
            ];
            break;
        case 'direct':
            // Verdade consolidada: catálogo do XUI (DB) + consumo real (runtime).
            $out = [
                'active' => DirectSource::activeSessions(),
                'summary' => DirectCatalog::summary(),
                'streams' => DirectCatalog::streams([
                    'mode' => trim((string) ($_GET['mode'] ?? '')),
                    'consistency' => trim((string) ($_GET['consistency'] ?? '')),
                    'host' => $filters['host'],
                ], $limit),
                'top_hosts' => DirectCatalog::topHosts(15, 10),
                'failures' => DirectCatalog::failuresByHost(60, 20),
                'users' => DirectCatalog::activeUsers(50),
                'blocked' => DirectSource::blocked(60, 40),
                'divergences' => Divergence::open(['direct' => true], 50),
            ];
            break;
        case 'direct_stream':
            $sid = (int) ($_GET['stream_id'] ?? 0);
            $out = [
                'state' => DirectCatalog::forStream($sid),
                'hops' => DirectSource::forStream($sid, 50),
            ];
            break;
        case 'user':
            $out = RestreamRuntime::userDetail((string) ($_GET['username'] ?? ''));
            break;
        case 'sync':
            $cfg = XuiSyncConfig::get();
            unset($cfg['password']);
            $out = [
                'config' => $cfg,
                'driver_pdo_mysql' => XuiReadOnly::available(),
                'ping' => XuiSyncConfig::enabled() ? XuiReadOnly::ping() : ['ok' => false, 'ms' => 0, 'error' => 'sync desabilitado'],
                'cache' => [
                    'users' => (int) Database::pdo()->query('SELECT COUNT(*) FROM xui_users_cache')->fetchColumn(),
                    'streams' => (int) Database::pdo()->query('SELECT COUNT(*) FROM xui_streams_cache')->fetchColumn(),
                    'sessions' => (int) Database::pdo()->query('SELECT COUNT(*) FROM xui_activity_now_cache')->fetchColumn(),
                ],
            ];
            break;
        default:
            $out = [
                'summary' => RestreamRuntime::summary(),
                'rows' => RestreamRuntime::live($filters, $limit),
            ];
    }
    // Meta em TODA resposta: o painel mostra a idade do dado, então ninguém
    // olha número velho pensando que é ao vivo. `poll_after_ms` é o intervalo
    // adaptativo que o front obedece (Fase 1.3 + 1.4).
    $queryMs = (int) round((microtime(true) - $t0) * 1000);
    $out['_meta'] = [
        'view' => $view,
        'generated_at' => date('c'),
        'epoch' => time(),
        'query_ms' => $queryMs,
        'db_lock_retries' => Database::lockRetries(),
    ] + Freshness::meta($view, $queryMs);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[restream-data] ' . $e->getMessage());
    echo json_encode(['error' => 'falha ao consultar restreamento', 'detail' => $e->getMessage()]);
}
