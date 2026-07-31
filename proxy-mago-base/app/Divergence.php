<?php

/**
 * Divergências operacionais: a CDN vê X, o XUI vê Y, o plano permite Z.
 *
 * Regras de produto (setting `limit_mode`):
 *   alert  -> só registra e mostra no painel (padrão)
 *   mark   -> registra + marca o usuário como risco em proxy_user_runtime
 *   block  -> além de marcar, o proxy passa a recusar novas conexões acima do
 *             limite depois da tolerância de reconexão (`limit_tolerance_seconds`)
 *
 * Nada aqui roda no caminho quente do stream, exceto shouldBlock(), que é um
 * SELECT por chave primária no SQLite local.
 */
final class Divergence
{
    public const KINDS = [
        'count_mismatch'           => 'CDN e XUI discordam na contagem de conexões',
        'above_limit'              => 'Usuário acima do limite do plano',
        'unknown_user'             => 'Usuário consumindo sem espelho no XUI',
        'orphan_request'           => 'Request da CDN sem sessão correspondente no XUI',
        'orphan_activity'          => 'Sessão ativa no XUI sem request na CDN',
        'invalid_credentials_swap' => 'Origem devolveu conteúdo de outro usuário',
        'sync_stale'               => 'Espelho do XUI desatualizado',
        'weak_match'               => 'Vínculo request/sessão com baixa confiança',
        // Divergências específicas de DIRECT SOURCE (escopo por stream).
        'direct_db_runtime_mismatch'   => 'Host do direct no XUI difere do host consumido em runtime',
        'direct_host_missing'          => 'Direct source marcado no XUI sem host final conhecido',
        'direct_parse_error'           => 'stream_source do XUI em formato não suportado',
        'direct_orphan_session'        => 'Sessão direct ativa sem stream no catálogo da CDN',
        'direct_runtime_without_db_flag' => 'Redirect externo sem direct_source=1 no XUI',
        'direct_db_flag_without_runtime' => 'Direct cadastrado no XUI sem consumo observado',
    ];

    public static function mode(): string
    {
        $mode = (string) SettingsRepository::get('limit_mode', 'alert');
        return in_array($mode, ['alert', 'mark', 'block'], true) ? $mode : 'alert';
    }

    public static function tolerance(): int
    {
        return max(0, (int) SettingsRepository::get('limit_tolerance_seconds', 45));
    }

    /** Abre ou reforça uma divergência (idempotente por usuário+tipo). */
    public static function raise(
        string $username,
        string $kind,
        string $severity,
        string $cause,
        array $data = [],
        string $scope = '',
        int $streamId = 0
    ): void {
        $now = time();
        $pdo = Database::pdo();
        $scope = substr($scope, 0, 120);
        $params = [
            ':u' => $username,
            ':k' => $kind,
            ':s' => $severity,
            ':cc' => (int) ($data['cdn'] ?? 0),
            ':xc' => (int) ($data['xui'] ?? 0),
            ':mc' => (int) ($data['max'] ?? 0),
            ':pc' => substr($cause, 0, 200),
            ':d' => substr(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}', 0, 600),
            ':sc' => $scope,
            ':sid' => $streamId,
            ':oa' => date('c', $now),
            ':oe' => $now,
            ':le' => $now,
        ];

        $check = $pdo->prepare(
            'SELECT id, opened_at, opened_epoch, occurrences FROM cdn_divergences
              WHERE username = :u AND kind = :k AND scope = :sc AND status = "open"
              ORDER BY id ASC LIMIT 1'
        );
        $check->execute([':u' => $username, ':k' => $kind, ':sc' => $scope]);
        $existing = $check->fetch();

        if ($existing) {
            $pdo->prepare(
                'DELETE FROM cdn_divergences
                  WHERE username = :u AND kind = :k AND scope = :sc AND status = "open"'
            )->execute([':u' => $username, ':k' => $kind, ':sc' => $scope]);

            $params[':oa'] = (string) $existing['opened_at'];
            $params[':oe'] = (int) $existing['opened_epoch'];
            $params[':occ'] = ((int) $existing['occurrences']) + 1;
        } else {
            $params[':occ'] = 1;
        }

        $pdo->prepare(
            'INSERT INTO cdn_divergences
               (username, kind, severity, cdn_count, xui_count, max_connections, probable_cause,
                detail, status, scope, stream_id, opened_at, opened_epoch, last_seen_epoch, occurrences)
             VALUES (:u,:k,:s,:cc,:xc,:mc,:pc,:d,"open",:sc,:sid,:oa,:oe,:le,:occ)'
        )->execute($params);
    }

    /** Fecha divergências que pararam de acontecer. */
    public static function closeStale(int $seconds = 300): int
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE cdn_divergences SET status = "closed", closed_epoch = :now
              WHERE status = "open" AND last_seen_epoch < :cut'
        );
        $stmt->execute([':now' => time(), ':cut' => time() - $seconds]);
        return $stmt->rowCount();
    }

    /** @return array<int,array<string,mixed>> */
    public static function open(array $filters = [], int $limit = 200): array
    {
        $sql = 'SELECT * FROM cdn_divergences WHERE status = "open"';
        $params = [];
        if (!empty($filters['severity'])) { $sql .= ' AND severity = :s'; $params[':s'] = $filters['severity']; }
        if (!empty($filters['kind'])) { $sql .= ' AND kind = :k'; $params[':k'] = $filters['kind']; }
        if (!empty($filters['direct'])) { $sql .= ' AND kind LIKE "direct_%"'; }
        if (!empty($filters['username'])) { $sql .= ' AND username LIKE :u'; $params[':u'] = '%' . $filters['username'] . '%'; }
        $sql .= ' ORDER BY CASE severity WHEN "critical" THEN 0 WHEN "warn" THEN 1 ELSE 2 END,'
              . ' last_seen_epoch DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,int> contagem por severidade */
    public static function counters(): array
    {
        $rows = Database::pdo()->query(
            'SELECT severity, COUNT(*) AS c FROM cdn_divergences WHERE status = "open" GROUP BY severity'
        )->fetchAll();
        $out = ['critical' => 0, 'warn' => 0, 'info' => 0];
        foreach ($rows as $r) { $out[(string) $r['severity']] = (int) $r['c']; }
        return $out;
    }

    /** @return array<string,int> contagem por severidade na janela recente */
    public static function countersRecent(int $seconds = 300): array
    {
        $st = Database::pdo()->prepare(
            'SELECT severity, COUNT(*) AS c
               FROM cdn_divergences
              WHERE status = "open" AND last_seen_epoch >= :cut
                AND kind NOT IN ("direct_db_flag_without_runtime", "direct_orphan_session")
              GROUP BY severity'
        );
        $st->execute([':cut' => time() - max(30, $seconds)]);
        $rows = $st->fetchAll();
        $out = ['critical' => 0, 'warn' => 0, 'info' => 0];
        foreach ($rows as $r) { $out[(string) $r['severity']] = (int) $r['c']; }
        return $out;
    }

    /** @return array<string,int> contagem operacional viva, sem massa de catálogo direct */
    public static function countersOperational(int $seconds = 300): array
    {
        $st = Database::pdo()->prepare(
            'SELECT severity, COUNT(*) AS c
               FROM cdn_divergences
              WHERE status = "open"
                AND last_seen_epoch >= :cut
                AND kind NOT IN (
                    "direct_db_flag_without_runtime",
                    "direct_orphan_session",
                    "direct_runtime_without_db"
                )
              GROUP BY severity'
        );
        $st->execute([':cut' => time() - max(30, $seconds)]);
        $rows = $st->fetchAll();
        $out = ['critical' => 0, 'warn' => 0, 'info' => 0];
        foreach ($rows as $r) { $out[(string) $r['severity']] = (int) $r['c']; }
        return $out;
    }

    public static function forUser(string $username, int $limit = 20): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM cdn_divergences WHERE username = :u ORDER BY last_seen_epoch DESC LIMIT ' . max(1, min(100, $limit))
        );
        $st->execute([':u' => $username]);
        return $st->fetchAll();
    }

    /**
     * Enforcement no caminho do proxy: só bloqueia em modo `block`, quando o
     * usuário está acima do limite há mais tempo que a tolerância de reconexão
     * e quando a sessão local não é apenas playlist/api.
     */
    public static function shouldBlock(string $username): bool
    {
        if ($username === '' || self::mode() !== 'block') { return false; }
        try {
            $st = Database::pdo()->prepare(
                'SELECT cdn_count, max_connections, opened_epoch FROM cdn_divergences
                  WHERE username = :u AND kind = "above_limit" AND status = "open" LIMIT 1'
            );
            $st->execute([':u' => $username]);
            $row = $st->fetch();
            if (!$row) { return false; }
            if ((int) $row['max_connections'] <= 0) { return false; }
            return (time() - (int) $row['opened_epoch']) >= self::tolerance();
        } catch (Throwable $e) {
            return false; // nunca derrubar o player por causa do enforcement
        }
    }
}
