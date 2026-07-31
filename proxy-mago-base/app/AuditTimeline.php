<?php

/**
 * TRILHA ÚNICA (fase 3 do plano de rastreabilidade total).
 *
 * Hoje a verdade estava espalhada: proxy_request_events (request), cdn_sessions
 * (conexão lógica), direct_source_hops (para onde o XUI mandou), cdn_divergences
 * (o que não fecha) e lb_user_routes (por qual músculo passou). Para auditar
 * "o que este usuário fez", o operador precisava cruzar 5 tabelas na mão.
 *
 * `cdn_audit_timeline` é UMA linha por sessão lógica, atualizada em UPSERT no
 * fechamento do request. Custo: 1 escrita por request textual (o mesmo que já
 * pagávamos), e ZERO para segmento .ts — segmento só entra na trilha quando
 * `log_segments = 1` ou quando dá erro (mesma regra do RequestLog).
 *
 * Nunca pode derrubar o stream: toda escrita passa por Database::write().
 */
final class AuditTimeline
{
    public static function shouldPersist(RequestContext $ctx, int $status): bool
    {
        if ($ctx->routeKind !== 'segment') {
            return true;
        }
        if ($status >= 400) {
            return true;
        }
        return (int) SettingsRepository::get('log_segments', 0) === 1;
    }

    /**
     * Consolida o request atual na linha do tempo da sessão.
     *
     * @param array<string,mixed> $extra origin_id, lb_id, lb_target, lb_reason,
     *                                   direct_host, inconsistency, hops, reason
     */
    public static function record(
        RequestContext $ctx,
        string $sessionKey,
        int $status,
        int $bytes,
        array $extra = []
    ): void {
        if ($sessionKey === '' || !self::shouldPersist($ctx, $status)) {
            return;
        }
        $now = time();
        $directHost = (string) ($extra['direct_host'] ?? '');
        Database::run(
            'INSERT INTO cdn_audit_timeline
               (session_key, username, credential_fingerprint, client_ip, user_agent, public_host,
                session_kind, stream_id, origin_id, lb_id, lb_target, lb_reason,
                direct_source, direct_host, first_request_id, last_request_id, last_path,
                last_status, last_reason, inconsistency, requests, errors, bytes, hops,
                started_epoch, last_epoch)
             VALUES (:k,:u,:fp,:ip,:ua,:host,:kind,:sid,:oid,:lb,:lbt,:lbr,:ds,:dh,:rid,:rid2,:path,
                     :st,:reason,:inc,1,:err,:by,:hops,:now,:now2)
             ON CONFLICT(session_key) DO UPDATE SET
               username=CASE WHEN excluded.username <> "" THEN excluded.username ELSE cdn_audit_timeline.username END,
               public_host=excluded.public_host,
               stream_id=CASE WHEN excluded.stream_id > 0 THEN excluded.stream_id ELSE cdn_audit_timeline.stream_id END,
               origin_id=CASE WHEN excluded.origin_id > 0 THEN excluded.origin_id ELSE cdn_audit_timeline.origin_id END,
               lb_id=excluded.lb_id,
               lb_target=excluded.lb_target,
               lb_reason=excluded.lb_reason,
               direct_source=CASE WHEN excluded.direct_source = 1 THEN 1 ELSE cdn_audit_timeline.direct_source END,
               direct_host=CASE WHEN excluded.direct_host <> "" THEN excluded.direct_host ELSE cdn_audit_timeline.direct_host END,
               last_request_id=excluded.last_request_id,
               last_path=excluded.last_path,
               last_status=excluded.last_status,
               last_reason=excluded.last_reason,
               inconsistency=CASE WHEN excluded.inconsistency <> "" THEN excluded.inconsistency ELSE cdn_audit_timeline.inconsistency END,
               requests=cdn_audit_timeline.requests + 1,
               errors=cdn_audit_timeline.errors + excluded.errors,
               bytes=cdn_audit_timeline.bytes + excluded.bytes,
               hops=MAX(cdn_audit_timeline.hops, excluded.hops),
               last_epoch=excluded.last_epoch',
            [
                ':k' => $sessionKey,
                ':u' => $ctx->username,
                ':fp' => $ctx->fingerprint,
                ':ip' => $ctx->clientIp,
                ':ua' => substr($ctx->userAgent, 0, 200),
                ':host' => $ctx->publicHost,
                ':kind' => CdnSession::kindOf($ctx),
                ':sid' => (int) $ctx->streamId,
                ':oid' => (int) ($extra['origin_id'] ?? 0),
                ':lb' => (int) ($extra['lb_id'] ?? 0),
                ':lbt' => (string) ($extra['lb_target'] ?? 'main'),
                ':lbr' => substr((string) ($extra['lb_reason'] ?? ''), 0, 60),
                ':ds' => $directHost !== '' ? 1 : 0,
                ':dh' => $directHost,
                ':rid' => $ctx->requestId,
                ':rid2' => $ctx->requestId,
                ':path' => RequestLog::maskPath($ctx->path),
                ':st' => $status,
                ':reason' => substr((string) ($extra['reason'] ?? ''), 0, 60),
                ':inc' => substr((string) ($extra['inconsistency'] ?? ''), 0, 60),
                ':err' => $status >= 400 ? 1 : 0,
                ':by' => max(0, $bytes),
                ':hops' => (int) ($extra['hops'] ?? 0),
                ':now' => $now,
                ':now2' => $now,
            ],
            'timeline.record'
        );
    }

    /**
     * Leitura do painel de auditoria: uma busca só responde
     * "quem, de onde, por qual host, por qual músculo, com qual problema".
     *
     * @return array<int,array<string,mixed>>
     */
    public static function search(array $filters = [], int $limit = 200): array
    {
        $sql = 'SELECT t.*, s.status AS session_status, s.session_kind AS kind_now,
                       n.label AS lb_label, n.public_ip AS lb_ip
                  FROM cdn_audit_timeline t
                  LEFT JOIN cdn_sessions s ON s.session_key = t.session_key
                  LEFT JOIN lb_nodes n ON n.id = t.lb_id
                 WHERE 1=1';
        $params = [];
        foreach ([['username', 't.username'], ['ip', 't.client_ip'], ['host', 't.public_host'],
                  ['player', 't.user_agent'], ['request_id', 't.last_request_id']] as [$f, $col]) {
            if (!empty($filters[$f])) {
                $sql .= " AND $col LIKE :$f";
                $params[":$f"] = '%' . $filters[$f] . '%';
            }
        }
        if (!empty($filters['kind'])) { $sql .= ' AND t.session_kind = :kind'; $params[':kind'] = $filters['kind']; }
        if (!empty($filters['direct'])) { $sql .= ' AND t.direct_source = 1'; }
        if (!empty($filters['lb_id'])) { $sql .= ' AND t.lb_id = :lb'; $params[':lb'] = (int) $filters['lb_id']; }
        if (!empty($filters['only_problems'])) { $sql .= ' AND (t.errors > 0 OR t.inconsistency <> "")'; }
        if (!empty($filters['since_minutes'])) {
            $sql .= ' AND t.last_epoch >= :since';
            $params[':since'] = time() - ((int) $filters['since_minutes'] * 60);
        }
        $sql .= ' ORDER BY t.last_epoch DESC LIMIT ' . max(1, min(1000, $limit));
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    }

    /** Trilha completa de um usuário (linha do tempo + request bruto + hops). */
    public static function forUser(string $username, int $limit = 60): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM cdn_audit_timeline WHERE username = :u
              ORDER BY last_epoch DESC LIMIT ' . max(1, min(200, $limit))
        );
        $st->execute([':u' => $username]);
        return $st->fetchAll() ?: [];
    }

    /** Indicadores do painel de auditoria (cacheado: o painel faz polling). */
    public static function summary(): array
    {
        return Cache::remember('timeline_summary', 5, static function (): array {
            $pdo = Database::pdo();
            $now = time();
            $win = $now - 300;
            $row = $pdo->query(
                'SELECT COUNT(*) AS sessions,
                        COUNT(DISTINCT username) AS users,
                        COALESCE(SUM(bytes),0) AS bytes,
                        COALESCE(SUM(errors),0) AS errors,
                        SUM(CASE WHEN direct_source = 1 THEN 1 ELSE 0 END) AS direct,
                        SUM(CASE WHEN inconsistency <> "" THEN 1 ELSE 0 END) AS inconsistent,
                        SUM(CASE WHEN lb_target = "lb" THEN 1 ELSE 0 END) AS via_lb
                   FROM cdn_audit_timeline WHERE last_epoch >= ' . $win
            )->fetch() ?: [];
            return [
                'window_seconds' => 300,
                'sessions' => (int) ($row['sessions'] ?? 0),
                'users' => (int) ($row['users'] ?? 0),
                'bytes' => (int) ($row['bytes'] ?? 0),
                'errors' => (int) ($row['errors'] ?? 0),
                'direct' => (int) ($row['direct'] ?? 0),
                'inconsistent' => (int) ($row['inconsistent'] ?? 0),
                'via_lb' => (int) ($row['via_lb'] ?? 0),
                'total_rows' => (int) $pdo->query('SELECT COUNT(*) FROM cdn_audit_timeline')->fetchColumn(),
                'generated_at' => date('c'),
            ];
        });
    }

    /** Job/cleanup: mantém a trilha auditável sem estourar o SQLite. */
    public static function prune(int $days): int
    {
        $cut = time() - ($days * 86400);
        $pdo = Database::pdo();
        $st = $pdo->prepare('DELETE FROM cdn_audit_timeline WHERE last_epoch < :cut');
        $st->execute([':cut' => $cut]);
        return $st->rowCount();
    }
}