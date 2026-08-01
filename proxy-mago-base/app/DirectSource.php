<?php

/**
 * Rastreio profundo de "direct source".
 *
 * O XUI devolve 302 para um CDN de terceiros (ex.: readyondemand.click) e some
 * do `user_activity_now`. Para a CDN, a fonte de verdade tem que ser ela mesma:
 * aqui gravamos cada hop seguido (ou bloqueado) pelo proxy, o host final
 * acessado por dentro, e o desfecho do consumo.
 *
 * O cliente NUNCA vê nada disso: os hops ficam só na trilha interna.
 */
final class DirectSource
{
    public static function enabled(): bool
    {
        return (int) SettingsRepository::get('direct_source_trace', 1) === 1;
    }

    /**
     * Persiste a trilha do request atual e consolida as duas verdades.
     *
     * RUNTIME: hops seguidos/bloqueados pelo proxy neste request.
     * DB:      `xui_streams_cache` (flag + host já cadastrado no XUI), lido por
     *          PK no SQLite local — nunca vai ao MySQL do XUI aqui.
     *
     * Best-effort: qualquer falha é logada e o stream continua.
     */
    public static function persist(RequestContext $ctx, string $sessionKey, int $status): string
    {
        $runtimeHost = StreamProxy::directHost();
        $hops = StreamProxy::hops();
        if (!self::enabled()) { return $runtimeHost; }

        $streamId = (int) ($ctx->streamId ?? 0);
        $db = DirectCatalog::dbHostFor($streamId);
        $hostDb = (string) $db['host'];
        $isDirect = $hops !== [] || (int) $db['direct'] === 1;
        if (!$isDirect) { return $runtimeHost; }

        $mode = self::mode((int) $db['direct'], $runtimeHost !== '');
        $effective = $runtimeHost !== '' ? $runtimeHost : $hostDb;

        try {
            if ($hops !== []) {
                $stmt = Database::pdo()->prepare(
                    'INSERT INTO direct_source_hops
                       (request_id, session_key, username, hop_no, from_host, to_host, off_origin, outcome,
                        status, ts, ts_epoch, stream_id, public_host, client_ip, player, route_kind,
                        final_host, direct_mode, host_from_db, error)
                     VALUES (:r,:k,:u,:n,:f,:t,:o,:oc,:s,:ts,:te,:sid,:ph,:ip,:pl,:rk,:fh,:dm,:hdb,:err)'
                );
                $now = time();
                foreach ($hops as $hop) {
                    $outcome = (string) $hop['outcome'];
                    $stmt->execute([
                        ':r' => $ctx->requestId, ':k' => $sessionKey, ':u' => $ctx->username,
                        ':n' => (int) $hop['hop'], ':f' => (string) $hop['from'], ':t' => (string) $hop['to'],
                        ':o' => (int) $hop['off_origin'], ':oc' => $outcome,
                        ':s' => $status, ':ts' => date('c', $now), ':te' => $now,
                        ':sid' => $streamId,
                        ':ph' => (string) ($ctx->publicHost ?? ''),
                        ':ip' => (string) ($ctx->clientIp ?? ''),
                        ':pl' => substr((string) $ctx->userAgent, 0, 120), // player = user agent do cliente
                        ':rk' => (string) ($ctx->routeKind ?? ''),
                        ':fh' => $effective, ':dm' => $mode, ':hdb' => $hostDb,
                        ':err' => $outcome === 'followed' ? '' : substr((string) ($hop['reason'] ?? $outcome), 0, 200),
                    ]);
                }
            }
            self::touchSession($sessionKey, $mode, $hostDb, $runtimeHost, $effective, $hops, $status);
        } catch (Throwable $e) {
            error_log('[directsource] persist falhou: ' . $e->getMessage());
        }
        return $effective;
    }

    /** Marca a sessão local como direct, com primeira/última vez e falhas. */
    private static function touchSession(
        string $sessionKey, string $mode, string $hostDb, string $hostRuntime,
        string $effective, array $hops, int $status
    ): void {
        $failures = 0;
        $blocked = 0;
        foreach ($hops as $hop) {
            if ((string) $hop['outcome'] === 'followed') { continue; }
            $failures++;
            if ((string) $hop['outcome'] === 'blocked') { $blocked++; }
        }
        if ($status >= 400) { $failures++; }
        $now = time();
        Database::pdo()->prepare(
            "UPDATE cdn_sessions
                SET direct_source = 1,
                    uptime_start_epoch = CASE
                        WHEN uptime_start_epoch = 0 AND direct_first_epoch > 0 THEN direct_first_epoch
                        WHEN uptime_start_epoch = 0 THEN started_epoch
                        ELSE uptime_start_epoch
                    END,
                    direct_mode = :m,
                    match_confidence = CASE
                        WHEN xui_activity_id = 0 AND match_confidence IN ('', 'low', 'pending')
                            THEN 'medium'
                        ELSE match_confidence
                    END,
                    match_reason = CASE
                        WHEN xui_activity_id = 0 AND match_reason IN ('', 'orphan_request')
                            THEN 'direct_source_runtime'
                        ELSE match_reason
                    END,
                    idle_timeout = CASE
                        WHEN session_kind = 'movie' AND idle_timeout < " . CdnSession::DIRECT_IDLE['movie'] . "
                            THEN " . CdnSession::DIRECT_IDLE['movie'] . "
                        WHEN session_kind = 'series' AND idle_timeout < " . CdnSession::DIRECT_IDLE['series'] . "
                            THEN " . CdnSession::DIRECT_IDLE['series'] . "
                        WHEN session_kind = 'other' AND idle_timeout < " . CdnSession::DIRECT_IDLE['other'] . "
                            THEN " . CdnSession::DIRECT_IDLE['other'] . "
                        ELSE idle_timeout
                    END,
                    status = 'active',
                    close_reason = '',
                    ended_epoch = 0,
                    direct_host_db = CASE WHEN :hdb <> '' THEN :hdb2 ELSE direct_host_db END,
                    direct_host_runtime = CASE WHEN :hrt <> '' THEN :hrt2 ELSE direct_host_runtime END,
                    direct_host_effective = CASE WHEN :heff <> '' THEN :heff2 ELSE direct_host_effective END,
                    direct_first_epoch = CASE WHEN direct_first_epoch = 0 THEN :now ELSE direct_first_epoch END,
                    direct_last_epoch = :now2,
                    direct_failures = direct_failures + :f,
                    direct_blocked = direct_blocked + :b
              WHERE session_key = :k"
        )->execute([
            ':m' => $mode, ':hdb' => $hostDb, ':hdb2' => $hostDb,
            ':hrt' => $hostRuntime, ':hrt2' => $hostRuntime,
            ':heff' => $effective, ':heff2' => $effective,
            ':now' => $now, ':now2' => $now, ':f' => $failures, ':b' => $blocked, ':k' => $sessionKey,
        ]);
    }

    /** db_only | runtime_only | db_runtime */
    public static function mode(int $directFlagDb, bool $hasRuntime): string
    {
        if ($directFlagDb === 1 && $hasRuntime) { return 'db_runtime'; }
        if ($directFlagDb === 1) { return 'db_only'; }
        return $hasRuntime ? 'runtime_only' : 'none';
    }

    /** Sessões de direct source ativas agora (contadas pela CDN). */
    public static function activeSessions(): int
    {
        return (int) Database::pdo()->query(
            'SELECT COUNT(*) FROM cdn_sessions WHERE ' . CdnSession::activeWhereSql(time()) . '
              AND ' . CdnSession::publicClientWhereSql() . '
              AND direct_source = 1'
        )->fetchColumn();
    }

    /** @return array<int,array<string,mixed>> hosts finais mais usados */
    public static function topHosts(int $minutes = 15, int $limit = 10): array
    {
        $since = time() - ($minutes * 60);
        return Database::pdo()->query(
            "SELECT to_host AS k, COUNT(*) AS c, MAX(ts) AS last_seen
               FROM direct_source_hops
              WHERE ts_epoch >= " . $since . " AND off_origin = 1 AND outcome = 'followed' AND to_host <> ''
              GROUP BY to_host ORDER BY c DESC LIMIT " . max(1, min(50, $limit))
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> hops bloqueados (falha/abandono) */
    public static function blocked(int $minutes = 60, int $limit = 50): array
    {
        $since = time() - ($minutes * 60);
        return Database::pdo()->query(
            "SELECT * FROM direct_source_hops
              WHERE ts_epoch >= " . $since . " AND outcome <> 'followed'
              ORDER BY id DESC LIMIT " . max(1, min(200, $limit))
        )->fetchAll();
    }

    public static function forRequest(string $requestId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM direct_source_hops WHERE request_id = :r ORDER BY hop_no ASC'
        );
        $st->execute([':r' => $requestId]);
        return $st->fetchAll();
    }

    public static function forUser(string $username, int $limit = 50): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM direct_source_hops WHERE username = :u ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $st->execute([':u' => $username]);
        return $st->fetchAll();
    }

    /** @return array<int,array<string,mixed>> últimos hops de um stream */
    public static function forStream(int $streamId, int $limit = 50): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM direct_source_hops WHERE stream_id = :s ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
        );
        $st->execute([':s' => $streamId]);
        return $st->fetchAll();
    }

    /** Hops de um usuário já enriquecidos com modo e host efetivo. */
    public static function timeline(string $username, int $limit = 50): array
    {
        return self::forUser($username, $limit);
    }

    public static function prune(int $days): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM direct_source_hops WHERE ts_epoch < :cut');
        $stmt->execute([':cut' => time() - ($days * 86400)]);
        return $stmt->rowCount();
    }
}
