<?php

/**
 * Veredito por HOST FINAL de direct source.
 *
 * Motivo de existir: a falha de série tem TRÊS causas diferentes e o painel
 * precisava separar as três, senão todo problema vira "bug do proxy":
 *
 *   catalogo_api -> o XUI devolveu catálogo torto (sem seasons, sem stream_source,
 *                   parse_status ruim). Culpa da API/catálogo, não do host.
 *   host_final   -> o host externo do direct source recusa/ignora o fetch da CDN
 *                   (401/403/451, sem resposta, 5xx). Culpa do host externo.
 *   sessao       -> hop seguido com sucesso mas sessão não consolidou.
 *
 * Só LEITURA de tabelas já existentes (`direct_source_hops`, `direct_stream_state`).
 * Nada aqui entra no caminho quente do player: é consumo de painel/job.
 */
final class DirectHostHealth
{
    public const VERDICTS = [
        'ok'            => 'Host final aceita o fetch da CDN',
        'flaky'         => 'Host final responde, mas falha parte das vezes',
        'blocked'       => 'Host final recusa a CDN (401/403/451)',
        'unreachable'   => 'Host final não respondeu (timeout/DNS/conexão)',
        'degraded'      => 'Host final com erro do lado dele (5xx)',
        'catalog_stale' => 'Host final responde 404/410: conteúdo saiu de lá',
        'unknown'       => 'Sem amostra suficiente na janela',
    ];

    /** @return array<int,array<string,mixed>> hosts finais com veredito e culpa */
    public static function hosts(int $minutes = 60, int $limit = 40): array
    {
        $since = time() - max(1, $minutes) * 60;
        $limit = max(1, min(200, $limit));
        $key = 'direct_host_health_' . $minutes . '_' . $limit;

        return Cache::remember($key, 10, static function () use ($since, $limit): array {
            $hostExpr = "COALESCE(NULLIF(final_host, ''), to_host)";
            $sql = 'SELECT ' . $hostExpr . ' AS host,
                        COUNT(*) AS hops,
                        SUM(CASE WHEN outcome = \'followed\' THEN 1 ELSE 0 END) AS ok_hops,
                        SUM(CASE WHEN outcome <> \'followed\' THEN 1 ELSE 0 END) AS fail_hops,
                        SUM(CASE WHEN status IN (401,403,451) THEN 1 ELSE 0 END) AS denied,
                        SUM(CASE WHEN status IN (404,410) THEN 1 ELSE 0 END) AS missing,
                        SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) AS upstream_err,
                        SUM(CASE WHEN status = 0 AND outcome <> \'followed\' THEN 1 ELSE 0 END) AS no_answer,
                        COUNT(DISTINCT username) AS users,
                        COUNT(DISTINCT stream_id) AS streams,
                        MAX(ts_epoch) AS last_epoch
                   FROM direct_source_hops
                  WHERE ts_epoch >= :since AND off_origin = 1 AND ' . $hostExpr . ' <> \'\'
               GROUP BY host
               ORDER BY fail_hops DESC, hops DESC
                  LIMIT ' . $limit;

            $st = Database::pdo()->prepare($sql);
            $st->execute([':since' => $since]);
            $out = [];
            foreach ($st->fetchAll() as $row) {
                $out[] = self::decorate($row);
            }
            return $out;
        });
    }

    /**
     * Resumo por veredito, para KPI leve do painel.
     * @return array<string,int>
     */
    public static function summary(int $minutes = 60): array
    {
        $counts = array_fill_keys(array_keys(self::VERDICTS), 0);
        foreach (self::hosts($minutes, 200) as $row) {
            $v = (string) $row['verdict'];
            if (isset($counts[$v])) {
                $counts[$v]++;
            }
        }
        return $counts;
    }

    /**
     * Triagem de um stream específico (o botão "por que essa série não abre?").
     * @return array<string,mixed>
     */
    public static function triageStream(int $streamId): array
    {
        $db = DirectCatalog::dbHostFor($streamId);
        $parse = (string) ($db['parse'] ?? '');
        $host = (string) ($db['host'] ?? '');

        if ($parse !== '' && !in_array($parse, ['ok', 'empty'], true)) {
            return self::verdictOut('catalogo_api', 'unknown', $host,
                'catálogo do XUI inconsistente (parse_status=' . $parse . ')');
        }

        $state = null;
        foreach (self::hosts(1440, 200) as $row) {
            if ($host !== '' && DirectCatalog::sameHost((string) $row['host'], $host)) {
                $state = $row;
                break;
            }
        }
        if ($state === null) {
            $st = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM direct_source_hops WHERE stream_id = :id'
            );
            $st->execute([':id' => $streamId]);
            $hops = (int) $st->fetchColumn();
            return $hops > 0
                ? self::verdictOut('sessao', 'ok', $host, 'hops seguidos, mas sem host final consolidado')
                : self::verdictOut('catalogo_api', 'unknown', $host, 'nenhum hop observado nesta janela');
        }

        return self::verdictOut((string) $state['blame'], (string) $state['verdict'], (string) $state['host'],
            (string) $state['explain'], $state);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decorate(array $row): array
    {
        $hops = max(1, (int) $row['hops']);
        $fail = (int) $row['fail_hops'];
        $denied = (int) $row['denied'];
        $missing = (int) $row['missing'];
        $srvErr = (int) $row['upstream_err'];
        $noAnswer = (int) $row['no_answer'];
        $failRate = (int) round(($fail / $hops) * 100);

        if ($hops < 3 && $fail === 0) {
            $verdict = 'unknown';
        } elseif ($denied >= max(1, (int) ceil($fail * 0.5)) && $denied > 0) {
            $verdict = 'blocked';
        } elseif ($noAnswer > 0 && $noAnswer >= $srvErr && $noAnswer >= $missing && $failRate >= 20) {
            $verdict = 'unreachable';
        } elseif ($srvErr > 0 && $srvErr >= $missing && $failRate >= 20) {
            $verdict = 'degraded';
        } elseif ($missing > 0 && $failRate >= 20) {
            $verdict = 'catalog_stale';
        } elseif ($failRate >= 10) {
            $verdict = 'flaky';
        } else {
            $verdict = 'ok';
        }

        $blame = in_array($verdict, ['blocked', 'unreachable', 'degraded', 'flaky'], true)
            ? 'host_final'
            : ($verdict === 'catalog_stale' ? 'catalogo_api' : 'nenhum');

        $row['host'] = (string) $row['host'];
        $row['fail_rate'] = $failRate;
        $row['verdict'] = $verdict;
        $row['verdict_label'] = self::VERDICTS[$verdict];
        $row['blame'] = $blame;
        $row['explain'] = self::explain($verdict, $failRate, $denied, $noAnswer, $srvErr, $missing);
        return $row;
    }

    private static function explain(
        string $verdict,
        int $failRate,
        int $denied,
        int $noAnswer,
        int $srvErr,
        int $missing
    ): string {
        switch ($verdict) {
            case 'blocked':
                return $denied . ' negativas 401/403/451 — host externo barra a CDN, não é falha do proxy';
            case 'unreachable':
                return $noAnswer . ' tentativas sem resposta — timeout/DNS/rota até o host externo';
            case 'degraded':
                return $srvErr . ' respostas 5xx do host externo (' . $failRate . '% de falha)';
            case 'catalog_stale':
                return $missing . ' respostas 404/410 — conteúdo mudou de lugar; corrigir catálogo no XUI';
            case 'flaky':
                return $failRate . '% de falha na janela — instável, mas entregando';
            case 'unknown':
                return 'amostra pequena na janela; sem veredito confiável';
            default:
                return 'entregando normalmente (' . $failRate . '% de falha)';
        }
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function verdictOut(
        string $blame,
        string $verdict,
        string $host,
        string $explain,
        array $extra = []
    ): array {
        return [
            'blame' => $blame,
            'verdict' => $verdict,
            'verdict_label' => self::VERDICTS[$verdict] ?? $verdict,
            'host' => $host,
            'explain' => $explain,
            'sample' => $extra,
        ];
    }
}
