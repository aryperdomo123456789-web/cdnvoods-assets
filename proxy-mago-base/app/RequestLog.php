<?php

/**
 * Gravação do log estruturado por request (proxy_request_events).
 *
 * Regras de leveza:
 *  - 1 INSERT no início (estado "pending") apenas para rotas que abrem sessão
 *    (m3u/api/hls/live/movie/series) ou para qualquer negativa;
 *  - segmentos (.ts) só são gravados quando `log_segments = 1` ou em erro;
 *  - UPDATE final com status/bytes/duração.
 *
 * Falha de log NUNCA derruba o stream (tudo em try/catch).
 */
final class RequestLog
{
    private static array $written = [];

    public static function shouldPersist(RequestContext $ctx): bool
    {
        if ($ctx->routeKind === 'segment') {
            return (int) SettingsRepository::get('log_segments', 0) === 1;
        }
        return true;
    }

    public static function open(RequestContext $ctx, ?int $originId, ?int $tokenId, string $reason = 'pending', string $sessionKey = ''): void
    {
        $ok = Database::write(static function (PDO $pdo) use ($ctx, $originId, $tokenId, $reason, $sessionKey): void {
            $stmt = $pdo->prepare(
                Database::insertIgnoreSql('proxy_request_events', [
                    'request_id', 'ts', 'ts_epoch', 'client_ip', 'public_host', 'method', 'path', 'query_masked',
                    'route_kind', 'username', 'credential_fingerprint', 'stream_id', 'token_id', 'origin_id',
                    'status', 'bytes', 'user_agent', 'referer', 'reason', 'match_confidence', 'session_key',
                ])
            );
            $stmt->execute([
                ':request_id' => $ctx->requestId,
                ':ts' => date('c'),
                ':ts_epoch' => time(),
                ':client_ip' => $ctx->clientIp,
                ':public_host' => $ctx->publicHost,
                ':method' => $ctx->method,
                ':path' => self::maskPath($ctx->path),
                ':query_masked' => $ctx->queryMasked,
                ':route_kind' => $ctx->routeKind,
                ':username' => $ctx->username,
                ':credential_fingerprint' => $ctx->fingerprint,
                ':stream_id' => $ctx->streamId,
                ':token_id' => $tokenId,
                ':origin_id' => $originId,
                ':status' => 0,
                ':bytes' => 0,
                ':user_agent' => $ctx->userAgent,
                ':referer' => $ctx->referer,
                ':reason' => $reason,
                ':match_confidence' => 'pending',
                ':session_key' => $sessionKey,
            ]);
        }, 'requestlog.open');
        if ($ok) {
            self::$written[$ctx->requestId] = true;
        }
    }

    public static function close(
        RequestContext $ctx,
        int $status,
        int $bytes,
        string $reason,
        string $inconsistency = '',
        string $directHost = '',
        int $hops = 0
    ): void {
        if (empty(self::$written[$ctx->requestId])) {
            return;
        }
        Database::write(static function (PDO $pdo) use ($ctx, $status, $bytes, $reason, $inconsistency, $directHost, $hops): void {
            $stmt = $pdo->prepare(
                'UPDATE proxy_request_events
                 SET status = :st, bytes = :by, duration_ms = :ms, reason = :reason,
                     inconsistency = :inc, direct_host = :dh, hops = :hops,
                     match_confidence = CASE WHEN :inc2 <> \'\' THEN \'invalid\' ELSE match_confidence END
                 WHERE request_id = :rid'
            );
            $stmt->execute([
                ':st' => $status,
                ':by' => $bytes,
                ':ms' => $ctx->elapsedMs(),
                ':reason' => $reason,
                ':inc' => $inconsistency,
                ':dh' => $directHost,
                ':hops' => $hops,
                ':inc2' => $inconsistency,
                ':rid' => $ctx->requestId,
            ]);
        }, 'requestlog.close');
    }

    /** Remove credenciais embutidas no path (/live/user/pass/1.ts). */
    public static function maskPath(string $path): string
    {
        $path = preg_replace('#^/(live|movie|vod|series|hls)/[^/]+/[^/]+/#i', '/$1/*/*/', $path) ?? $path;
        return substr($path, 0, 400);
    }

    /** Retenção: mantém a trilha auditável sem estourar o SQLite da VPS. */
    public static function prune(int $days): int
    {
        $cut = time() - ($days * 86400);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('DELETE FROM proxy_request_events WHERE ts_epoch < :cut');
        $stmt->execute([':cut' => $cut]);
        $removed = $stmt->rowCount();
        $pdo->exec('DELETE FROM proxy_session_links WHERE request_id NOT IN (SELECT request_id FROM proxy_request_events)');
        return $removed;
    }
}
