<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();
csrf_verify();

$job = (string) ($_POST['job'] ?? '');
$action = (string) ($_POST['action'] ?? 'run');
$back = match ((string) ($_POST['back'] ?? '')) {
    'jobs' => '/jobs.php',
    'lb' => '/lb.php',
    default => '/restream.php',
};

if (!isset(JobRunner::CATALOG[$job])) {
    $_SESSION['flash'] = 'Job desconhecido.';
    header('Location: ' . $back);
    exit;
}

// Disjuntor aberto = job pausado após falhas seguidas. Fechar é ação explícita
// do operador, com trilha no audit_logs.
if ($action === 'reset_circuit') {
    JobRunner::resetCircuit($job);
    Audit::log('job_circuit_reset', 'disjuntor fechado manualmente: ' . $job);
    $_SESSION['flash'] = 'Disjuntor de ' . $job . ' fechado. O job volta a rodar no próximo tick.';
    header('Location: ' . $back);
    exit;
}

if (str_starts_with($job, 'xui_sync_') && !XuiReadOnly::available()) {
    $_SESSION['flash'] = 'pdo_mysql ausente na VPS. Rode: apt-get install -y php8.1-mysql && phpenmod pdo_mysql && systemctl reload php8.1-fpm';
    header('Location: ' . $back);
    exit;
}

try {
    $fn = match ($job) {
        'xui_sync_users' => fn (array &$s) => XuiSyncService::syncUsers($s),
        'xui_sync_streams' => fn (array &$s) => XuiSyncService::syncStreams($s),
        'xui_sync_activity' => fn (array &$s) => XuiSyncService::syncActivity($s),
        'direct_enrich' => fn (array &$s) => DirectCatalog::enrich($s),
        'direct_consolidate' => fn (array &$s) => DirectCatalog::consolidate($s),
        'match_sessions' => fn (array &$s) => RestreamRuntime::matchSessions($s),
        'session_sweep' => fn (array &$s) => CdnSession::sweep($s),
        'consolidate_runtime' => fn (array &$s) => RestreamRuntime::consolidate($s),
        'detect_inconsistency' => fn (array &$s) => RestreamRuntime::detectInconsistencies($s),
        'metrics_rollup' => fn (array &$s) => RestreamRuntime::metricsRollup($s),
        'cleanup' => fn (array &$s) => RestreamRuntime::cleanup($s),
        'repair_retry' => fn (array &$s) => RestreamRuntime::repair($s),
        'lb_probe' => fn (array &$s) => LbTelemetry::probeAll($s),
        'lb_rebalance' => fn (array &$s) => LbRouter::rebalance($s),
        'lb_cleanup' => fn (array &$s) => LbTelemetry::cleanup($s),
    };

    $r = JobRunner::run($job, 'painel', $fn);
    if (str_starts_with($job, 'xui_sync_')) {
        XuiSyncConfig::markSync($r['status'] === 'error' ? 'error' : 'ok', $r['error']);
    }
    Audit::log('job_manual_run', sprintf('%s status=%s processed=%d', $job, $r['status'], $r['processed']));
    $_SESSION['flash'] = sprintf(
        'Job %s: %s — %d processados, %d falhas, %dms. %s',
        $job, $r['status'], $r['processed'], $r['failed'], $r['duration_ms'], $r['error']
    );
} catch (Throwable $e) {
    error_log('[run-job] ' . $e->getMessage());
    $_SESSION['flash'] = 'Falha ao executar o job: ' . $e->getMessage();
}

header('Location: ' . $back);
exit;