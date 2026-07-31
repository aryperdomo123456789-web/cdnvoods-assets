<?php

/**
 * Núcleo de observabilidade do restreamento.
 *
 * - matchSessions(): cruza proxy_request_events com xui_activity_now_cache
 * - consolidate(): monta proxy_user_runtime (visão ao vivo do painel)
 * - detectInconsistencies(): swap de credencial, acima do limite, sessão órfã
 * - cleanup()/repair(): manutenção auditável
 * - consultas de leitura do painel (live, por usuário, IP, host, player)
 */
final class RestreamRuntime
{
    public const ACTIVE_WINDOW = 90;   // segundos para considerar "ao vivo"
    /** Idade máxima aceitável do rollup leve antes de recontar (job roda a 30s). */
    public const ROLLUP_MAX_AGE = 90;

    private static function jobsLateCountSql(int $now): string
    {
        $fast = array_map(
            static fn(string $name): string => "'" . str_replace("'", "''", $name) . "'",
            JobRunner::fastProfile()
        );
        return 'SELECT COUNT(*) FROM job_state
                 WHERE job_name IN (' . implode(',', $fast) . ')
                   AND next_run_epoch > 0
                   AND next_run_epoch < ' . ($now - 120) . '
                   AND running = 0';
    }

    private static function protectedHostSql(string $column = 'public_host'): string
    {
        return 'EXISTS (
            SELECT 1
              FROM aliases a
             WHERE a.active = 1
               AND lower(a.hostname) = lower(' . $column . ')
        )';
    }

    /* ---------------------------------------------------------------- jobs */

    public static function matchSessions(array &$stats): void
    {
        $pdo = Database::pdo();
        $since = time() - 300;
        JobRunner::step('carregar_eventos_pendentes');
        $events = $pdo->query(
            'SELECT request_id, username, credential_fingerprint, client_ip, user_agent,
                    stream_id, ts_epoch, session_key, route_kind, status, reason
             FROM proxy_request_events
             WHERE ts_epoch >= ' . $since . '
               AND match_confidence IN (\'pending\',\'low\')
               AND (
                    status BETWEEN 200 AND 399
                    OR (status = 0 AND session_key <> \'\')
               )
               AND username <> ""
               AND ' . self::protectedHostSql('public_host') . '
             ORDER BY id DESC LIMIT 500'
        )->fetchAll();
        JobRunner::step('cruzar_com_xui', count($events) . ' evento(s)');

        $link = $pdo->prepare(
            Database::insertIgnoreSql('proxy_session_links', [
                'request_id', 'activity_id', 'user_id', 'stream_id', 'matched_by', 'confidence', 'matched_at',
            ])
        );
        $mark = $pdo->prepare(
            'UPDATE proxy_request_events
                SET match_confidence = :c,
                    match_reason = :m
              WHERE request_id = :r'
        );
        $markSession = $pdo->prepare(
            'UPDATE cdn_sessions SET xui_activity_id = :a, match_confidence = :c, match_reason = :m
              WHERE session_key = :k AND :k <> \'\''
        );

        foreach ($events as $e) {
            $stats['processed']++;
            $user = self::userByEvent($e);
            if (!$user) {
                $mark->execute([':c' => 'low', ':m' => 'unknown_user', ':r' => $e['request_id']]);
                if (!empty($e['session_key'])) {
                    $markSession->execute([':a' => 0, ':c' => 'low', ':m' => 'unknown_user', ':k' => $e['session_key']]);
                }
                $stats['failed']++;
                continue;
            }
            $userId = (int) $user['user_id'];
            $sessions = $pdo->prepare('SELECT * FROM xui_activity_now_cache WHERE user_id = :u');
            $sessions->execute([':u' => $userId]);
            $rows = $sessions->fetchAll();
            if (!$rows) {
                $isDirect = false;
                if (!empty($e['session_key'])) {
                    $isDirectSt = $pdo->prepare(
                        'SELECT direct_source FROM cdn_sessions WHERE session_key = :k LIMIT 1'
                    );
                    $isDirectSt->execute([':k' => (string) $e['session_key']]);
                    $isDirect = (int) $isDirectSt->fetchColumn() === 1;
                }
                if ($isDirect) {
                    $mark->execute([':c' => 'medium', ':m' => 'direct_source_runtime', ':r' => $e['request_id']]);
                    if (!empty($e['session_key'])) {
                        $markSession->execute([':a' => 0, ':c' => 'medium', ':m' => 'direct_source_runtime', ':k' => $e['session_key']]);
                    }
                    continue;
                }
                $mark->execute([':c' => 'low', ':m' => 'orphan_request', ':r' => $e['request_id']]);
                if (!empty($e['session_key'])) {
                    $markSession->execute([':a' => 0, ':c' => 'low', ':m' => 'orphan_request', ':k' => $e['session_key']]);
                }
                $stats['details']['orphans'] = (int) ($stats['details']['orphans'] ?? 0) + 1;
                continue;
            }
            [$best, $by, $conf] = self::scoreSessions($rows, $e);
            if ($best) {
                $link->execute([
                    ':request_id' => $e['request_id'],
                    ':activity_id' => (int) $best['activity_id'],
                    ':user_id' => $userId,
                    ':stream_id' => (int) $best['stream_id'],
                    ':matched_by' => $by,
                    ':confidence' => $conf,
                    ':matched_at' => date('c'),
                ]);
                $mark->execute([':c' => $conf, ':m' => $by, ':r' => $e['request_id']]);
                if (!empty($e['session_key'])) {
                    $markSession->execute([
                        ':a' => (int) $best['activity_id'], ':c' => $conf, ':m' => $by, ':k' => $e['session_key'],
                    ]);
                }
                if ($conf === 'low') { $stats['failed']++; }
            }
        }
        $stats['details']['matched'] = $stats['processed'] - $stats['failed'];
    }

    /**
     * Score explícito do vínculo request <-> sessão ativa do XUI.
     *
     * Pontos: IP igual (+50), stream igual (+30), user-agent igual (+20),
     * container coerente (+5) e proximidade temporal (+15 até 60s, +5 até 300s).
     *
     *   >= 85  high    (praticamente certo)
     *   >= 55  medium  (provável)
     *    < 55  low     (fraco — o painel destaca)
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{0:?array<string,mixed>,1:string,2:string}
     */
    public static function scoreSessions(array $rows, array $event): array
    {
        $bestRow = null; $bestScore = -1; $bestReason = '';
        $eventEpoch = (int) ($event['ts_epoch'] ?? time());
        $eventStream = (int) ($event['stream_id'] ?? 0);
        foreach ($rows as $s) {
            $score = 10; // já é o mesmo user_id do XUI
            $reason = ['user'];
            if ((string) $s['user_ip'] !== '' && (string) $s['user_ip'] === (string) ($event['client_ip'] ?? '')) {
                $score += 50; $reason[] = 'ip';
            }
            if ($eventStream > 0 && (int) $s['stream_id'] === $eventStream) {
                $score += 30; $reason[] = 'stream';
            }
            $ua = (string) ($event['user_agent'] ?? '');
            if ($ua !== '' && (string) $s['user_agent'] !== '' && strcasecmp((string) $s['user_agent'], $ua) === 0) {
                $score += 20; $reason[] = 'ua';
            }
            $start = (int) ($s['date_start'] ?? 0);
            if ($start > 0) {
                $delta = abs($eventEpoch - $start);
                if ($delta <= 60) { $score += 15; $reason[] = 'time60'; }
                elseif ($delta <= 300) { $score += 5; $reason[] = 'time300'; }
            }
            if (!empty($s['container'])) { $score += 5; $reason[] = 'container'; }
            if ($score > $bestScore) {
                $bestScore = $score; $bestRow = $s; $bestReason = implode('+', $reason);
            }
        }
        if ($bestRow === null) { return [null, '', 'low']; }
        $conf = $bestScore >= 85 ? 'high' : ($bestScore >= 55 ? 'medium' : 'low');
        return [$bestRow, $bestReason . ':' . $bestScore, $conf];
    }

    private static function userByEvent(array $e): ?array
    {
        $pdo = Database::pdo();
        if (!empty($e['credential_fingerprint'])) {
            $st = $pdo->prepare('SELECT * FROM xui_users_cache WHERE credential_fingerprint = :f LIMIT 1');
            $st->execute([':f' => $e['credential_fingerprint']]);
            $row = $st->fetch();
            if ($row) return $row;
        }
        if (!empty($e['username'])) {
            $st = $pdo->prepare('SELECT * FROM xui_users_cache WHERE username = :u LIMIT 1');
            $st->execute([':u' => $e['username']]);
            $row = $st->fetch();
            if ($row) return $row;
        }
        return null;
    }

    public static function consolidate(array &$stats): void
    {
        $pdo = Database::pdo();
        $since = time() - 300;
        $hasRuntimeUptime = Database::tableHasColumn('proxy_user_runtime', 'uptime_start_epoch');
        $hasRuntimeLbLabel = Database::tableHasColumn('proxy_user_runtime', 'last_lb_label');
        JobRunner::step('agregar_eventos_5m');
        $rows = $pdo->query(
            'SELECT username,
                    MAX(last_epoch) AS last_epoch,
                    SUM(reqs) AS reqs,
                    SUM(bytes) AS bytes
               FROM (
                    SELECT username,
                           MAX(ts_epoch) AS last_epoch,
                           COUNT(*) AS reqs,
                           COALESCE(SUM(bytes), 0) AS bytes
                      FROM proxy_request_events
                     WHERE ts_epoch >= ' . $since . '
                       AND username <> ""
                       AND status BETWEEN 200 AND 399
                       AND ' . self::protectedHostSql('public_host') . '
                     GROUP BY username
                    UNION ALL
                    SELECT username,
                           MAX(last_seen_epoch) AS last_epoch,
                           0 AS reqs,
                           0 AS bytes
                      FROM cdn_sessions
                     WHERE username <> ""
                       AND ' . CdnSession::activeWhereSql(time()) . '
                       AND ' . CdnSession::publicClientWhereSql() . '
                     GROUP BY username
               ) x
              GROUP BY username'
        )->fetchAll();

        $last = $pdo->prepare(
                'SELECT public_host, client_ip, user_agent, route_kind, stream_id, ts
                 FROM proxy_request_events
                 WHERE username = :u
                   AND status BETWEEN 200 AND 399
                   AND ' . self::protectedHostSql('public_host') . '
                 ORDER BY ts_epoch DESC LIMIT 1'
        );
        $upsertColumns = [
            'username', 'user_id', 'public_host_last_seen', 'client_ip_last_seen', 'user_agent_last_seen',
            'active_connections_now', 'max_connections', 'last_activity_at', 'last_activity_epoch',
            'last_route_kind', 'last_stream_id', 'last_stream_name', 'requests_5m', 'bytes_5m',
            'health_status', 'updated_at', 'cdn_connections_now', 'xui_connections_now', 'divergence',
            'count_source', 'direct_sessions_now',
        ];
        $upsertValues = [
            ':u', ':uid', ':host', ':ip', ':ua', ':ac', ':mc', ':la', ':le', ':rk', ':sid', ':sname',
            ':rq', ':by', ':hs', ':up', ':cdn', ':xui', ':div', ':src', ':ds',
        ];
        $upsertUpdate = [
            'user_id=excluded.user_id',
            'public_host_last_seen=excluded.public_host_last_seen',
            'client_ip_last_seen=excluded.client_ip_last_seen',
            'user_agent_last_seen=excluded.user_agent_last_seen',
            'active_connections_now=excluded.active_connections_now',
            'max_connections=excluded.max_connections',
            'last_activity_at=excluded.last_activity_at',
            'last_activity_epoch=excluded.last_activity_epoch',
            'last_route_kind=excluded.last_route_kind',
            'last_stream_id=excluded.last_stream_id',
            'last_stream_name=excluded.last_stream_name',
            'requests_5m=excluded.requests_5m',
            'bytes_5m=excluded.bytes_5m',
            'health_status=excluded.health_status',
            'updated_at=excluded.updated_at',
            'cdn_connections_now=excluded.cdn_connections_now',
            'xui_connections_now=excluded.xui_connections_now',
            'divergence=excluded.divergence',
            'count_source=excluded.count_source',
            'direct_sessions_now=excluded.direct_sessions_now',
        ];
        if ($hasRuntimeUptime) {
            $upsertColumns[] = 'uptime_start_epoch';
            $upsertValues[] = ':uptime';
            $upsertUpdate[] = 'uptime_start_epoch=excluded.uptime_start_epoch';
        }
        if ($hasRuntimeLbLabel) {
            $upsertColumns[] = 'last_lb_label';
            $upsertValues[] = ':lb';
            $upsertUpdate[] = 'last_lb_label=excluded.last_lb_label';
        }
        $upsert = $pdo->prepare(
            'INSERT INTO proxy_user_runtime (' . implode(', ', $upsertColumns) . ')
             VALUES (' . implode(',', $upsertValues) . ')
             ON CONFLICT(username) DO UPDATE SET ' . implode(', ', $upsertUpdate)
        );

        $cdnCounts = CdnSession::activeCounts();
        JobRunner::step('consolidar_usuarios', count($rows) . ' usuário(s)');
        $directCount = $pdo->prepare(
            'SELECT COUNT(*) FROM cdn_sessions WHERE username = :u
               AND ' . CdnSession::activeWhereSql(time()) . ' AND direct_source = 1'
        );
        $liveSession = $pdo->prepare(
            'SELECT s.public_host,
                    s.client_ip,
                    s.user_agent,
                    s.session_kind AS route_kind,
                    s.stream_id,
                    s.last_seen_at AS ts,
                    s.last_seen_epoch,
                    s.uptime_start_epoch,
                    COALESCE(xs.stream_display_name, \'\') AS stream_name,
                    COALESCE(lb.label, CASE WHEN s.lb_id > 0 THEN lb.public_ip ELSE \'main\' END, \'main\') AS lb_label
               FROM cdn_sessions s
          LEFT JOIN xui_streams_cache xs ON xs.stream_id = s.stream_id
          LEFT JOIN lb_nodes lb ON lb.id = s.lb_id
              WHERE s.username = :u
                AND ' . CdnSession::activeWhereSql(time(), 's') . '
                AND ' . CdnSession::publicClientWhereSql('s') . '
              ORDER BY s.last_seen_epoch DESC
              LIMIT 1'
        );

        foreach ($rows as $r) {
            $username = (string) $r['username'];
            $last->execute([':u' => $username]);
            $l = $last->fetch() ?: [];
            $liveSession->execute([':u' => $username]);
            $live = $liveSession->fetch() ?: [];
            if ($l === [] && $live !== []) {
                $l = $live;
            }
            $user = self::userByEvent(['username' => $username, 'credential_fingerprint' => '']);
            $userId = (int) ($user['user_id'] ?? 0);
            $maxConn = (int) ($user['max_connections'] ?? 0);
            $xuiActive = 0;
            if ($userId > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM xui_activity_now_cache WHERE user_id = :u');
                $st->execute([':u' => $userId]);
                $xuiActive = (int) $st->fetchColumn();
            }
            $cdnActive = (int) ($cdnCounts[$username] ?? 0);
            $directCount->execute([':u' => $username]);
            $directNow = (int) $directCount->fetchColumn();
            // A CDN é a fonte de verdade: direct source some do XUI, então o
            // contador oficial é o maior dos dois (merged).
            $active = max($cdnActive, $xuiActive);
            $source = $cdnActive === $xuiActive
                ? 'merged'
                : ($cdnActive > $xuiActive ? 'cdn_local' : 'xui_activity_now');
            $divergence = $cdnActive - $xuiActive;
            $streamId = (int) ($l['stream_id'] ?? 0);
            $streamName = (string) ($live['stream_name'] ?? '');
            if ($streamName === '' && $streamId > 0) {
                $st = $pdo->prepare('SELECT stream_display_name FROM xui_streams_cache WHERE stream_id = :s');
                $st->execute([':s' => $streamId]);
                $streamName = (string) ($st->fetchColumn() ?: '');
            }
            $lastActivityEpoch = max((int) $r['last_epoch'], (int) ($live['last_seen_epoch'] ?? 0));
            $lastActivityAt = (string) ($live['ts'] ?? ($l['ts'] ?? ''));
            $lastHost = (string) ($live['public_host'] ?? ($l['public_host'] ?? ''));
            $lastIp = (string) ($live['client_ip'] ?? ($l['client_ip'] ?? ''));
            $lastPlayer = (string) ($live['user_agent'] ?? ($l['user_agent'] ?? ''));
            $lastRouteKind = (string) ($live['route_kind'] ?? ($l['route_kind'] ?? ''));
            $uptimeStartEpoch = (int) ($live['uptime_start_epoch'] ?? 0);
            $lastLbLabel = (string) ($live['lb_label'] ?? 'main');
            $health = 'ok';
            if ($maxConn > 0 && $active > $maxConn) { $health = 'over_limit'; }
            if ($userId === 0) { $health = 'unknown_user'; }
            if ($health === 'ok' && $divergence !== 0 && $userId > 0) { $health = 'divergent'; }
            $incon = $pdo->prepare(
                'SELECT COUNT(*) FROM proxy_request_events
                  WHERE username = :u
                    AND inconsistency <> \'\'
                    AND ts_epoch >= :s
                    AND status BETWEEN 200 AND 399
                    AND ' . self::protectedHostSql('public_host')
            );
            $incon->execute([':u' => $username, ':s' => $since]);
            if ((int) $incon->fetchColumn() > 0) { $health = 'inconsistent'; }

            $params = [
                ':u' => $username, ':uid' => $userId,
                ':host' => $lastHost,
                ':ip' => $lastIp,
                ':ua' => $lastPlayer,
                ':ac' => $active, ':mc' => $maxConn,
                ':la' => $lastActivityAt, ':le' => $lastActivityEpoch,
                ':rk' => $lastRouteKind,
                ':sid' => $streamId, ':sname' => $streamName,
                ':rq' => (int) $r['reqs'], ':by' => (int) $r['bytes'],
                ':hs' => $health, ':up' => date('c'),
                ':cdn' => $cdnActive, ':xui' => $xuiActive, ':div' => $divergence,
                ':src' => $source, ':ds' => $directNow,
            ];
            if ($hasRuntimeUptime) {
                $params[':uptime'] = $uptimeStartEpoch;
            }
            if ($hasRuntimeLbLabel) {
                $params[':lb'] = $lastLbLabel;
            }
            $upsert->execute($params);
            $stats['processed']++;
        }
        // Some quem sumiu da janela.
        $pdo->exec('DELETE FROM proxy_user_runtime WHERE last_activity_epoch < ' . ($since - 300));
        $stats['details']['users'] = $stats['processed'];
    }

    public static function detectInconsistencies(array &$stats): void
    {
        $pdo = Database::pdo();
        $since = time() - 600;
        JobRunner::step('swap_de_credencial');

        // 1) Swap de credencial — sempre crítico.
        $swapRows = $pdo->query(
            'SELECT username, COUNT(*) AS c FROM proxy_request_events
              WHERE ts_epoch >= ' . $since . '
                AND inconsistency = \'invalid_credentials_swap\'
                AND ' . self::protectedHostSql('public_host') . '
              GROUP BY username'
        )->fetchAll();
        $swaps = 0;
        foreach ($swapRows as $s) {
            $swaps += (int) $s['c'];
            Divergence::raise((string) $s['username'], 'invalid_credentials_swap', 'critical',
                'Origem devolveu conteúdo de outro usuário; entrega abortada pelo CredentialGuard',
                ['events_10m' => (int) $s['c']]);
            Audit::log('restream_swap', sprintf('%s: %d swap(s) em 10min', $s['username'], (int) $s['c']), '-', 'job');
        }
        $stats['failed'] += $swaps;

        // 2) Contagem CDN x XUI x limite do plano.
        JobRunner::step('contagem_cdn_x_xui');
        $rows = $pdo->query(
            'SELECT username, user_id, cdn_connections_now, xui_connections_now, active_connections_now,
                    max_connections, divergence, direct_sessions_now
               FROM proxy_user_runtime'
        )->fetchAll();
        $overLimit = 0; $mismatch = 0; $unknown = 0;
        $mode = Divergence::mode();
        foreach ($rows as $r) {
            $username = (string) $r['username'];
            $cdn = (int) $r['cdn_connections_now'];
            $xui = (int) $r['xui_connections_now'];
            $max = (int) $r['max_connections'];
            $active = (int) $r['active_connections_now'];

            if ((int) $r['user_id'] === 0) {
                $unknown++;
                Divergence::raise($username, 'unknown_user', 'warn',
                    'Consumo sem usuário correspondente no espelho do XUI (sync atrasado ou login inválido)',
                    ['cdn' => $cdn, 'xui' => $xui]);
            }

            if ($max > 0 && $active > $max) {
                $overLimit++;
                Divergence::raise($username, 'above_limit', 'critical',
                    sprintf('Usando %d conexões para um limite de %d (modo: %s)', $active, $max, $mode),
                    ['cdn' => $cdn, 'xui' => $xui, 'max' => $max, 'mode' => $mode]);
                Audit::log('restream_over_limit',
                    sprintf('%s usando %d conexões (limite %d, modo %s)', $username, $active, $max, $mode), '-', 'job');
            }

            $diff = $cdn - $xui;
            if ($diff !== 0 && (int) $r['user_id'] > 0) {
                $mismatch++;
                $cause = $diff > 0
                    ? ((int) $r['direct_sessions_now'] > 0
                        ? 'CDN vê mais: consumo direct source que não aparece no user_activity_now'
                        : 'CDN vê mais: sessão local ativa antes do XUI registrar (ou sync atrasado)')
                    : 'XUI vê mais: sessão fantasma/zumbi no XUI ou consumo fora da CDN';
                Divergence::raise($username, 'count_mismatch', abs($diff) > 2 ? 'warn' : 'info', $cause,
                    ['cdn' => $cdn, 'xui' => $xui, 'max' => $max, 'direct' => (int) $r['direct_sessions_now']]);
            }
        }

        // 3) Sessão ativa no XUI sem nenhum request na CDN (órfã).
        JobRunner::step('sessoes_orfas');
        $orphanActivity = (int) $pdo->query(
            'SELECT COUNT(*) FROM xui_activity_now_cache a
              WHERE NOT EXISTS (SELECT 1 FROM proxy_session_links l WHERE l.activity_id = a.activity_id)'
        )->fetchColumn();

        // 4) Matches fracos nos últimos 10 min.
        $weak = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_request_events
              WHERE ts_epoch >= ' . $since . '
                AND match_confidence = \'low\'
                AND username <> \'\'
                AND ' . self::protectedHostSql('public_host') . ''
        )->fetchColumn();

        JobRunner::step('normalizar_direct_runtime');
        $normalized = Database::run(
            'UPDATE cdn_sessions
                SET match_confidence = \'medium\',
                    match_reason = \'direct_source_runtime\'
              WHERE ' . CdnSession::activeWhereSql(time()) . '
                AND direct_source = 1
                AND xui_activity_id = 0
                AND match_reason IN (\'\', \'orphan_request\')',
            [],
            'restream.direct_runtime_match'
        );

        // 5) Espelho do XUI parado.
        $cfg = XuiSyncConfig::get();
        $lastSync = strtotime((string) ($cfg['last_sync_at'] ?? '')) ?: 0;
        if ((int) ($cfg['sync_enabled'] ?? 0) === 1 && (time() - $lastSync) > 120) {
            Divergence::raise('-', 'sync_stale', 'warn',
                'Espelho do XUI sem sincronizar há mais de 2 minutos; contagem oficial fica só com a CDN',
                ['last_sync' => (string) ($cfg['last_sync_at'] ?? ''), 'status' => (string) ($cfg['last_sync_status'] ?? '')]);
        }

        $closed = Divergence::closeStale(300);
        $stats['processed'] += count($rows);
        $stats['details'] = [
            'swaps_10m' => $swaps,
            'over_limit' => $overLimit,
            'count_mismatch' => $mismatch,
            'unknown_users' => $unknown,
            'orphan_activity' => $orphanActivity,
            'weak_matches_10m' => $weak,
            'direct_runtime_normalized' => $normalized ? 1 : 0,
            'closed' => $closed,
            'mode' => $mode,
        ];
    }

    /** Job leve: mantém o painel ao vivo longe de agregações pesadas. */
    public static function metricsRollupLight(array &$stats): void
    {
        $pdo = Database::pdo();
        $now = time();
        $preIndexHint = Database::isSqlite() ? ' INDEXED BY idx_pre_ts' : '';
        $active = (int) $pdo->query(
            'SELECT COUNT(*) FROM cdn_sessions WHERE ' . CdnSession::activeWhereSql($now) . '
               AND ' . CdnSession::publicClientWhereSql() . '
                AND session_kind NOT IN (\'playlist\',\'api\')'
        )->fetchColumn();
        $users = (int) $pdo->query(
            'SELECT COUNT(DISTINCT username) FROM cdn_sessions WHERE ' . CdnSession::activeWhereSql($now) . '
               AND ' . CdnSession::publicClientWhereSql()
        )->fetchColumn();
        $fetch = (int) $pdo->query(
            'SELECT COUNT(*) FROM cdn_sessions WHERE ' . CdnSession::activeWhereSql($now) . '
               AND ' . CdnSession::publicClientWhereSql() . '
                AND session_kind IN (\'playlist\',\'api\')'
        )->fetchColumn();
        $direct = DirectSource::activeSessions();
        $requests5 = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 300) . '
                AND status BETWEEN 200 AND 399
                AND ' . self::protectedHostSql('public_host')
        )->fetchColumn();
        $bytes5 = (int) $pdo->query(
            'SELECT COALESCE(SUM(bytes),0) FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 300) . '
                AND status BETWEEN 200 AND 399
                AND ' . self::protectedHostSql('public_host')
        )->fetchColumn();
        $errors5 = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 300) . '
                AND status >= 400
                AND ' . self::protectedHostSql('public_host')
        )->fetchColumn();
        $swaps1h = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 3600) . '
                AND inconsistency = \'invalid_credentials_swap\'
                AND ' . self::protectedHostSql('public_host')
        )->fetchColumn();
        $inconsistencies1h = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 3600) . '
                AND inconsistency <> \'\'
                AND ' . self::protectedHostSql('public_host')
        )->fetchColumn();
        $jobsLate = (int) $pdo->query(self::jobsLateCountSql($now))->fetchColumn();
        $directBlocked = (int) $pdo->query(
            'SELECT COUNT(*) FROM direct_source_hops WHERE ts_epoch >= ' . ($now - 3600) . ' AND outcome <> \'followed\''
        )->fetchColumn();
        $div = Divergence::counters();
        // Estes dois iam no caminho do painel (2 COUNT por tick). Agora o
        // resumo lê do rollup e só reconta se o rollup estiver velho.
        $runtimeActive = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_user_runtime
              WHERE last_activity_epoch >= ' . ($now - self::ACTIVE_WINDOW) . '
                AND active_connections_now > 0'
        )->fetchColumn();
        $overLimitNow = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_user_runtime
              WHERE max_connections > 0 AND active_connections_now > max_connections'
        )->fetchColumn();
        $ins = $pdo->prepare('INSERT INTO cdn_metrics (metric, value, ts_epoch) VALUES (:m,:v,:t)');
        // KPIs específicos de direct source: catálogo do XUI x consumo real.
        $dc = DirectCatalog::summary();
        $metrics = [
            'connections_active' => $active,
            'users_active' => $users,
            'fetch_active' => $fetch,
            'direct_active' => $direct,
            'requests_5m' => $requests5,
            'bytes_5m' => $bytes5,
            'errors_5m' => $errors5,
            'swaps_1h' => $swaps1h,
            'inconsistencies_1h' => $inconsistencies1h,
            'jobs_late' => $jobsLate,
            'direct_blocked_1h' => $directBlocked,
            'divergences_critical' => (int) ($div['critical'] ?? 0),
            'divergences_warn' => (int) ($div['warn'] ?? 0),
            'divergences_info' => (int) ($div['info'] ?? 0),
            'direct_streams_db' => (int) $dc['streams_db'],
            'direct_db_runtime' => (int) $dc['db_runtime'],
            'direct_runtime_only' => (int) $dc['runtime_only'],
            'direct_mismatch' => (int) $dc['mismatch'],
            'direct_parse_errors' => (int) $dc['parse_errors'],
            'users_runtime_active' => $runtimeActive,
            'over_limit_now' => $overLimitNow,
        ];
        foreach ($metrics as $m => $v) {
            $ins->execute([':m' => $m, ':v' => $v, ':t' => $now]);
        }
        $pdo->exec('DELETE FROM cdn_metrics WHERE ts_epoch < ' . ($now - 86400));
        $stats['processed'] += count($metrics);
        $stats['details'] = $metrics;
    }

    /** Job analítico: snapshots pesados que nao podem pressionar o painel ao vivo. */
    public static function metricsRollupAnalytics(array &$stats): void
    {
        $pdo = Database::pdo();
        $now = time();
        $preIndexHint = Database::isSqlite() ? ' INDEXED BY idx_pre_ts' : '';
        $topHosts = $pdo->query(
            'SELECT public_host AS k, COUNT(*) AS c FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 300) . '
                AND ' . self::protectedHostSql('public_host') . '
              GROUP BY public_host
              ORDER BY c DESC
              LIMIT 5'
        )->fetchAll();
        $topPlayers = $pdo->query(
            'SELECT user_agent AS k, COUNT(*) AS c FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 300) . '
                AND user_agent <> \'\'
                AND ' . self::protectedHostSql('public_host') . '
              GROUP BY user_agent
              ORDER BY c DESC
              LIMIT 5'
        )->fetchAll();
        $topKinds = $pdo->query(
            'SELECT route_kind AS k, COUNT(*) AS c FROM proxy_request_events' . $preIndexHint . '
              WHERE ts_epoch >= ' . ($now - 300) . '
                AND ' . self::protectedHostSql('public_host') . '
              GROUP BY route_kind
              ORDER BY c DESC'
        )->fetchAll();

        SettingsRepository::set('summary_top_hosts_5m', $topHosts);
        SettingsRepository::set('summary_top_players_5m', $topPlayers);
        SettingsRepository::set('summary_top_kinds_5m', $topKinds);
        SettingsRepository::set('summary_top_snapshot_epoch', $now);
        $stats['processed'] += count($topHosts) + count($topPlayers) + count($topKinds);
        $stats['details'] = [
            'top_hosts' => count($topHosts),
            'top_players' => count($topPlayers),
            'top_kinds' => count($topKinds),
            'snapshot_epoch' => $now,
        ];
    }

    /** Alias legado para nao quebrar chamadas antigas. */
    public static function metricsRollup(array &$stats): void
    {
        self::metricsRollupLight($stats);
    }

    /** Pico de uma métrica na janela informada. */
    public static function peak(string $metric, int $seconds): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(value),0) FROM cdn_metrics WHERE metric = :m AND ts_epoch >= :t'
        );
        $st->execute([':m' => $metric, ':t' => time() - $seconds]);
        return (int) $st->fetchColumn();
    }

    /** Último valor conhecido de uma métrica do rollup. */
    public static function latestMetric(string $metric): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(value,0) FROM cdn_metrics
              WHERE metric = :m ORDER BY ts_epoch DESC LIMIT 1'
        );
        $st->execute([':m' => $metric]);
        return (int) $st->fetchColumn();
    }

    /** @return array<string,int> */
    public static function latestMetrics(array $metrics): array
    {
        $metrics = array_values(array_unique(array_filter(array_map('strval', $metrics))));
        if ($metrics === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($metrics), '?'));
        $sql = 'SELECT metric, value
                  FROM cdn_metrics
                 WHERE metric IN (' . $placeholders . ')
                  ORDER BY metric ASC, ts_epoch ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($metrics);
        $out = array_fill_keys($metrics, 0);
        // Ordenado por ts crescente: a última linha lida de cada métrica é a
        // mais recente. Antes usávamos subquery correlacionada sem alias no
        // FROM interno, e o SQLite ligava `cdn_metrics.metric` à tabela INTERNA
        // -> o filtro virava MAX(ts_epoch) GLOBAL. Em produção, onde outro job
        // grava métrica mais nova no mesmo segundo seguinte, a linha certa
        // simplesmente desaparecia do resultado.
        foreach ($st->fetchAll() as $row) {
            $out[(string) $row['metric']] = (int) $row['value'];
        }
        return $out;
    }

    /**
     * Idade do rollup por métrica.
     *
     * @return array<string,array{value:int,age:int}>
     */
    public static function latestMetricsAged(array $metrics): array
    {
        $metrics = array_values(array_unique(array_filter(array_map('strval', $metrics))));
        if ($metrics === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($metrics), '?'));
        $st = Database::pdo()->prepare(
            'SELECT metric, value, ts_epoch
               FROM cdn_metrics
              WHERE metric IN (' . $placeholders . ')
              ORDER BY metric ASC, ts_epoch ASC'
        );
        $st->execute($metrics);
        $now = time();
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(string) $row['metric']] = [
                'value' => (int) $row['value'],
                'age' => max(0, $now - (int) $row['ts_epoch']),
            ];
        }
        return $out;
    }

    /**
     * Rollup só vale se for RECENTE.
     *
     * O painel oscilava porque a leitura caía para recontagem apenas quando o
     * rollup marcava zero; rollup atrasado com número velho passava como se
     * fosse ao vivo. Agora: métrica ausente ou velha => null, e quem chamou
     * decide (recontar uma vez, dentro do cache de 5s).
     *
     * @return array<string,int>|null
     */
    public static function metricsIfFresh(array $metrics, int $maxAge = self::ROLLUP_MAX_AGE): ?array
    {
        $aged = self::latestMetricsAged($metrics);
        $out = [];
        foreach ($metrics as $m) {
            $m = (string) $m;
            if (!isset($aged[$m]) || $aged[$m]['age'] > $maxAge) {
                return null;
            }
            $out[$m] = $aged[$m]['value'];
        }
        return $out;
    }

    /**
     * Idade máxima do rollup leve entre as métricas informadas.
     *
     * Contrato único: SEMPRE inteiro >= 0. Rollup ausente não pode devolver -1
     * (o painel e os smokes tratam o campo como idade, não como código de
     * erro); ausência = idade acima do teto, ou seja, degradado explícito.
     *
     * @param array<int,string> $metrics
     */
    public static function rollupAgeSeconds(array $metrics = ['connections_active']): int
    {
        $aged = self::latestMetricsAged($metrics);
        $age = 0;
        foreach ($metrics as $m) {
            if (!isset($aged[(string) $m])) {
                return self::ROLLUP_MAX_AGE * 10;
            }
            $age = max($age, (int) $aged[(string) $m]['age']);
        }
        return max(0, $age);
    }

    /**
     * Saúde rápida do cérebro para polling do painel.
     *
     * Não pode chamar kpisFresh() completo porque isso reabre contagens pesadas.
     * Aqui consumimos o que os jobs já consolidaram em cdn_metrics.
     */
    public static function healthView(): array
    {
        return Cache::remember('health_view', 5, static function (): array {
            $states = JobRunner::states();
            return [
                'db' => Database::healthSnapshot(),
                'jobs' => array_values(array_filter(
                    $states,
                    static fn (array $j): bool => !empty($j['circuit_open'])
                        || ((int) ($j['late_seconds'] ?? 0) > 120 && JobRunner::isFastJob((string) ($j['job_name'] ?? '')))
                        || (string) ($j['last_status'] ?? '') === 'error'
                )),
                'sessions_now' => self::latestMetric('connections_active'),
                'users_now' => self::latestMetric('users_active'),
                'direct_now' => self::latestMetric('direct_active'),
            ];
        });
    }

    /** KPIs operacionais da CDN (conexão, qualidade, consistência). */
    public static function kpis(): array
    {
        // Polling do painel: 5s de cache mantém a leitura "ao vivo" e evita
        // recontar centenas de milhares de linhas a cada request.
        return Cache::remember('kpis', 5, static fn(): array => self::kpisFresh());
    }

    public static function kpisFresh(): array
    {
        $now = time();
        $pdo = Database::pdo();
        $jobsLateNow = (int) $pdo->query(self::jobsLateCountSql($now))->fetchColumn();
        $divOperational = Divergence::countersOperational();
        $divAll = Divergence::counters();
        $metrics = self::latestMetrics([
            'connections_active',
            'users_active',
            'fetch_active',
            'direct_active',
            'requests_5m',
            'bytes_5m',
            'errors_5m',
            'swaps_1h',
            'inconsistencies_1h',
            'jobs_late',
            'direct_blocked_1h',
            'divergences_critical',
            'divergences_warn',
            'divergences_info',
            'direct_streams_db',
            'direct_db_runtime',
            'direct_runtime_only',
            'direct_mismatch',
            'direct_parse_errors',
        ]);
        $active = (int) ($metrics['connections_active'] ?? 0);
        $usersActive = (int) ($metrics['users_active'] ?? 0);
        $fetchNow = (int) ($metrics['fetch_active'] ?? 0);
        $directNow = (int) ($metrics['direct_active'] ?? 0);
        $liveKeys = ['connections_active', 'users_active', 'fetch_active', 'direct_active'];
        $liveFresh = self::metricsIfFresh($liveKeys);
        $rollupAge = self::rollupAgeSeconds();

        // Recontamos SÓ quando o rollup está velho/ausente (não quando ele
        // legitimamente marca zero). Isso mata a oscilação "0 -> 3 -> 0" e
        // mantém a leitura ao vivo barata: 1 recontagem por 5s de cache.
        if ($liveFresh === null) {
            $active = (int) $pdo->query(
                'SELECT COUNT(*) FROM cdn_sessions
                  WHERE ' . CdnSession::activeWhereSql($now) . '
                    AND ' . CdnSession::publicClientWhereSql() . '
                    AND session_kind NOT IN (\'playlist\',\'api\')'
            )->fetchColumn();
            $fetchNow = (int) $pdo->query(
                'SELECT COUNT(*) FROM cdn_sessions
                  WHERE ' . CdnSession::activeWhereSql($now) . '
                    AND ' . CdnSession::publicClientWhereSql() . '
                    AND session_kind IN (\'playlist\',\'api\')'
            )->fetchColumn();
            $usersActive = (int) $pdo->query(
                'SELECT COUNT(DISTINCT username) FROM cdn_sessions
                  WHERE ' . CdnSession::activeWhereSql($now) . '
                    AND ' . CdnSession::publicClientWhereSql() . '
                    AND username <> ""'
            )->fetchColumn();
            $directNow = (int) $pdo->query(
                'SELECT COUNT(*) FROM cdn_sessions
                  WHERE ' . CdnSession::activeWhereSql($now) . '
                    AND ' . CdnSession::publicClientWhereSql() . '
                    AND direct_source = 1
                    AND session_kind NOT IN (\'playlist\',\'api\')'
            )->fetchColumn();
        }

        $conf = $pdo->query(
            'SELECT match_confidence AS k, COUNT(*) AS c
               FROM cdn_sessions
              WHERE ' . CdnSession::activeWhereSql($now) . '
                AND ' . CdnSession::publicClientWhereSql() . '
                AND username <> ""
              GROUP BY match_confidence'
        )->fetchAll();
        $byConf = ['high' => 0, 'medium' => 0, 'low' => 0, 'pending' => 0, 'invalid' => 0];
        foreach ($conf as $c) { $byConf[(string) $c['k']] = (int) $c['c']; }

        return [
            'connections_now' => $active,
            'fetch_now' => $fetchNow,
            'sessions_now' => $active + $fetchNow,
            'users_now' => $usersActive,
            'avg_per_user' => $usersActive > 0 ? round($active / $usersActive, 2) : 0,
            'peak_5m' => max($active, self::peak('connections_active', 300)),
            'peak_1h' => max($active, self::peak('connections_active', 3600)),
            'direct_now' => $directNow,
            'direct_catalog' => [
                'streams_db' => (int) ($metrics['direct_streams_db'] ?? 0),
                'streams_parsed' => max(0, (int) ($metrics['direct_streams_db'] ?? 0) - (int) ($metrics['direct_parse_errors'] ?? 0)),
                'parse_errors' => (int) ($metrics['direct_parse_errors'] ?? 0),
                'db_only' => max(0, (int) ($metrics['direct_streams_db'] ?? 0) - (int) ($metrics['direct_db_runtime'] ?? 0)),
                'runtime_only' => (int) ($metrics['direct_runtime_only'] ?? 0),
                'db_runtime' => (int) ($metrics['direct_db_runtime'] ?? 0),
                'mismatch' => (int) ($metrics['direct_mismatch'] ?? 0),
                'hosts_effective' => 0,
            ],
            'direct_blocked_1h' => (int) ($metrics['direct_blocked_1h'] ?? 0),
            'errors_5m' => (int) ($metrics['errors_5m'] ?? 0),
            'swaps_1h' => (int) ($metrics['swaps_1h'] ?? 0),
            'match' => $byConf,
            'jobs_late' => $jobsLateNow,
            'rollup_age_s' => $rollupAge,
            'rollup_stale' => $liveFresh === null,
            // O painel ao vivo precisa refletir o que exige ação agora, não a
            // massa informativa do catálogo de direct source.
            'divergences' => $divOperational,
            'divergences_operational' => $divOperational,
            'divergences_catalog_noise' => [
                'critical' => max(0, (int) ($divAll['critical'] ?? 0) - (int) ($divOperational['critical'] ?? 0)),
                'warn' => max(0, (int) ($divAll['warn'] ?? 0) - (int) ($divOperational['warn'] ?? 0)),
                'info' => max(0, (int) ($divAll['info'] ?? 0) - (int) ($divOperational['info'] ?? 0)),
            ],
            'limit_mode' => Divergence::mode(),
        ];
    }

    public static function cleanup(array &$stats): void
    {
        $pdo = Database::pdo();
        $days = (int) SettingsRepository::get('event_retention_days', 7);
        JobRunner::step('podar_eventos');
        $stats['processed'] += RequestLog::prune($days);
        $stats['processed'] += DirectSource::prune($days);
        JobRunner::step('podar_residuos_match');
        $pdo->exec(
            'DELETE FROM proxy_request_events
              WHERE ts_epoch < ' . (time() - 21600) . '
                AND match_confidence IN (\'pending\',\'low\')
                AND status >= 400'
        );
        JobRunner::step('podar_trilha');
        $stats['processed'] += AuditTimeline::prune($days);
        JobRunner::step('podar_tabelas_auxiliares');
        $pdo->exec('DELETE FROM rate_limit WHERE window_start < ' . (int) floor((time() - 3600) / 60));
        $pdo->exec('DELETE FROM access_log WHERE ts < \'' . date('c', time() - $days * 86400) . '\'');
        $pdo->exec('DELETE FROM job_runs WHERE started_epoch < ' . (time() - 3 * 86400));
        $pdo->exec('DELETE FROM job_step_history WHERE ts_epoch < ' . (time() - 3 * 86400));
        $pdo->exec('DELETE FROM lb_route_history WHERE ts_epoch < ' . (time() - 7 * 86400));
        $pdo->exec('DELETE FROM audit_logs WHERE created_at < \'' . date('c', time() - 30 * 86400) . '\'');
        $pdo->exec('DELETE FROM tokens WHERE expires_at < \'' . date('c', time() - 86400) . '\'');
        $pdo->exec('DELETE FROM cdn_sessions WHERE status = \'closed\' AND ended_epoch < ' . (time() - 86400));
        $pdo->exec('DELETE FROM cdn_divergences WHERE status = \'closed\' AND closed_epoch < ' . (time() - 7 * 86400));
        // WAL grande deixa TODA leitura mais lenta; o cleanup é a hora certa.
        JobRunner::step('checkpoint_wal');
        if (Database::isSqlite()) {
            try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) { /* best-effort */ }
        }
        $stats['details']['retention_days'] = $days;
    }

    /** Reprocessa matching de requests que ficaram "low" quando o XUI estava fora. */
    public static function repair(array &$stats): void
    {
        $pdo = Database::pdo();
        $since = time() - 1800;
        $pdo->exec(
            'UPDATE proxy_request_events SET match_confidence = \'pending\'
             WHERE ts_epoch >= ' . $since . '
               AND match_confidence = \'low\'
               AND username <> ""
               AND ' . self::protectedHostSql('public_host') . '
               AND request_id NOT IN (SELECT request_id FROM proxy_session_links)'
        );
        $stats['processed'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_request_events
              WHERE ts_epoch >= ' . $since . '
                AND match_confidence = \'pending\'
                AND ' . self::protectedHostSql('public_host')
        )->fetchColumn();
        self::matchSessions($stats);
    }

    /* ------------------------------------------------------------- leitura */

    public static function live(array $filters = [], int $limit = 200): array
    {
        $key = 'live_' . md5(json_encode([$filters, $limit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Cache::remember($key, 1, static function () use ($filters, $limit): array {
            $lbLabelsSql = Database::isPgsql()
                ? "STRING_AGG(DISTINCT CASE
                            WHEN s.lb_id > 0 THEN COALESCE(lb.label, lb.public_ip, 'LB#' || s.lb_id::text)
                            ELSE 'main'
                        END, ',')"
                : 'GROUP_CONCAT(DISTINCT CASE
                            WHEN s.lb_id > 0 THEN COALESCE(lb.label, lb.public_ip, "LB#" || s.lb_id)
                            ELSE "main"
                        END)';
            $sql = 'SELECT
                        s.username,
                        COALESCE(u.user_id, r.user_id, 0) AS user_id,
                        s.public_host AS public_host_last_seen,
                        s.client_ip AS client_ip_last_seen,
                        s.user_agent AS user_agent_last_seen,
                        MAX(s.last_seen_epoch) AS last_activity_epoch,
                        MAX(s.last_seen_at) AS last_activity_at,
                        MAX(s.last_route_kind) AS last_route_kind,
                        MAX(s.stream_id) AS last_stream_id,
                        COALESCE(MAX(xs.stream_display_name), COALESCE(r.last_stream_name, \'\')) AS last_stream_name,
                        SUM(CASE WHEN s.session_kind NOT IN (\'playlist\',\'api\') THEN 1 ELSE 0 END) AS active_connections_now,
                        SUM(CASE WHEN s.session_kind IN (\'playlist\',\'api\') THEN 1 ELSE 0 END) AS playlist_fetch_now,
                        SUM(CASE WHEN s.direct_source = 1 AND s.session_kind NOT IN (\'playlist\',\'api\') THEN 1 ELSE 0 END) AS direct_sessions_now,
                        MAX(s.lb_id) AS lb_id,
                        ' . $lbLabelsSql . ' AS lb_labels,
                        COALESCE(MAX(route.lb_id), 0) AS route_lb_id,
                        COALESCE(MAX(route.mode), \'main_only\') AS route_mode,
                        COALESCE(MAX(route_lb.label), CASE WHEN COALESCE(MAX(route.lb_id), 0) > 0 THEN MAX(route_lb.public_ip) ELSE \'main\' END, \'main\') AS route_lb_label,
                        COALESCE(MAX(u.max_connections), COALESCE(r.max_connections, 0)) AS max_connections,
                        COALESCE(MAX(r.xui_connections_now), 0) AS xui_connections_now,
                        COALESCE(MAX(r.requests_5m), 0) AS requests_5m,
                        COALESCE(MAX(r.bytes_5m), 0) AS bytes_5m,
                        COALESCE(MAX(r.count_source), \'cdn_local\') AS count_source,
                        COALESCE(MAX(r.health_status), \'ok\') AS health_status
                    FROM cdn_sessions s
                    LEFT JOIN proxy_user_runtime r ON r.username = s.username
                    LEFT JOIN xui_users_cache u ON u.username = s.username
                    LEFT JOIN xui_streams_cache xs ON xs.stream_id = s.stream_id
                    LEFT JOIN lb_nodes lb ON lb.id = s.lb_id
                    LEFT JOIN lb_user_routes route ON route.username = s.username
                    LEFT JOIN lb_nodes route_lb ON route_lb.id = route.lb_id
                    WHERE ' . CdnSession::activeWhereSql(time(), 's') . '
                      AND ' . CdnSession::publicClientWhereSql('s') . '
                      AND s.username <> \'\'
                      AND ' . self::protectedHostSql('s.public_host');
            $params = [];
            if (!empty($filters['username'])) { $sql .= ' AND s.username LIKE :u'; $params[':u'] = '%' . $filters['username'] . '%'; }
            if (!empty($filters['host'])) { $sql .= ' AND s.public_host LIKE :h'; $params[':h'] = '%' . $filters['host'] . '%'; }
            if (!empty($filters['ip'])) { $sql .= ' AND s.client_ip LIKE :i'; $params[':i'] = '%' . $filters['ip'] . '%'; }
            if (!empty($filters['kind'])) { $sql .= ' AND s.last_route_kind = :k'; $params[':k'] = $filters['kind']; }
            if (!empty($filters['player'])) { $sql .= ' AND s.user_agent LIKE :p'; $params[':p'] = '%' . $filters['player'] . '%'; }
            $sql .= ' GROUP BY s.username';
            if (!empty($filters['status'])) { $sql .= ' HAVING health_status = :s'; $params[':s'] = $filters['status']; }
            if (!empty($filters['over'])) { $sql .= (!empty($filters['status']) ? ' AND' : ' HAVING') . ' max_connections > 0 AND active_connections_now > max_connections'; }
            $sql .= ' ORDER BY last_activity_epoch DESC LIMIT ' . max(1, min(1000, $limit));
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['cdn_connections_now'] = (int) $row['active_connections_now'];
                $row['active_connections_now'] = max((int) $row['active_connections_now'], (int) $row['xui_connections_now']);
                $row['divergence'] = (int) $row['cdn_connections_now'] - (int) $row['xui_connections_now'];
                if ((int) $row['max_connections'] > 0 && (int) $row['active_connections_now'] > (int) $row['max_connections']) {
                    $row['health_status'] = 'over_limit';
                } elseif ((int) $row['divergence'] !== 0 && (string) $row['health_status'] === 'ok') {
                    $row['health_status'] = 'divergent';
                }
            }
            unset($row);
            return $rows;
        });
    }

    public static function summary(): array
    {
        return Cache::remember('summary', 2, static fn(): array => self::summaryFresh());
    }

    public static function summaryFresh(): array
    {
        $pdo = Database::pdo();
        $win = time() - self::ACTIVE_WINDOW;
        $cfg = XuiSyncConfig::get();
        $kpis = self::kpis();
        $summaryTopHosts = SettingsRepository::get('summary_top_hosts_5m', []);
        $summaryTopPlayers = SettingsRepository::get('summary_top_players_5m', []);
        $summaryTopKinds = SettingsRepository::get('summary_top_kinds_5m', []);
        $summaryMetrics = self::latestMetrics([
            'requests_5m',
            'bytes_5m',
            'errors_5m',
            'inconsistencies_1h',
        ]);
        // Resumo depende do rollup leve; recontagem só em modo degradado.
        $heavy = self::metricsIfFresh(['users_runtime_active', 'over_limit_now']);
        if ($heavy === null) {
            $heavy = [
                'users_runtime_active' => (int) ($pdo->query(
                    'SELECT COUNT(*) FROM proxy_user_runtime
                      WHERE last_activity_epoch >= ' . $win . ' AND active_connections_now > 0'
                )->fetchColumn() ?: 0),
                'over_limit_now' => (int) ($pdo->query(
                    'SELECT COUNT(*) FROM proxy_user_runtime
                      WHERE max_connections > 0 AND active_connections_now > max_connections'
                )->fetchColumn() ?: 0),
            ];
        }
        return [
            'generated_at' => date('c'),
            'active_users' => max((int) $kpis['users_now'], (int) $heavy['users_runtime_active']),
            'active_sessions_xui' => (int) $pdo->query('SELECT COUNT(*) FROM xui_activity_now_cache')->fetchColumn(),
            'active_sessions_cdn' => $kpis['connections_now'],
            'kpis' => $kpis,
            'divergences' => Divergence::countersRecent(300),
            'requests_5m' => (int) ($summaryMetrics['requests_5m'] ?? 0),
            'bytes_5m' => (int) ($summaryMetrics['bytes_5m'] ?? 0),
            'errors_5m' => (int) ($summaryMetrics['errors_5m'] ?? 0),
            'over_limit' => (int) $heavy['over_limit_now'],
            'inconsistencies_1h' => (int) ($summaryMetrics['inconsistencies_1h'] ?? 0),
            'sync_status' => (string) ($cfg['last_sync_status'] ?? 'never'),
            'sync_at' => (string) ($cfg['last_sync_at'] ?? ''),
            'sync_error' => (string) ($cfg['last_sync_error'] ?? ''),
            'sync_enabled' => (int) ($cfg['sync_enabled'] ?? 0) === 1,
            'mysql_driver' => XuiReadOnly::available(),
            'top_hosts' => is_array($summaryTopHosts) ? $summaryTopHosts : [],
            'top_players' => is_array($summaryTopPlayers) ? $summaryTopPlayers : [],
            'top_kinds' => is_array($summaryTopKinds) ? $summaryTopKinds : [],
            'top_users' => $pdo->query(
                'SELECT username AS k, active_connections_now AS c
                   FROM proxy_user_runtime
                  WHERE active_connections_now > 0 OR requests_5m > 0
                  ORDER BY active_connections_now DESC, requests_5m DESC, last_activity_epoch DESC
                  LIMIT 5'
            )->fetchAll(),
        ];
    }

    public static function userDetail(string $username): array
    {
        $pdo = Database::pdo();
        $runtime = $pdo->prepare('SELECT * FROM proxy_user_runtime WHERE username = :u');
        $runtime->execute([':u' => $username]);

        $xui = $pdo->prepare('SELECT * FROM xui_users_cache WHERE username = :u');
        $xui->execute([':u' => $username]);
        $xuiUser = $xui->fetch() ?: null;

        $sessions = [];
        if ($xuiUser) {
            $st = $pdo->prepare(
                'SELECT a.*, s.stream_display_name FROM xui_activity_now_cache a
                 LEFT JOIN xui_streams_cache s ON s.stream_id = a.stream_id
                 WHERE a.user_id = :u ORDER BY a.date_start DESC'
            );
            $st->execute([':u' => (int) $xuiUser['user_id']]);
            $sessions = $st->fetchAll();
        }

        $events = $pdo->prepare(
            'SELECT * FROM proxy_request_events
              WHERE username = :u
                AND ' . self::protectedHostSql('public_host') . '
              ORDER BY id DESC LIMIT 100'
        );
        $events->execute([':u' => $username]);

        $hosts = $pdo->prepare('SELECT public_host AS k, COUNT(*) AS c, MAX(ts) AS last FROM proxy_request_events WHERE username = :u AND ' . self::protectedHostSql('public_host') . ' GROUP BY public_host ORDER BY c DESC LIMIT 20');
        $hosts->execute([':u' => $username]);
        $ips = $pdo->prepare('SELECT client_ip AS k, COUNT(*) AS c, MAX(ts) AS last FROM proxy_request_events WHERE username = :u AND ' . self::protectedHostSql('public_host') . ' GROUP BY client_ip ORDER BY c DESC LIMIT 20');
        $ips->execute([':u' => $username]);
        $players = $pdo->prepare('SELECT user_agent AS k, COUNT(*) AS c, MAX(ts) AS last FROM proxy_request_events WHERE username = :u AND ' . self::protectedHostSql('public_host') . ' GROUP BY user_agent ORDER BY c DESC LIMIT 20');
        $players->execute([':u' => $username]);
        $divs = $pdo->prepare('SELECT * FROM proxy_request_events WHERE username = :u AND inconsistency <> "" AND ' . self::protectedHostSql('public_host') . ' ORDER BY id DESC LIMIT 50');
        $divs->execute([':u' => $username]);

        return [
            'username' => $username,
            'runtime' => $runtime->fetch() ?: null,
            'xui_user' => $xuiUser,
            'sessions' => $sessions,
            'cdn_sessions' => CdnSession::forUser($username),
            'direct_hops' => DirectSource::forUser($username, 40),
            'open_divergences' => Divergence::forUser($username),
            'events' => $events->fetchAll(),
            'hosts' => $hosts->fetchAll(),
            'ips' => $ips->fetchAll(),
            'players' => $players->fetchAll(),
            'divergences' => $divs->fetchAll(),
        ];
    }

    public static function events(array $filters = [], int $limit = 200): array
    {
        $sql = 'SELECT * FROM proxy_request_events WHERE ' . self::protectedHostSql('public_host');
        $params = [];
        foreach ([['username', 'username'], ['ip', 'client_ip'], ['host', 'public_host'], ['player', 'user_agent']] as [$f, $col]) {
            if (!empty($filters[$f])) { $sql .= " AND $col LIKE :$f"; $params[":$f"] = '%' . $filters[$f] . '%'; }
        }
        if (!empty($filters['kind'])) { $sql .= ' AND route_kind = :kind'; $params[':kind'] = $filters['kind']; }
        if (!empty($filters['only_problems'])) { $sql .= ' AND (status >= 400 OR inconsistency <> "")'; }
        if (!empty($filters['current_only'])) { $sql .= ' AND ts_epoch >= :since'; $params[':since'] = time() - 300; }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit));
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function byDimension(string $column, int $limit = 50): array
    {
        $allowed = ['client_ip', 'public_host', 'user_agent', 'route_kind'];
        if (!in_array($column, $allowed, true)) { return []; }
        $since = time() - 900;
        return Database::pdo()->query(
            "SELECT $column AS k, COUNT(*) AS requests, COALESCE(SUM(bytes),0) AS bytes,
                    COUNT(DISTINCT username) AS users, MAX(ts) AS last_seen
             FROM proxy_request_events WHERE ts_epoch >= $since AND $column <> ''
               AND " . self::protectedHostSql('public_host') . "
             GROUP BY $column ORDER BY requests DESC LIMIT " . max(1, min(500, $limit))
        )->fetchAll();
    }
}
