<?php

/**
 * Catálogo de DIRECT SOURCE pela verdade do banco do XUI + consolidação.
 *
 * Duas verdades convivem nesta CDN:
 *
 *   DB      -> `streams.direct_source = 1` e `streams.stream_source` (URL externa
 *              já cadastrada no XUI). Chega aqui pelo job `xui_sync_streams`,
 *              é parseada pelo job `direct_enrich` e vira `xui_streams_cache`.
 *   RUNTIME -> hops realmente seguidos pelo proxy (`direct_source_hops`), ou
 *              seja, o host que o cliente consumiu de verdade por dentro.
 *
 * `direct_consolidate` cruza as duas em `direct_stream_state`, que é a fonte
 * principal de verdade operacional do painel. O MySQL do XUI é complementar e
 * NUNCA é consultado no caminho crítico do stream — só por job.
 */
final class DirectCatalog
{
    /** Modos de origem consolidados. */
    public const MODES = [
        'db_only'      => 'Direct cadastrado no XUI, ainda sem consumo observado',
        'runtime_only' => 'Redirect observado em runtime sem flag no XUI',
        'db_runtime'   => 'Direct cadastrado no XUI e confirmado em runtime',
        'none'         => 'Sem direct source',
    ];

    /**
     * Job `direct_enrich`: parseia `stream_source_raw` já espelhado e preenche
     * host/parse_status/source_mode em `xui_streams_cache`.
     */
    public static function enrich(array &$stats): void
    {
        $pdo = Database::pdo();
        $batchSize = 2000;
        $sel = $pdo->prepare(
            'SELECT stream_id, direct_source, stream_source_raw, parse_status, enriched_epoch
               FROM xui_streams_cache
              WHERE stream_id > :after
           ORDER BY stream_id ASC
              LIMIT ' . $batchSize
        );
        $upd = $pdo->prepare(
            'UPDATE xui_streams_cache
                SET direct_host_detected = :h, direct_hosts_json = :hj, urls_count = :c,
                    source_mode = :m, parse_status = :st, parse_error = :err, enriched_epoch = :ep
              WHERE stream_id = :id'
        );
        $now = time();
        $byStatus = ['ok' => 0, 'empty' => 0, 'no_host' => 0, 'bad_json' => 0, 'unsupported' => 0];
        $directDb = 0;
        $after = 0;

        while (true) {
            $sel->execute([':after' => $after]);
            $rows = $sel->fetchAll();
            if (!$rows) {
                break;
            }
            $pdo->beginTransaction();
            try {
                foreach ($rows as $r) {
                    $after = (int) $r['stream_id'];
                    $flag = (int) $r['direct_source'];
                    $parsed = DirectSourceParser::parse((string) $r['stream_source_raw']);
                    $mode = DirectSourceParser::sourceMode($flag, $parsed['host'], $parsed['status']);
                    $upd->execute([
                        ':h' => $parsed['host'],
                        ':hj' => json_encode($parsed['hosts'], JSON_UNESCAPED_SLASHES) ?: '[]',
                        ':c' => $parsed['count'],
                        ':m' => $mode,
                        ':st' => $parsed['status'],
                        ':err' => substr($parsed['error'], 0, 200),
                        ':ep' => $now,
                        ':id' => (int) $r['stream_id'],
                    ]);
                    if (isset($byStatus[$parsed['status']])) { $byStatus[$parsed['status']]++; }
                    if ($flag === 1) { $directDb++; }
                    $stats['processed']++;
                    if ($flag === 1 && $parsed['status'] !== 'ok' && $parsed['status'] !== 'empty') {
                        $stats['failed']++;
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            unset($rows);
        }

        $stats['details'] = ['direct_db' => $directDb, 'parse' => $byStatus];
    }

    /**
     * Job `direct_consolidate`: junta DB + runtime em `direct_stream_state`,
     * define host efetivo, modo de origem e consistência, e abre divergências
     * específicas de direct source.
     */
    public static function consolidate(array &$stats): void
    {
        $pdo = Database::pdo();
        $now = time();
        $window = $now - 3600; // 1h de runtime para decidir o host efetivo

        // Runtime: host final mais recente por stream, com hits e falhas.
        $runtime = [];
        $rt = $pdo->query(
            "SELECT stream_id,
                    SUM(CASE WHEN outcome = 'followed' THEN 1 ELSE 0 END) AS hits,
                    SUM(CASE WHEN outcome <> 'followed' THEN 1 ELSE 0 END) AS failures,
                    MAX(ts_epoch) AS last_epoch
               FROM direct_source_hops
              WHERE ts_epoch >= " . $window . " AND off_origin = 1 AND stream_id > 0
              GROUP BY stream_id"
        )->fetchAll();
        foreach ($rt as $r) {
            $runtime[(int) $r['stream_id']] = [
                'hits' => (int) $r['hits'],
                'failures' => (int) $r['failures'],
                'last' => (int) $r['last_epoch'],
                'host' => '',
            ];
        }
        if ($runtime !== []) {
            $ids = implode(',', array_map('intval', array_keys($runtime)));
            $hosts = $pdo->query(
                "SELECT stream_id, to_host, MAX(ts_epoch) AS last_epoch
                   FROM direct_source_hops
                  WHERE ts_epoch >= " . $window . " AND off_origin = 1 AND outcome = 'followed'
                    AND to_host <> '' AND stream_id IN (" . $ids . ")
                  GROUP BY stream_id, to_host"
            )->fetchAll();
            foreach ($hosts as $h) {
                $runtime[(int) $h['stream_id']]['host'] = (string) $h['to_host'];
            }
        }

        // DB: tudo que o XUI marca como direct source (ou que já teve runtime).
        $dbRows = $pdo->query(
            'SELECT stream_id, stream_display_name, type, direct_source, direct_proxy,
                    direct_host_detected, parse_status, urls_count, synced_at
               FROM xui_streams_cache
              WHERE direct_source = 1'
        )->fetchAll();

        $states = [];
        foreach ($dbRows as $r) {
            $sid = (int) $r['stream_id'];
            $states[$sid] = [
                'name' => (string) $r['stream_display_name'],
                'type' => (string) $r['type'],
                'flag_db' => 1,
                'proxy' => (int) $r['direct_proxy'],
                'host_db' => (string) $r['direct_host_detected'],
                'parse' => (string) $r['parse_status'],
                'urls' => (int) $r['urls_count'],
                'db_epoch' => strtotime((string) $r['synced_at']) ?: 0,
            ];
        }
        foreach ($runtime as $sid => $_) {
            if (!isset($states[$sid])) {
                $states[$sid] = [
                    'name' => '', 'type' => '', 'flag_db' => 0, 'proxy' => 0,
                    'host_db' => '', 'parse' => 'unknown', 'urls' => 0, 'db_epoch' => 0,
                ];
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO direct_stream_state
               (stream_id, stream_name, stream_type, direct_flag_db, direct_proxy,
                direct_host_from_db, direct_host_runtime, direct_host_effective,
                direct_origin_mode, direct_consistency, parse_status, urls_count,
                runtime_hits, runtime_failures, runtime_last_epoch, db_synced_epoch,
                updated_at, updated_epoch)
             VALUES (:id,:n,:t,:fdb,:px,:hdb,:hrt,:heff,:mode,:cons,:parse,:urls,:hits,:fails,:rlast,:dbe,:ua,:ue)
             ON CONFLICT(stream_id) DO UPDATE SET
               stream_name=excluded.stream_name, stream_type=excluded.stream_type,
               direct_flag_db=excluded.direct_flag_db, direct_proxy=excluded.direct_proxy,
               direct_host_from_db=excluded.direct_host_from_db,
               direct_host_runtime=excluded.direct_host_runtime,
               direct_host_effective=excluded.direct_host_effective,
               direct_origin_mode=excluded.direct_origin_mode,
               direct_consistency=excluded.direct_consistency,
               parse_status=excluded.parse_status, urls_count=excluded.urls_count,
               runtime_hits=excluded.runtime_hits, runtime_failures=excluded.runtime_failures,
               runtime_last_epoch=excluded.runtime_last_epoch,
               db_synced_epoch=excluded.db_synced_epoch,
               updated_at=excluded.updated_at, updated_epoch=excluded.updated_epoch'
        );

        $counters = [
            'db_only' => 0, 'runtime_only' => 0, 'db_runtime' => 0,
            'mismatch' => 0, 'host_missing' => 0, 'parse_error' => 0,
        ];

        $pdo->beginTransaction();
        try {
            foreach ($states as $sid => $s) {
                $rt = $runtime[$sid] ?? ['hits' => 0, 'failures' => 0, 'last' => 0, 'host' => ''];
                $hostDb = (string) $s['host_db'];
                $hostRt = (string) $rt['host'];
                // Runtime existe se houve QUALQUER hop off-origin: seguido, falho ou bloqueado.
                $mode = self::originMode((int) $s['flag_db'], $hostRt !== '' || $rt['hits'] > 0 || $rt['failures'] > 0);
                // Host efetivo: o runtime manda, porque é o que o cliente consumiu.
                $effective = $hostRt !== '' ? $hostRt : $hostDb;
                $consistency = self::consistency((int) $s['flag_db'], $hostDb, $hostRt, (string) $s['parse'], (int) $rt['hits']);

                $ins->execute([
                    ':id' => $sid, ':n' => substr((string) $s['name'], 0, 200), ':t' => (string) $s['type'],
                    ':fdb' => (int) $s['flag_db'], ':px' => (int) $s['proxy'],
                    ':hdb' => $hostDb, ':hrt' => $hostRt, ':heff' => $effective,
                    ':mode' => $mode, ':cons' => $consistency, ':parse' => (string) $s['parse'],
                    ':urls' => (int) $s['urls'], ':hits' => (int) $rt['hits'], ':fails' => (int) $rt['failures'],
                    ':rlast' => (int) $rt['last'], ':dbe' => (int) $s['db_epoch'],
                    ':ua' => date('c', $now), ':ue' => $now,
                ]);

                if (isset($counters[$mode])) { $counters[$mode]++; }
                $stats['processed']++;

                // Divergências específicas de direct source (escopo = stream).
                switch ($consistency) {
                    case 'mismatch':
                        $counters['mismatch']++;
                        self::raiseStream($sid, 'direct_db_runtime_mismatch', 'warn',
                            sprintf('DB aponta %s, runtime consumiu %s', $hostDb ?: '-', $hostRt ?: '-'),
                            ['host_db' => $hostDb, 'host_runtime' => $hostRt, 'hits' => (int) $rt['hits']]);
                        break;
                    case 'host_missing':
                        $counters['host_missing']++;
                        self::raiseStream($sid, 'direct_host_missing', 'warn',
                            'Stream marcado como direct source no XUI mas sem host final conhecido',
                            ['parse_status' => (string) $s['parse'], 'urls' => (int) $s['urls']]);
                        break;
                    case 'parse_error':
                        $counters['parse_error']++;
                        self::raiseStream($sid, 'direct_parse_error', 'warn',
                            'stream_source do XUI em formato não suportado; host de DB indisponível',
                            ['parse_status' => (string) $s['parse']]);
                        break;
                }
                if ($mode === 'runtime_only') {
                    self::raiseStream($sid, 'direct_runtime_without_db_flag', 'info',
                        'Redirect para fora da origem sem direct_source=1 no XUI (origem mudou ou sync atrasado)',
                        ['host_runtime' => $hostRt, 'hits' => (int) $rt['hits']]);
                }
                if ($mode === 'db_only' && (int) $s['flag_db'] === 1 && $hostDb !== '') {
                    self::raiseStream($sid, 'direct_db_flag_without_runtime', 'info',
                        'Direct cadastrado no XUI sem nenhum consumo observado pela CDN na última hora',
                        ['host_db' => $hostDb]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $counters['orphan_sessions'] = self::detectOrphanSessions();
        self::rollupHosts($now);
        self::backfillSessions($now);

        $stats['failed'] += $counters['mismatch'] + $counters['parse_error'];
        $stats['details'] = $counters;
    }

    /** Sessões locais marcadas como direct sem stream conhecido pela CDN. */
    private static function detectOrphanSessions(): int
    {
        $pdo = Database::pdo();
        $rows = $pdo->query(
            "SELECT session_key, username, direct_host_effective, direct_host_runtime, stream_id
               FROM cdn_sessions
              WHERE direct_source = 1 AND status = 'active'
                AND (stream_id = 0 OR stream_id NOT IN (SELECT stream_id FROM direct_stream_state))
              LIMIT 200"
        )->fetchAll();
        foreach ($rows as $r) {
            Divergence::raise((string) $r['username'], 'direct_orphan_session', 'info',
                'Sessão direct source ativa sem stream correspondente no catálogo da CDN',
                [
                    'session' => (string) $r['session_key'],
                    'host' => (string) ($r['direct_host_effective'] ?: $r['direct_host_runtime']),
                    'stream_id' => (int) $r['stream_id'],
                ],
                'session:' . substr((string) $r['session_key'], 0, 16),
                (int) $r['stream_id']
            );
        }
        return count($rows);
    }

    /** Rollup de 5min por host final (hits, falhas, usuários, streams). */
    private static function rollupHosts(int $now): void
    {
        $pdo = Database::pdo();
        $bucket = (int) (floor($now / 300) * 300);
        $rows = $pdo->query(
            "SELECT to_host AS host,
                    SUM(CASE WHEN outcome = 'followed' THEN 1 ELSE 0 END) AS hits,
                    SUM(CASE WHEN outcome <> 'followed' THEN 1 ELSE 0 END) AS failures,
                    COUNT(DISTINCT username) AS users,
                    COUNT(DISTINCT stream_id) AS streams
               FROM direct_source_hops
              WHERE ts_epoch >= " . $bucket . " AND ts_epoch < " . ($bucket + 300) . "
                AND off_origin = 1 AND to_host <> ''
              GROUP BY to_host"
        )->fetchAll();

        $ins = $pdo->prepare(
            "INSERT INTO direct_host_rollup (host, bucket_epoch, direct_mode, hits, failures, users, streams, updated_epoch)
             VALUES (:h,:b,'runtime',:hits,:f,:u,:s,:ue)
             ON CONFLICT(host, bucket_epoch, direct_mode) DO UPDATE SET
               hits=excluded.hits, failures=excluded.failures, users=excluded.users,
               streams=excluded.streams, updated_epoch=excluded.updated_epoch"
        );
        foreach ($rows as $r) {
            $ins->execute([
                ':h' => (string) $r['host'], ':b' => $bucket, ':hits' => (int) $r['hits'],
                ':f' => (int) $r['failures'], ':u' => (int) $r['users'], ':s' => (int) $r['streams'],
                ':ue' => $now,
            ]);
        }
        // Hosts vindos do DB, sem runtime: contabilizados como catálogo.
        $dbRows = $pdo->query(
            "SELECT direct_host_from_db AS host, COUNT(*) AS streams FROM direct_stream_state
              WHERE direct_host_from_db <> '' GROUP BY direct_host_from_db"
        )->fetchAll();
        $insDb = $pdo->prepare(
            "INSERT INTO direct_host_rollup (host, bucket_epoch, direct_mode, hits, failures, users, streams, updated_epoch)
             VALUES (:h,:b,'db',0,0,0,:s,:ue)
             ON CONFLICT(host, bucket_epoch, direct_mode) DO UPDATE SET
               streams=excluded.streams, updated_epoch=excluded.updated_epoch"
        );
        foreach ($dbRows as $r) {
            $insDb->execute([':h' => (string) $r['host'], ':b' => $bucket, ':s' => (int) $r['streams'], ':ue' => $now]);
        }
        $pdo->exec('DELETE FROM direct_host_rollup WHERE bucket_epoch < ' . ($now - 7 * 86400));
    }

    /**
     * Sessões ativas de streams direct cadastrados no DB que ainda não tinham
     * marcação (o consumo pode nem gerar redirect: a URL já é externa).
     */
    private static function backfillSessions(int $now): void
    {
        Database::pdo()->exec(
            "UPDATE cdn_sessions
                SET direct_source = 1,
                    uptime_start_epoch = CASE
                        WHEN uptime_start_epoch = 0 AND direct_first_epoch > 0 THEN direct_first_epoch
                        WHEN uptime_start_epoch = 0 THEN started_epoch
                        ELSE uptime_start_epoch
                    END,
                    direct_host_db = COALESCE((SELECT direct_host_from_db FROM direct_stream_state d
                                                WHERE d.stream_id = cdn_sessions.stream_id), ''),
                    direct_host_effective = CASE WHEN direct_host_runtime <> '' THEN direct_host_runtime
                        ELSE COALESCE((SELECT direct_host_from_db FROM direct_stream_state d
                                        WHERE d.stream_id = cdn_sessions.stream_id), '') END,
                    direct_mode = CASE WHEN direct_host_runtime <> '' THEN 'db_runtime' ELSE 'db_only' END,
                    direct_first_epoch = CASE WHEN direct_first_epoch = 0 THEN " . $now . " ELSE direct_first_epoch END,
                    direct_last_epoch = " . $now . "
              WHERE status = 'active' AND stream_id > 0
                AND stream_id IN (SELECT stream_id FROM direct_stream_state WHERE direct_flag_db = 1)"
        );
    }

    private static function originMode(int $flagDb, bool $hasRuntime): string
    {
        if ($flagDb === 1 && $hasRuntime) { return 'db_runtime'; }
        if ($flagDb === 1) { return 'db_only'; }
        return $hasRuntime ? 'runtime_only' : 'none';
    }

    /**
     * consistent   -> DB e runtime concordam (ou só existe uma fonte coerente)
     * mismatch     -> DB diz um host, runtime consumiu outro
     * host_missing -> flag de direct sem host em nenhuma das fontes
     * parse_error  -> stream_source ilegível
     * runtime_only -> runtime sem respaldo no DB
     */
    private static function consistency(int $flagDb, string $hostDb, string $hostRt, string $parse, int $hits): string // phpcs:ignore
    {
        if ($flagDb === 1 && in_array($parse, ['bad_json', 'unsupported'], true)) { return 'parse_error'; }
        if ($flagDb === 1 && $hostDb === '' && $hostRt === '') { return 'host_missing'; }
        if ($hostDb !== '' && $hostRt !== '' && !self::sameHost($hostDb, $hostRt)) { return 'mismatch'; }
        if ($flagDb === 0) { return 'runtime_only'; }
        return 'consistent';
    }

    /** Compara hosts ignorando www. e o domínio registrável mais próximo. */
    public static function sameHost(string $a, string $b): bool
    {
        $norm = static function (string $h): string {
            $h = strtolower(trim($h));
            return preg_replace('/^www\./', '', $h) ?? $h;
        };
        $a = $norm($a); $b = $norm($b);
        if ($a === $b) { return true; }
        $tail = static function (string $h): string {
            $p = explode('.', $h);
            return count($p) >= 2 ? implode('.', array_slice($p, -2)) : $h;
        };
        return $tail($a) === $tail($b);
    }

    private static function raiseStream(int $streamId, string $kind, string $severity, string $cause, array $data): void
    {
        Divergence::raise('-', $kind, $severity, $cause, $data + ['stream_id' => $streamId], 'stream:' . $streamId, $streamId);
    }

    // ------------------------------------------------------------------
    // Leituras do painel (todas em SQLite local, nunca no MySQL do XUI)
    // ------------------------------------------------------------------

    /** Verdade do DB para UM stream — usada no caminho do proxy (1 SELECT por PK). */
    public static function dbHostFor(int $streamId): array
    {
        if ($streamId <= 0) { return ['direct' => 0, 'host' => '', 'mode' => 'local', 'parse' => 'unknown']; }
        try {
            $st = Database::pdo()->prepare(
                'SELECT direct_source, direct_host_detected, source_mode, parse_status
                   FROM xui_streams_cache WHERE stream_id = :id'
            );
            $st->execute([':id' => $streamId]);
            $row = $st->fetch();
            if (!$row) { return ['direct' => 0, 'host' => '', 'mode' => 'unknown', 'parse' => 'unknown']; }
            return [
                'direct' => (int) $row['direct_source'],
                'host' => (string) $row['direct_host_detected'],
                'mode' => (string) $row['source_mode'],
                'parse' => (string) $row['parse_status'],
            ];
        } catch (Throwable $e) {
            return ['direct' => 0, 'host' => '', 'mode' => 'unknown', 'parse' => 'unknown'];
        }
    }

    /** @return array<string,int> resumo do catálogo de direct source */
    public static function summary(): array
    {
        // Catálogo com ~484k streams: contar tudo a cada poll travava o painel.
        // O catálogo só muda no sync do XUI, então 30s de cache não atrasa nada
        // que o operador precise ver em tempo real.
        return Cache::remember('direct_summary', 30, static fn(): array => self::summaryFresh());
    }

    /** @return array<string,int> */
    public static function summaryFresh(): array
    {
        $pdo = Database::pdo();
        // Uma varredura por tabela em vez de 3 + 3: com 484k streams cada
        // COUNT separado custava centenas de ms no painel.
        $s = $pdo->query(
            "SELECT COUNT(*) AS streams_db,
                    SUM(CASE WHEN parse_status = 'ok' THEN 1 ELSE 0 END) AS streams_parsed,
                    SUM(CASE WHEN parse_status IN ('bad_json','unsupported','no_host') THEN 1 ELSE 0 END) AS parse_errors
               FROM xui_streams_cache WHERE direct_source = 1"
        )->fetch() ?: [];
        $out = [
            'streams_db' => (int) ($s['streams_db'] ?? 0),
            'streams_parsed' => (int) ($s['streams_parsed'] ?? 0),
            'parse_errors' => (int) ($s['parse_errors'] ?? 0),
            'db_only' => 0, 'runtime_only' => 0, 'db_runtime' => 0, 'mismatch' => 0,
        ];
        $mismatch = 0;
        foreach ($pdo->query(
            "SELECT direct_origin_mode AS m, COUNT(*) AS c,
                    SUM(CASE WHEN direct_consistency = 'mismatch' THEN 1 ELSE 0 END) AS mm
               FROM direct_stream_state GROUP BY direct_origin_mode"
        )->fetchAll() as $r) {
            if (isset($out[(string) $r['m']])) { $out[(string) $r['m']] = (int) $r['c']; }
            $mismatch += (int) $r['mm'];
        }
        $out['mismatch'] = $mismatch;
        $out['hosts_effective'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM (SELECT 1 FROM direct_stream_state WHERE direct_host_effective <> '' GROUP BY direct_host_effective) t"
        )->fetchColumn();
        return $out;
    }

    /** @return array<int,array<string,mixed>> catálogo consolidado para a tabela do painel */
    public static function streams(array $filters = [], int $limit = 100): array
    {
        $sql = 'SELECT * FROM direct_stream_state WHERE 1=1';
        $params = [];
        if (!empty($filters['mode'])) { $sql .= ' AND direct_origin_mode = :m'; $params[':m'] = $filters['mode']; }
        if (!empty($filters['consistency'])) { $sql .= ' AND direct_consistency = :c'; $params[':c'] = $filters['consistency']; }
        if (!empty($filters['host'])) { $sql .= ' AND (direct_host_effective LIKE :h OR direct_host_from_db LIKE :h)'; $params[':h'] = '%' . $filters['host'] . '%'; }
        $sql .= ' ORDER BY runtime_last_epoch DESC, runtime_hits DESC LIMIT ' . max(1, min(500, $limit));
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> top hosts por modo (runtime + db) */
    public static function topHosts(int $minutes = 15, int $limit = 10): array
    {
        $since = (int) (floor((time() - $minutes * 60) / 300) * 300);
        return Database::pdo()->query(
            'SELECT host, direct_mode, SUM(hits) AS hits, SUM(failures) AS failures,
                    MAX(users) AS users, MAX(streams) AS streams, MAX(bucket_epoch) AS last_bucket
               FROM direct_host_rollup
              WHERE bucket_epoch >= ' . $since . '
              GROUP BY host, direct_mode
              ORDER BY hits DESC, streams DESC LIMIT ' . max(1, min(50, $limit))
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> falhas por host final */
    public static function failuresByHost(int $minutes = 60, int $limit = 20): array
    {
        $since = time() - ($minutes * 60);
        return Database::pdo()->query(
            "SELECT to_host AS host, COUNT(*) AS failures, MAX(ts) AS last_seen,
                    COUNT(DISTINCT username) AS users, COUNT(DISTINCT stream_id) AS streams
               FROM direct_source_hops
              WHERE ts_epoch >= " . $since . " AND outcome <> 'followed'
              GROUP BY to_host ORDER BY failures DESC LIMIT " . max(1, min(100, $limit))
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> usuários com direct ativo agora */
    public static function activeUsers(int $limit = 50): array
    {
        $now = time();
        return Database::pdo()->query(
            "SELECT username, COUNT(*) AS sessions,
                    STRING_AGG(DISTINCT direct_host_effective, ',') AS hosts,
                    MAX(direct_last_epoch) AS last_epoch,
                    STRING_AGG(DISTINCT direct_mode, ',') AS modes,
                    SUM(direct_failures) AS failures
               FROM cdn_sessions
              WHERE status = 'active' AND direct_source = 1
                AND (last_seen_epoch + idle_timeout) >= " . $now . "
              GROUP BY username ORDER BY sessions DESC, last_epoch DESC LIMIT " . max(1, min(200, $limit))
        )->fetchAll();
    }

    public static function forStream(int $streamId): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM direct_stream_state WHERE stream_id = :id');
        $st->execute([':id' => $streamId]);
        $row = $st->fetch();
        return $row ?: null;
    }
}
