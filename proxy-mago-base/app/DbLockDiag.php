<?php

/**
 * Instrumentação de `database is locked` na trilha quente.
 *
 * O SQLite serve, no mesmo arquivo: tráfego ao vivo (cdn_sessions,
 * proxy_request_events), 15 jobs internos, telemetria de LB e polling do
 * painel. Quando um escritor perde a corrida, a mensagem crua do PDO
 * ("SQLSTATE[HY000]: General error: 5 database is locked") não diz QUAL
 * tabela, QUAL operação nem QUEM estava escrevendo junto.
 *
 * Esta classe existe só para isso: envolver a escrita, dizer o que travou e
 * fotografar os fluxos concorrentes no instante do lock. É diagnóstico, não
 * conserto: o conserto definitivo é o promote da trilha quente para PostgreSQL
 * (ver docs/RUNTIME_LOCK_SQLITE_2026-07-31.md).
 */
final class DbLockDiag
{
    /** @var array<int,array<string,mixed>> */
    private static array $events = [];

    public static function isLockError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'locked') || str_contains($msg, 'busy');
    }

    /** Registra um lock observado (retry ou falha final). */
    public static function note(string $table, string $op, string $tag, string $message, int $attempt = 1, bool $fatal = false): void
    {
        self::$events[] = [
            'ts' => date('c'),
            'table' => $table,
            'op' => $op,
            'tag' => $tag,
            'attempt' => $attempt,
            'fatal' => $fatal,
            'message' => trim($message),
            'flows' => self::concurrentFlows(),
        ];
        error_log(sprintf(
            '[db:lock] tabela=%s op=%s tag=%s tentativa=%d fatal=%s msg=%s',
            $table,
            $op,
            $tag,
            $attempt,
            $fatal ? 'sim' : 'nao',
            trim($message)
        ));
    }

    /**
     * Executa uma escrita idempotente com backoff e instrumentação de lock.
     *
     * @template T
     * @param callable():mixed $fn
     * @return mixed
     */
    public static function guard(callable $fn, string $table, string $op, string $tag = 'smoke', int $attempts = 8)
    {
        $delayUs = 25000;
        $last = null;
        for ($i = 1; $i <= max(1, $attempts); $i++) {
            try {
                return $fn();
            } catch (Throwable $e) {
                if (!self::isLockError($e)) {
                    throw $e;
                }
                $last = $e;
                self::note($table, $op, $tag, $e->getMessage(), $i, $i >= $attempts);
                if ($i >= $attempts) {
                    break;
                }
                usleep($delayUs + random_int(0, 15000));
                $delayUs = min($delayUs * 2, 500000);
            }
        }
        throw new RuntimeException(
            'database is locked em ' . $table . ' (' . $op . ') após ' . $attempts . " tentativas\n" . self::report(),
            0,
            $last instanceof Throwable ? $last : null
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function events(): array
    {
        return self::$events;
    }

    public static function hadLock(): bool
    {
        return self::$events !== [];
    }

    /** Relatório legível: o que travou e quem estava escrevendo junto. */
    public static function report(): string
    {
        if (self::$events === []) {
            return '  (nenhum lock observado)';
        }
        $lines = [];
        foreach (self::$events as $ev) {
            $lines[] = sprintf(
                '  lock: tabela=%s op=%s tag=%s tentativa=%d fatal=%s',
                $ev['table'],
                $ev['op'],
                $ev['tag'],
                $ev['attempt'],
                $ev['fatal'] ? 'sim' : 'nao'
            );
            foreach ((array) $ev['flows'] as $k => $v) {
                $lines[] = sprintf('        %-16s %s', $k, is_array($v) ? implode(', ', $v) : (string) $v);
            }
        }
        return implode("\n", $lines);
    }

    /** Foto dos fluxos concorrentes: WAL, locks de job e processos PHP. */
    public static function concurrentFlows(): array
    {
        $root = dirname(__DIR__);
        $dbPath = (string) Config::get('db_path');
        $locks = [];
        foreach (glob($root . '/storage/cache/*.lock') ?: [] as $lock) {
            $age = time() - (int) @filemtime($lock);
            $locks[] = basename($lock) . '(' . $age . 's)';
        }
        $procs = [];
        $out = @shell_exec('ps -eo pid,etimes,args 2>/dev/null | grep -E "php|jobs-run|nginx" | grep -v grep');
        foreach (explode("\n", (string) $out) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $procs[] = substr($line, 0, 120);
            }
        }
        return [
            'db_bytes' => (string) (int) @filesize($dbPath),
            'wal_bytes' => (string) (int) @filesize($dbPath . '-wal'),
            'shm_bytes' => (string) (int) @filesize($dbPath . '-shm'),
            'job_locks' => $locks === [] ? '-' : implode(', ', $locks),
            'db_retries' => (string) (class_exists('Database') ? Database::lockRetries() : 0),
            'procs' => $procs === [] ? '-' : implode(' | ', array_slice($procs, 0, 6)),
        ];
    }
}
