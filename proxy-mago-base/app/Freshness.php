<?php

/**
 * Frescor de dado do painel (Fase 1.3).
 *
 * Problema real: o painel mostra numero bonito mesmo quando o cron parou, o
 * disjuntor abriu ou o LB nao responde. O operador olha "0 conexao" e acha que
 * esta tudo calmo -- quando na verdade o dado esta velho.
 *
 * Aqui a resposta de TODA API do painel carrega:
 *   data_age_ms   -> idade da fonte que alimenta aquela tela
 *   degraded      -> true quando a fonte esta atrasada / disjuntor aberto
 *   reasons       -> motivo curto para exibir na tela
 *   poll_after_ms -> intervalo que o front DEVE usar no proximo tick
 *
 * Tudo com micro-cache: frescor nao pode custar query pesada por tick.
 */
final class Freshness
{
    /** Jobs que alimentam cada tela do painel. */
    private const SOURCES = [
        'live'     => ['xui_sync_activity', 'consolidate_runtime'],
        'users'    => ['xui_sync_users', 'consolidate_runtime'],
        'sessions' => ['session_sweep', 'match_sessions'],
        'timeline' => ['match_sessions', 'consolidate_runtime'],
        'summary'  => ['consolidate_runtime', 'metrics_rollup_light'],
        'lb'       => ['lb_probe'],
        'nodes'    => ['lb_probe'],
        'routes'   => ['lb_rebalance', 'lb_autoroute'],
        'default'  => ['consolidate_runtime'],
    ];

    /** Piso e teto do intervalo de polling adaptativo (ms). */
    private const POLL_MIN_MS = 3000;
    private const POLL_MAX_MS = 30000;

    /** @return array<string,array{last_run_epoch:int,interval:int,circuit_open:bool,status:string}> */
    private static function jobState(): array
    {
        return Cache::remember('freshness-jobs', 3, static function (): array {
            $out = [];
            try {
                $rows = Database::pdo()->query(
                    'SELECT job_name, last_run_epoch, interval_seconds, last_status, circuit_open_until
                       FROM job_state'
                )->fetchAll();
            } catch (Throwable $e) {
                return [];
            }
            foreach ((array) $rows as $r) {
                $out[(string) $r['job_name']] = [
                    'last_run_epoch' => (int) $r['last_run_epoch'],
                    'interval' => max(5, (int) $r['interval_seconds']),
                    'circuit_open' => (int) ($r['circuit_open_until'] ?? 0) > time(),
                    'status' => (string) ($r['last_status'] ?? 'never'),
                ];
            }
            return $out;
        });
    }

    /**
     * Meta de frescor da view.
     *
     * @param int $queryMs custo real da consulta que acabou de rodar
     */
    public static function meta(string $view, int $queryMs = 0): array
    {
        $jobs = self::SOURCES[$view] ?? self::SOURCES['default'];
        $state = self::jobState();
        $now = time();

        $ageSeconds = 0;
        $degraded = false;
        $reasons = [];
        $slowest = 5;

        foreach ($jobs as $job) {
            $s = $state[$job] ?? null;
            if ($s === null || $s['last_run_epoch'] <= 0) {
                $degraded = true;
                $reasons[] = $job . ' nunca rodou';
                continue;
            }
            $age = max(0, $now - $s['last_run_epoch']);
            $ageSeconds = max($ageSeconds, $age);
            $slowest = max($slowest, $s['interval']);
            // Tolerancia: 3x o intervalo do job antes de gritar.
            if ($age > $s['interval'] * 3) {
                $degraded = true;
                $reasons[] = $job . ' atrasado ' . $age . 's';
            }
            if ($s['circuit_open']) {
                $degraded = true;
                $reasons[] = $job . ' com disjuntor aberto';
            }
        }

        return [
            'data_age_ms' => $ageSeconds * 1000,
            'data_age_seconds' => $ageSeconds,
            'degraded' => $degraded,
            'reasons' => $reasons,
            'sources' => $jobs,
            'poll_after_ms' => self::pollAfterMs($slowest, $queryMs, $degraded),
        ];
    }

    /**
     * Intervalo adaptativo (Fase 1.4).
     *
     * Regras: nunca mais rapido do que a fonte se atualiza (polling de 3s sobre
     * job de 60s so gasta CPU) e, se a consulta esta lenta, o front respira.
     */
    public static function pollAfterMs(int $sourceIntervalSeconds, int $queryMs, bool $degraded): int
    {
        $ms = max(self::POLL_MIN_MS, (int) ($sourceIntervalSeconds * 1000 * 0.8));
        if ($queryMs > 400) {
            $ms = max($ms, $queryMs * 8);
        }
        if ($degraded) {
            $ms = max($ms, 10000);
        }
        return (int) min(self::POLL_MAX_MS, $ms);
    }
}
