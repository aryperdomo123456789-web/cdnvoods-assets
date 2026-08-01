#!/usr/bin/env php
<?php

/**
 * Tick único de jobs internos — VPS 45.140.192.237 / Ubuntu 22.04
 * Path de produção: /opt/proxy-mago/proxy-mago-base
 *
 * Uso:
 *   php /opt/proxy-mago/proxy-mago-base/bin/jobs-run.php            # tick (respeita intervalos)
 *   php bin/jobs-run.php --job=xui_sync_activity --force            # roda um job específico
 *   php bin/jobs-run.php --list                                     # lista o catálogo
 *
 * Cron sugerido (todo minuto; o loop interno cobre o intervalo de 5s):
 *   * * * * * www-data /usr/bin/php /opt/proxy-mago/proxy-mago-base/bin/jobs-run.php >> /opt/proxy-mago/proxy-mago-base/storage/logs/jobs.log 2>&1
 */

require_once dirname(__DIR__) . '/app/bootstrap-cli.php';

$opts = getopt('', ['job::', 'force', 'list', 'loop::', 'trigger::', 'profile::']);
$trigger = (string) ($opts['trigger'] ?? 'cron');
$profile = (string) ($opts['profile'] ?? 'all');
$profile = preg_replace('/[^a-z0-9_-]/i', '', $profile) ?: 'all';
$lockPath = dirname(__DIR__) . '/storage/cache/jobs-run-' . $profile . '.lock';
$lockHandle = @fopen($lockPath, 'c+');
if ($lockHandle === false && is_file($lockPath) && !is_writable($lockPath)) {
    @unlink($lockPath);
    $lockHandle = @fopen($lockPath, 'c+');
}
if ($lockHandle === false) {
    fwrite(STDERR, "[fatal] não foi possível abrir lock de jobs\n");
    exit(1);
}
@chmod($lockPath, 0664);

// Evita concorrência entre ticks do cron e execuções manuais longas.
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (isset($opts['job'])) {
        fwrite(STDERR, "[skip] jobs-run já está em execução; tente novamente em alguns segundos\n");
    }
    exit(0);
}
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

if (isset($opts['list'])) {
    foreach (JobRunner::CATALOG as $name => [$purpose, $interval]) {
        printf("%-22s %-4ds  %s\n", $name, $interval, $purpose);
    }
    exit(0);
}

function job_callable(string $name): callable
{
    switch ($name) {
        case 'xui_sync_users':
            return function (array &$s) { XuiSyncService::syncUsers($s); XuiSyncConfig::markSync('ok'); };
        case 'xui_sync_streams':
            return function (array &$s) { XuiSyncService::syncStreams($s); XuiSyncConfig::markSync('ok'); };
        case 'xui_sync_series':
            return function (array &$s) { XuiSyncService::syncSeries($s); XuiSyncConfig::markSync('ok'); };
        case 'xui_sync_activity':
            return function (array &$s) { XuiSyncService::syncActivity($s); XuiSyncConfig::markSync('ok'); };
        case 'direct_enrich':
            return fn (array &$s) => DirectCatalog::enrich($s);
        case 'direct_consolidate':
            return fn (array &$s) => DirectCatalog::consolidate($s);
        case 'match_sessions':
            return fn (array &$s) => RestreamRuntime::matchSessions($s);
        case 'session_sweep':
            return fn (array &$s) => CdnSession::sweep($s);
        case 'consolidate_runtime':
            return fn (array &$s) => RestreamRuntime::consolidate($s);
        case 'detect_inconsistency':
            return fn (array &$s) => RestreamRuntime::detectInconsistencies($s);
        case 'metrics_rollup':
        case 'metrics_rollup_light':
            return fn (array &$s) => RestreamRuntime::metricsRollupLight($s);
        case 'metrics_rollup_analytics':
            return fn (array &$s) => RestreamRuntime::metricsRollupAnalytics($s);
        case 'cleanup':
            return fn (array &$s) => RestreamRuntime::cleanup($s);
        case 'repair_retry':
            return fn (array &$s) => RestreamRuntime::repair($s);
        case 'lb_probe':
            return fn (array &$s) => LbTelemetry::probeAll($s);
        case 'lb_rebalance':
            return fn (array &$s) => LbRouter::rebalance($s);
        case 'lb_autoroute':
            return fn (array &$s) => LbRouter::autoroute($s);
        case 'lb_cleanup':
            return fn (array &$s) => LbTelemetry::cleanup($s);
    }
    throw new RuntimeException('job desconhecido: ' . $name);
}

function run_job(string $name, string $trigger): array
{
    $needsXui = str_starts_with($name, 'xui_sync_');
    if ($needsXui && !XuiSyncConfig::enabled()) {
        return ['status' => 'skipped', 'processed' => 0, 'failed' => 0, 'error' => 'sync desabilitado', 'duration_ms' => 0, 'run_id' => ''];
    }
    $result = JobRunner::run($name, $trigger, job_callable($name));
    if ($needsXui && $result['status'] === 'error') {
        XuiSyncConfig::markSync('error', $result['error']);
    }
    return $result;
}

function profile_jobs(string $profile): array
{
    $all = array_keys(JobRunner::CATALOG);
    return match ($profile) {
        'fast' => JobRunner::fastProfile(),
        'heavy' => JobRunner::heavyProfile(),
        default => $all,
    };
}

if (!empty($opts['job'])) {
    $name = (string) $opts['job'];
    if (!isset($opts['force']) && !JobRunner::due($name)) {
        echo "[skip] $name ainda dentro do intervalo\n";
        exit(0);
    }
    $r = run_job($name, $trigger === 'cron' ? 'manual' : $trigger);
    printf("[%s] %s processed=%d failed=%d %dms %s\n", $r['status'], $name, $r['processed'], $r['failed'], $r['duration_ms'], $r['error']);
    exit($r['status'] === 'error' ? 1 : 0);
}

/**
 * Tick padrão: roda por ~55s, disparando cada job quando vence o intervalo.
 * Isso dá granularidade de 5s para sessões ativas usando apenas cron de 1min.
 */
$deadline = time() + (int) ($opts['loop'] ?? 55);
$exit = 0;
// Manutenção é escrita e pertence ao runner, nunca ao polling read-only do painel.
JobRunner::recoverStaleRunning();
do {
    foreach (profile_jobs($profile) as $name) {
        try {
            if (!JobRunner::due($name)) { continue; }
            $r = run_job($name, 'cron');
            if ($r['status'] === 'error') {
                $exit = 1;
                fwrite(STDERR, sprintf("[error] %s: %s\n", $name, $r['error']));
            }
        } catch (Throwable $e) {
            $exit = 1;
            fwrite(STDERR, sprintf("[fatal] %s: %s\n", $name, $e->getMessage()));
        }
    }
    if (time() >= $deadline) { break; }
    sleep(2);
} while (true);

exit($exit);
