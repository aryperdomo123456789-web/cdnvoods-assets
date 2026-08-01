<?php

/**
 * Auditoria de jobs internos.
 *
 * Todo job (sync XUI, sessões, matching, limpeza, métricas, repair) roda por
 * aqui. Cada execução gera uma linha em `job_runs` e atualiza `job_state` com
 * última execução, duração, status, processados, falhas e próxima execução
 * esperada. Nada roda "invisível".
 *
 * Fase 2 (rastreabilidade total):
 *  - LOCK por job: dois ticks do cron nunca rodam o mesmo job em paralelo
 *    (era uma das fontes de "database is locked" na VPS);
 *  - PASSOS: o job informa em que etapa está (job_step_history + current_step),
 *    então um job de 40s deixa de ser caixa-preta;
 *  - DISJUNTOR: job que falha N vezes seguidas entra em circuito aberto por um
 *    tempo, para de martelar o SQLite e aparece no painel com o motivo;
 *  - sempre grava com retry (Database::write), então log de job não derruba job.
 */
final class JobRunner
{
    /** Falhas consecutivas para abrir o disjuntor. */
    public const CIRCUIT_THRESHOLD = 5;
    /** Teto da janela de circuito aberto (segundos). */
    public const CIRCUIT_MAX_BACKOFF = 900;

    private static string $currentRunId = '';
    private static string $currentJob = '';
    private static int $stepSeq = 0;
    private static float $stepStart = 0.0;
    private static string $stepName = '';

    public const CATALOG = [
        'xui_sync_activity' => ['Espelha user_activity_now do XUI (sessões ativas)', 5],
        'xui_sync_users'    => ['Espelha users do XUI (limites, validade, flags)', 60],
        'xui_sync_streams'  => ['Espelha streams do XUI (nome/tipo/container)', 300],
        'direct_enrich'     => ['Parseia stream_source do XUI e detecta host de direct source', 300],
        // Em produção real com ~480k streams, esse job pode levar dezenas de
        // segundos. Rodar a cada 30s trava o SQLite e faz o painel perder
        // sessões ao vivo. A trilha runtime do request continua imediata; a
        // consolidação pesada do catálogo pode ser bem mais espaçada.
        'direct_consolidate' => ['Cruza direct source do DB com o runtime e abre divergências', 300],
        'match_sessions'    => ['Cruza requests do proxy com sessões ativas do XUI', 10],
        'session_sweep'     => ['Encerra sessões locais ociosas da CDN e mantém o contador próprio', 10],
        'consolidate_runtime' => ['Consolida proxy_user_runtime para o painel ao vivo', 10],
        'detect_inconsistency' => ['Detecta divergências (swap, acima do limite, órfãos)', 30],
        'metrics_rollup_light' => ['Grava KPIs leves da CDN para o painel ao vivo', 30],
        'metrics_rollup_analytics' => ['Grava snapshots analíticos (top hosts/players/kinds)', 300],
        'metrics_rollup'    => ['Alias legado do rollup leve da CDN', 30],
        'cleanup'           => ['Limpeza de eventos antigos, rate_limit e job_runs', 3600],
        'repair_retry'      => ['Reprocessa matching de requests que falharam', 300],
        'lb_probe'          => ['Coleta CPU/RAM/banda dos LBs por SSH e atualiza saúde', 30],
        'lb_rebalance'      => ['Reavalia usuários em modo auto e escolhe o melhor LB', 60],
        'lb_autoroute'      => ['Cria rota de LB para usuários novos usando o modo padrão', 120],
        'lb_cleanup'        => ['Limpa métricas antigas dos LBs', 3600],
    ];

    private const FAST_PROFILE = [
        'xui_sync_activity',
        'xui_sync_users',
        'match_sessions',
        'session_sweep',
        'consolidate_runtime',
        'lb_probe',
        'lb_rebalance',
    ];


    private const HEAVY_PROFILE = [
        'xui_sync_streams',
        'direct_enrich',
        'direct_consolidate',
        'detect_inconsistency',
        'metrics_rollup_analytics',
        'cleanup',
        'repair_retry',
        'lb_cleanup',
    ];

    public static function fastProfile(): array
    {
        return self::FAST_PROFILE;
    }

    public static function heavyProfile(): array
    {
        return self::HEAVY_PROFILE;
    }

    public static function isFastJob(string $jobName): bool
    {
        return in_array($jobName, self::FAST_PROFILE, true);
    }

    /**
     * @param callable(array &$stats): void $fn  recebe ['processed'=>0,'failed'=>0,'details'=>[]]
     * @return array{status:string,processed:int,failed:int,error:string,duration_ms:int,run_id:string}
     */
    public static function run(string $jobName, string $trigger, callable $fn): array
    {
        $purpose = self::CATALOG[$jobName][0] ?? '';
        $interval = (int) (self::CATALOG[$jobName][1] ?? 60);
        $runId = $jobName . '-' . bin2hex(random_bytes(6));
        $start = microtime(true);
        // LOCK de execução: um job nunca roda duas vezes ao mesmo tempo.
        $lock = self::acquireLock($jobName);
        if ($lock === null) {
            self::bumpSkipped($jobName, 'lock');
            return ['status' => 'locked', 'processed' => 0, 'failed' => 0,
                    'error' => 'já em execução', 'duration_ms' => 0, 'run_id' => ''];
        }

        // SQLite só admite um escritor por vez. O lock por nome acima impede
        // apenas duas cópias do MESMO job; perfis fast/heavy ainda podiam
        // executar jobs diferentes em paralelo e disputar o arquivo inteiro.
        // Este mutex serializa oficialmente todos os jobs escritores, inclusive
        // invocações manuais. Tráfego HTTP continua independente e protegido
        // pelo busy_timeout/retry; PostgreSQL segue sendo a solução definitiva.
        $writerLock = self::acquireWriterLock();
        if ($writerLock === null) {
            self::releaseLock($lock);
            self::bumpSkipped($jobName, 'writer-lock');
            return ['status' => 'locked', 'processed' => 0, 'failed' => 0,
                    'error' => 'fila global de escrita indisponível', 'duration_ms' => 0, 'run_id' => ''];
        }

        $pdo = Database::pdo();

        // DISJUNTOR: circuito aberto = não martela o SQLite nem a origem.
        $circuit = self::circuit($jobName);
        if ($circuit['open'] && $trigger === 'cron') {
            self::releaseLock($writerLock);
            self::releaseLock($lock);
            self::bumpSkipped($jobName, 'circuit');
            return ['status' => 'circuit_open', 'processed' => 0, 'failed' => 0,
                    'error' => sprintf('circuito aberto por %ds (%s)', $circuit['seconds_left'], $circuit['reason']),
                    'duration_ms' => 0, 'run_id' => ''];
        }

        self::$currentRunId = $runId;
        self::$currentJob = $jobName;
        self::$stepSeq = 0;
        self::$stepName = '';
        self::$stepStart = 0.0;

        Database::run(
            'INSERT INTO job_runs (job_name, run_id, purpose, trigger_source, started_at, started_epoch, status, host)
             VALUES (:n,:r,:p,:t,:sa,:se,\'running\',:h)',
            [
                ':n' => $jobName, ':r' => $runId, ':p' => $purpose, ':t' => $trigger,
                ':sa' => date('c'), ':se' => time(),
                ':h' => substr((string) gethostname(), 0, 60),
            ],
            'job_runs.open'
        );
        Database::run(
            'INSERT INTO job_state (job_name, purpose, interval_seconds, running, running_since_epoch, last_run_id, updated_at)
             VALUES (:n,:p,:i,1,:se,:r,:up)
             ON CONFLICT(job_name) DO UPDATE SET running=1, running_since_epoch=excluded.running_since_epoch,
               last_run_id=excluded.last_run_id, current_step=\'\', updated_at=excluded.updated_at',
            [':n' => $jobName, ':p' => $purpose, ':i' => $interval, ':se' => time(), ':r' => $runId, ':up' => date('c')],
            'job_state.running'
        );

        $stats = ['processed' => 0, 'failed' => 0, 'details' => []];
        $status = 'ok';
        $error = '';
        $retriesBefore = Database::lockRetries();
        try {
            $fn($stats);
            self::closeStep('ok');
            if ((int) $stats['failed'] > 0) {
                $status = 'partial';
            }
        } catch (Throwable $e) {
            $status = 'error';
            $error = substr($e->getMessage(), 0, 400);
            $stats['failed'] = max(1, (int) $stats['failed']);
            self::closeStep('error', $error);
            if (DbLockDiag::isLockError($e)) {
                DbLockDiag::note('(job)', 'run', $jobName, $e->getMessage(), 1, true);
            }
        }

        $duration = (int) round((microtime(true) - $start) * 1000);
        $details = substr(json_encode($stats['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 2000);
        $lockRetries = Database::lockRetries() - $retriesBefore;

        Database::run(
            'UPDATE job_runs SET finished_at=:f, duration_ms=:d, status=:s, processed=:p, failed=:fa,
             error=:e, details=:de, last_step=:ls, steps_done=:sd, lock_retries=:lr WHERE run_id=:r',
            [
                ':f' => date('c'), ':d' => $duration, ':s' => $status,
                ':p' => (int) $stats['processed'], ':fa' => (int) $stats['failed'],
                ':e' => $error, ':de' => $details, ':ls' => self::$stepName,
                ':sd' => self::$stepSeq, ':lr' => max(0, $lockRetries), ':r' => $runId,
            ],
            'job_runs.close'
        );

        // Disjuntor: cresce a janela conforme as falhas consecutivas.
        $failedRun = $status === 'error';
        $consec = $failedRun ? ((int) $circuit['consecutive_failures'] + 1) : 0;
        $openUntil = 0;
        $openReason = '';
        if ($consec >= self::CIRCUIT_THRESHOLD) {
            $backoff = (int) min(self::CIRCUIT_MAX_BACKOFF, $interval * (2 ** min(5, $consec - self::CIRCUIT_THRESHOLD + 1)));
            $openUntil = time() + max($interval, $backoff);
            $openReason = substr($error !== '' ? $error : 'falhas consecutivas', 0, 200);
        }

        Database::run(
            'INSERT INTO job_state (job_name, purpose, interval_seconds, last_run_at, last_run_epoch,
                last_status, last_duration_ms, last_processed, last_failed, last_error, next_run_epoch,
                total_runs, total_failures, updated_at, running, running_since_epoch, last_run_id,
                current_step, consecutive_failures, circuit_open_until, circuit_reason, max_duration_ms)
             VALUES (:n,:p,:i,:la,:le,:st,:d,:pr,:fa,:er,:nr,1,:tf,:up,0,0,:rid,\'\',:cf,:cu,:cr,:d2)
             ON CONFLICT(job_name) DO UPDATE SET
                purpose=excluded.purpose,
                interval_seconds=excluded.interval_seconds,
                last_run_at=excluded.last_run_at,
                last_run_epoch=excluded.last_run_epoch,
                last_status=excluded.last_status,
                last_duration_ms=excluded.last_duration_ms,
                last_processed=excluded.last_processed,
                last_failed=excluded.last_failed,
                last_error=excluded.last_error,
                next_run_epoch=excluded.next_run_epoch,
                total_runs=job_state.total_runs + 1,
                total_failures=job_state.total_failures + excluded.total_failures,
                updated_at=excluded.updated_at,
                running=0,
                running_since_epoch=0,
                last_run_id=excluded.last_run_id,
                current_step=\'\',
                consecutive_failures=excluded.consecutive_failures,
                circuit_open_until=excluded.circuit_open_until,
                circuit_reason=excluded.circuit_reason,
                max_duration_ms=MAX(job_state.max_duration_ms, excluded.max_duration_ms)',
            [
                ':n' => $jobName, ':p' => $purpose, ':i' => $interval,
                ':la' => date('c'), ':le' => time(), ':st' => $status, ':d' => $duration,
                ':pr' => (int) $stats['processed'], ':fa' => (int) $stats['failed'], ':er' => $error,
                ':nr' => time() + $interval, ':tf' => $failedRun ? 1 : 0, ':up' => date('c'),
                ':rid' => $runId, ':cf' => $consec, ':cu' => $openUntil, ':cr' => $openReason,
                ':d2' => $duration,
            ],
            'job_state.close'
        );

        self::releaseLock($writerLock);
        self::releaseLock($lock);
        self::$currentRunId = '';
        self::$currentJob = '';

        if ($status === 'error') {
            Audit::log('job_error', $jobName . ': ' . $error, '-', 'job');
        }
        if ($openUntil > 0) {
            Audit::log('job_circuit_open',
                sprintf('%s: %d falhas consecutivas, pausado até %s', $jobName, $consec, date('c', $openUntil)), '-', 'job');
        }

        return [
            'status' => $status,
            'processed' => (int) $stats['processed'],
            'failed' => (int) $stats['failed'],
            'error' => $error,
            'duration_ms' => $duration,
            'run_id' => $runId,
        ];
    }

    /* ------------------------------------------------------------- passos */

    /**
     * Marca o passo atual do job em execução. Fecha o anterior automaticamente.
     * Chamar sem run() ativo é no-op (nunca quebra chamada direta de serviço).
     */
    public static function step(string $name, string $message = ''): void
    {
        if (self::$currentRunId === '') { return; }
        self::closeStep('ok');
        self::$stepSeq++;
        self::$stepName = substr($name, 0, 80);
        self::$stepStart = microtime(true);
        Database::run(
            'UPDATE job_state SET current_step = :s, updated_at = :u WHERE job_name = :n',
            [':s' => self::$stepName . ($message !== '' ? ' — ' . substr($message, 0, 80) : ''),
             ':u' => date('c'), ':n' => self::$currentJob],
            'job_state.step'
        );
    }

    private static function closeStep(string $status, string $message = ''): void
    {
        if (self::$currentRunId === '' || self::$stepName === '' || self::$stepStart <= 0.0) {
            return;
        }
        $ms = (int) round((microtime(true) - self::$stepStart) * 1000);
        Database::run(
            'INSERT INTO job_step_history (run_id, job_name, seq, step, status, message, duration_ms, ts_epoch)
             VALUES (:r,:j,:q,:s,:st,:m,:d,:t)',
            [
                ':r' => self::$currentRunId, ':j' => self::$currentJob, ':q' => self::$stepSeq,
                ':s' => self::$stepName, ':st' => $status, ':m' => substr($message, 0, 300),
                ':d' => $ms, ':t' => time(),
            ],
            'job_step.insert'
        );
        self::$stepStart = 0.0;
    }

    /** @return array<int,array<string,mixed>> */
    public static function steps(string $runId, int $limit = 60): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM job_step_history WHERE run_id = :r ORDER BY seq ASC LIMIT ' . max(1, min(200, $limit))
        );
        $st->execute([':r' => $runId]);
        return $st->fetchAll() ?: [];
    }

    /* ------------------------------------------------- lock e disjuntor */

    /** @return resource|null */
    private static function acquireLock(string $jobName)
    {
        $dir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $path = $dir . '/job-' . preg_replace('/[^a-z0-9_]/i', '_', $jobName) . '.lock';
        $fh = @fopen($path, 'c+');
        if ($fh === false && is_file($path) && !is_writable($path)) {
            @unlink($path);
            $fh = @fopen($path, 'c+');
        }
        if ($fh === false) { return null; }
        @chmod($path, 0664);
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        ftruncate($fh, 0);
        fwrite($fh, (string) getmypid());
        return $fh;
    }

    /**
     * Mutex global de jobs que escrevem no SQLite.
     *
     * É bloqueante de propósito: um job devido entra na fila em vez de ser
     * descartado enquanto outro perfil termina sua transação. No PostgreSQL o
     * mutex não é necessário, pois o MVCC resolve a concorrência de escritores.
     *
     * @return resource|null
     */
    private static function acquireWriterLock()
    {
        if (!Database::isSqlite()) {
            return fopen('php://temp', 'w+');
        }
        $dir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $path = $dir . '/jobs-writer.lock';
        $fh = @fopen($path, 'c+');
        if ($fh === false && is_file($path) && !is_writable($path)) {
            @unlink($path);
            $fh = @fopen($path, 'c+');
        }
        if ($fh === false) { return null; }
        @chmod($path, 0664);
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return null;
        }
        ftruncate($fh, 0);
        fwrite($fh, self::$currentJob . ':' . (string) getmypid());
        fflush($fh);
        return $fh;
    }

    private static function releaseLock($fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    private static function bumpSkipped(string $jobName, string $why): void
    {
        Database::run(
            'UPDATE job_state SET skipped_runs = skipped_runs + 1, updated_at = :u WHERE job_name = :n',
            [':u' => date('c'), ':n' => $jobName],
            'job_state.skip'
        );
        error_log(sprintf('[jobs] %s pulado (%s)', $jobName, $why));
    }

    /** Estado do disjuntor de um job. */
    public static function circuit(string $jobName): array
    {
        $st = Database::pdo()->prepare(
            'SELECT consecutive_failures, circuit_open_until, circuit_reason FROM job_state WHERE job_name = :n'
        );
        $st->execute([':n' => $jobName]);
        $row = $st->fetch() ?: [];
        $until = (int) ($row['circuit_open_until'] ?? 0);
        return [
            'open' => $until > time(),
            'seconds_left' => max(0, $until - time()),
            'reason' => (string) ($row['circuit_reason'] ?? ''),
            'consecutive_failures' => (int) ($row['consecutive_failures'] ?? 0),
        ];
    }

    /** Reabilita manualmente um job em circuito aberto (botão do painel). */
    public static function resetCircuit(string $jobName): void
    {
        Database::run(
            'UPDATE job_state SET circuit_open_until = 0, circuit_reason = "", consecutive_failures = 0, updated_at = :u
              WHERE job_name = :n',
            [':u' => date('c'), ':n' => $jobName],
            'job_state.circuit_reset'
        );
        Audit::log('job_circuit_reset', $jobName);
    }

    /** Limpa jobs presos em running por tempo demais. */
    public static function recoverStaleRunning(int $graceSeconds = 600): int
    {
        $graceSeconds = max(60, $graceSeconds);
        $now = time();
        $staleSince = $now - $graceSeconds;
        $affected = 0;
        Database::write(static function (PDO $pdo) use ($staleSince, $now, &$affected): void {
            $st = $pdo->prepare(
                'UPDATE job_state
                    SET running = 0,
                        running_since_epoch = 0,
                        current_step = "",
                        updated_at = :updated
                  WHERE running = 1
                    AND running_since_epoch > 0
                    AND running_since_epoch < :cut'
            );
            $st->execute([
                ':updated' => date('c', $now),
                ':cut' => $staleSince,
            ]);
            $affected = $st->rowCount();
        }, 'job_state.recover_stale');
        if ($affected > 0) {
            Audit::log('job_stale_recovered', sprintf('%d job(s) stale running limpo(s)', $affected), '-', 'job');
        }
        return $affected;
    }

    public static function states(): array
    {
        // Leitura de painel precisa ser estritamente read-only. Antes ela
        // chamava recoverStaleRunning(), um UPDATE que esperava o escritor
        // SQLite por até busy_timeout (30s), mesmo quando não havia linha stale.
        $rows = Database::pdo()->query('SELECT * FROM job_state ORDER BY job_name ASC')->fetchAll();
        $byName = [];
        foreach ($rows as $r) { $byName[$r['job_name']] = $r; }
        $out = [];
        foreach (self::CATALOG as $name => [$purpose, $interval]) {
            $row = $byName[$name] ?? [
                'job_name' => $name, 'purpose' => $purpose, 'interval_seconds' => $interval,
                'last_run_at' => '', 'last_run_epoch' => 0, 'last_status' => 'never',
                'last_duration_ms' => 0, 'last_processed' => 0, 'last_failed' => 0,
                'last_error' => '', 'next_run_epoch' => 0, 'total_runs' => 0,
                'total_failures' => 0, 'updated_at' => '',
            ];
            $row += [
                'current_step' => '', 'consecutive_failures' => 0, 'last_run_id' => '',
                'running' => 0, 'running_since_epoch' => 0, 'circuit_open_until' => 0,
                'circuit_reason' => '', 'skipped_runs' => 0, 'max_duration_ms' => 0,
            ];
            // Exibe estado stale como recuperado sem transformar GET/polling em
            // escrita. O próximo run() faz o UPSERT autoritativo normalmente.
            if ((int) $row['running'] === 1
                && (int) $row['running_since_epoch'] > 0
                && (time() - (int) $row['running_since_epoch']) >= 600) {
                $row['running'] = 0;
                $row['running_since_epoch'] = 0;
                $row['current_step'] = '';
                $row['stale_recovered_view'] = true;
            }
            $row['circuit_open'] = (int) $row['circuit_open_until'] > time();
            $row['late_seconds'] = (int) $row['next_run_epoch'] > 0
                ? max(0, time() - (int) $row['next_run_epoch']) : 0;
            $out[] = $row;
        }
        return $out;
    }

    public static function history(int $limit = 60, string $jobName = ''): array
    {
        $sql = 'SELECT * FROM job_runs';
        $params = [];
        if ($jobName !== '') {
            $sql .= ' WHERE job_name = :n';
            $params[':n'] = $jobName;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Só executa se já passou do intervalo (usado pelo tick único do cron). */
    public static function due(string $jobName, int $intervalOverride = 0): bool
    {
        $interval = $intervalOverride > 0 ? $intervalOverride : (int) (self::CATALOG[$jobName][1] ?? 60);
        $stmt = Database::pdo()->prepare(
            'SELECT last_run_epoch, circuit_open_until, running, running_since_epoch
               FROM job_state WHERE job_name = :n'
        );
        $stmt->execute([':n' => $jobName]);
        $row = $stmt->fetch() ?: [];
        $now = time();
        if ((int) ($row['circuit_open_until'] ?? 0) > $now) {
            return false;
        }
        // Job "running" travado (processo morto) libera depois de 10 min.
        if ((int) ($row['running'] ?? 0) === 1
            && ($now - (int) ($row['running_since_epoch'] ?? 0)) < 600) {
            return false;
        }
        $last = (int) ($row['last_run_epoch'] ?? 0);
        return (time() - $last) >= $interval;
    }
}
