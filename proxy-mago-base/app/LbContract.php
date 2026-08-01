<?php

declare(strict_types=1);

/**
 * CONTRATO ESTÁVEL CÉREBRO <-> MÚSCULO — v1.
 *
 * Hoje o LB é um pacote de classes PHP do próprio proxy (LbPackageBuilder):
 * o músculo só funciona porque roda o MESMO runtime do cérebro. Isso trava a
 * troca do caminho quente por Go. Este arquivo quebra esse acoplamento.
 *
 * Dois lados, um contrato:
 *   1) SNAPSHOT (cérebro -> músculo): GET /lb-contract.php
 *      tudo que o músculo precisa para decidir sozinho, sem consultar o
 *      cérebro no caminho quente: origem, alias, regras por usuário, trava de
 *      IP, limite de conexão, flags de runtime e coordenadas do StateStore.
 *   2) EVENTOS (músculo -> cérebro): POST /lb-events.php
 *      lote de eventos já normalizados. O cérebro reaplica as MESMAS regras de
 *      negócio PHP (CdnSession/RequestLog/LbTelemetry) — nenhuma regra é
 *      duplicada no músculo em forma divergente.
 *
 * Regras do contrato:
 *   - `contract_version` é obrigatório nos dois lados. Divergência de major =
 *     músculo entra em modo conservador e NÃO aplica regra nova.
 *   - Campo desconhecido é IGNORADO, nunca fatal (compat futura).
 *   - Nada aqui pode depender de classe PHP no músculo: só JSON.
 *   - Segredos de origem viajam no snapshot (o músculo precisa falar com o
 *     XUI), então o transporte é obrigatoriamente autenticado por X-LB-Token
 *     e o snapshot NUNCA é servido sem token válido.
 */
final class LbContract
{
    public const VERSION = '1.0';
    public const MAJOR = 1;

    /** TTL sugerido do snapshot no músculo (segundos). */
    public const SNAPSHOT_TTL = 30;

    public const EVENT_TYPES = [
        'session_open',
        'session_touch',
        'session_close',
        'session_reject',
        'request',
        'heartbeat',
    ];

    // --------------------------------------------------------------- snapshot

    /**
     * Snapshot completo para UM nó de LB.
     *
     * @param array<string,mixed> $node linha de lb_nodes
     * @return array<string,mixed>
     */
    public static function snapshot(array $node): array
    {
        $lbId = (int) ($node['id'] ?? 0);

        return [
            'contract' => 'cdnvoods.lb',
            'contract_version' => self::VERSION,
            'generated_epoch' => time(),
            'ttl' => self::SNAPSHOT_TTL,
            'lb' => [
                'id' => $lbId,
                'label' => (string) ($node['label'] ?? ''),
                'public_ip' => (string) ($node['public_ip'] ?? ''),
                'enabled' => (int) ($node['enabled'] ?? 1) === 1,
                'drain' => (int) ($node['drain'] ?? 0) === 1,
            ],
            'state' => self::stateBlock(),
            'runtime' => self::runtimeBlock(),
            'origins' => self::originsBlock(),
            'aliases' => self::aliasesBlock(),
            'users' => self::usersBlock(),
            'brain' => [
                'events_url' => '/lb-events.php',
                'snapshot_url' => '/lb-contract.php',
                'heartbeat_url' => '/lb-ingest.php',
                'events_max_batch' => 500,
                'auth_header' => 'X-LB-Token',
            ],
        ];
    }

    /**
     * Coordenadas do estado vivo compartilhado. O músculo (PHP ou Go) fala com
     * o MESMO namespace de chaves descrito em app/StateStore.php.
     *
     * @return array<string,mixed>
     */
    private static function stateBlock(): array
    {
        $health = StateStore::health();

        return [
            'driver' => $health['configured'],
            'effective_driver' => $health['driver'],
            'degraded' => $health['degraded'],
            'namespace' => StateStore::NS,
            'redis' => [
                'host' => (string) SettingsRepository::get('redis_host', (string) Config::get('redis_host', '127.0.0.1')),
                'port' => (int) SettingsRepository::get('redis_port', (int) Config::get('redis_port', 6379)),
                'db' => (int) SettingsRepository::get('redis_db', (int) Config::get('redis_db', 0)),
                // senha NÃO viaja aqui: é instalada no músculo pelo instalador.
                'has_password' => ((string) SettingsRepository::get('redis_pass', (string) Config::get('redis_pass', ''))) !== '',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function runtimeBlock(): array
    {
        $bool = static fn (string $k, bool $d): bool => (int) SettingsRepository::get($k, $d ? '1' : '0') === 1;
        $int = static fn (string $k, int $d): int => (int) SettingsRepository::get($k, (string) $d);

        return [
            'sessions_enabled' => $bool('cdn_sessions_enabled', true),
            'enforce_ip_lock' => $bool('cdn_enforce_ip_lock', true),
            'enforce_connection_limit' => $bool('cdn_enforce_limit', true),
            'follow_direct_source' => $bool('follow_direct_source', true),
            'require_token' => $bool('require_token', false),
            'allowed_user_agent' => (string) SettingsRepository::get('allowed_user_agent', ''),
            'rate_limit_per_minute' => $int('rate_limit_per_minute', 240),
            'session_ttl_live' => $int('cdn_session_ttl_live', 120),
            'session_ttl_vod' => $int('cdn_session_ttl_vod', 1800),
            'log_requests' => $bool('log_requests', true),
            // Cérebro puro: o músculo sabe que ele é o ÚNICO caminho de entrega
            // e não deve mandar o player de volta para o main.
            'lb_require_delivery' => LbRouter::requireDelivery(),
            'lb_default_mode' => LbRouter::defaultMode(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function originsBlock(): array
    {
        $out = [];
        foreach (OriginRepository::all() as $o) {
            $out[] = [
                'id' => (int) $o['id'],
                'label' => (string) ($o['label'] ?? ''),
                'scheme' => (string) ($o['scheme'] ?? 'http'),
                'host' => (string) ($o['host'] ?? ''),
                'port' => (int) ($o['port'] ?? 80),
                'host_header' => (string) ($o['host_header'] ?? ''),
                // O músculo precisa disso para MASCARAR o corpo (extra_hosts) e
                // para falar com origem de conta única (auth_*). O snapshot só
                // sai com X-LB-Token válido — ver docs/CONTRATO_LB_V1.md.
                'extra_hosts' => (string) ($o['extra_hosts'] ?? ''),
                'base_path' => (string) ($o['base_path'] ?? ''),
                'auth_user' => (string) ($o['auth_user'] ?? ''),
                'auth_pass' => (string) ($o['auth_pass'] ?? ''),
                'active' => (int) ($o['active'] ?? 1) === 1,
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function aliasesBlock(): array
    {
        $out = [];
        foreach (AliasRepository::all() as $a) {
            $out[] = [
                'id' => (int) $a['id'],
                'hostname' => (string) ($a['hostname'] ?? ''),
                'origin_id' => (int) ($a['origin_id'] ?? 0),
                'active' => (int) ($a['active'] ?? 1) === 1,
            ];
        }
        return $out;
    }

    /**
     * Regras POR USUÁRIO. É o coração do contrato: o músculo aplica limite e
     * trava de IP sem uma ida ao cérebro por segmento.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function usersBlock(): array
    {
        $sql = 'SELECT u.username, u.max_connections, u.enabled, u.exp_date,
                       COALESCE(l.allowed_ips, \'\') AS allowed_ips
                  FROM xui_users_cache u
             LEFT JOIN cdn_user_ip_lock l ON l.username = u.username
                 WHERE u.enabled = 1
                 ORDER BY u.username ASC';

        try {
            $rows = Database::pdo()->query($sql)->fetchAll();
        } catch (Throwable $e) {
            error_log('[lb-contract] usersBlock: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $ips = UserIpLock::parseList((string) $r['allowed_ips']);
            $out[] = [
                'username' => (string) $r['username'],
                'max_connections' => (int) $r['max_connections'],
                'exp_date' => (string) ($r['exp_date'] ?? ''),
                'allowed_ips' => $ips,
                'ip_locked' => $ips !== [],
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------- eventos

    /**
     * Aplica um lote de eventos vindo do músculo.
     *
     * Estratégia: o evento é traduzido para um RequestContext sintético e
     * passa pelas MESMAS classes do caminho quente. Assim regra de negócio
     * existe em UM lugar só (PHP no cérebro), e o motor Go pode ser burro e
     * rápido no músculo.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array{accepted:int,rejected:int,errors:array<int,string>}
     */
    public static function applyEvents(int $lbId, array $events): array
    {
        $accepted = 0;
        $rejected = 0;
        $errors = [];

        foreach ($events as $i => $event) {
            if (!is_array($event)) {
                $rejected++;
                $errors[] = '#' . $i . ' evento não é objeto';
                continue;
            }

            $type = (string) ($event['type'] ?? '');
            if (!in_array($type, self::EVENT_TYPES, true)) {
                $rejected++;
                $errors[] = '#' . $i . ' tipo desconhecido: ' . substr($type, 0, 40);
                continue;
            }

            try {
                self::applyOne($lbId, $type, $event);
                $accepted++;
            } catch (Throwable $e) {
                $rejected++;
                $errors[] = '#' . $i . ' ' . $type . ': ' . $e->getMessage();
                error_log('[lb-events] lb=' . $lbId . ' ' . $type . ': ' . $e->getMessage());
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    /** @param array<string,mixed> $event */
    private static function applyOne(int $lbId, string $type, array $event): void
    {
        if ($type === 'heartbeat') {
            LbTelemetry::record($lbId, [
                'cpu_pct' => (float) ($event['cpu_pct'] ?? 0),
                'ram_used_mb' => (float) ($event['ram_used_mb'] ?? 0),
                'ram_free_mb' => (float) ($event['ram_free_mb'] ?? 0),
                'disk_used_gb' => (float) ($event['disk_used_gb'] ?? 0),
                'rx_mbps' => (float) ($event['rx_mbps'] ?? 0),
                'tx_mbps' => (float) ($event['tx_mbps'] ?? 0),
                'sessions_active' => (int) ($event['sessions_active'] ?? 0),
                'users_active' => (int) ($event['users_active'] ?? 0),
                'errors_5m' => (int) ($event['errors_5m'] ?? 0),
            ], 'contract');
            return;
        }

        $ctx = self::contextFromEvent($event);
        $key = (string) ($event['session_key'] ?? '');

        switch ($type) {
            case 'session_open':
            case 'session_touch':
                $key = CdnSession::touch($ctx);
                if ($key !== '' && $lbId > 0) {
                    CdnSession::tagLb($key, $lbId);
                }
                break;

            case 'request':
                if ($key === '') {
                    $key = CdnSession::touch($ctx);
                }
                if ($key !== '') {
                    CdnSession::record(
                        $key,
                        (int) ($event['status'] ?? 200),
                        (int) ($event['bytes'] ?? 0),
                        (string) ($event['direct_host'] ?? '')
                    );
                    if ($lbId > 0) {
                        CdnSession::tagLb($key, $lbId);
                    }
                }
                // Trilha auditável: abre e fecha o evento no cérebro para o
                // request que o músculo JÁ serviu (origin_id/token_id vêm do
                // músculo apenas como referência, nunca como autorização).
                RequestLog::open(
                    $ctx,
                    ((int) ($event['origin_id'] ?? 0)) ?: null,
                    null,
                    'lb_served',
                    $key
                );
                RequestLog::close(
                    $ctx,
                    (int) ($event['status'] ?? 200),
                    (int) ($event['bytes'] ?? 0),
                    'lb_served',
                    (string) ($event['inconsistency'] ?? ''),
                    (string) ($event['direct_host'] ?? ''),
                    (int) ($event['hops'] ?? 0)
                );
                break;

            case 'session_close':
                if ($key !== '') {
                    CdnSession::heartbeat($key, (string) ($event['direct_host'] ?? ''));
                }
                StateStore::sessionClose($key, $ctx->username, 'lb_close');
                break;

            case 'session_reject':
                if ($key === '') {
                    $key = CdnSession::keyFor($ctx);
                }
                CdnSession::reject($key, substr((string) ($event['reason'] ?? 'rejected'), 0, 60));
                break;
        }
    }

    /**
     * Reconstrói o contexto rastreável a partir do evento. O músculo manda o
     * path e a query originais; a classificação de rota, o fingerprint e o
     * mascaramento continuam sendo feitos pelo cérebro — nunca confiamos na
     * classificação do músculo.
     *
     * @param array<string,mixed> $event
     */
    private static function contextFromEvent(array $event): RequestContext
    {
        $host = (string) ($event['host'] ?? '');
        $ip = (string) ($event['client_ip'] ?? '');
        $path = (string) ($event['path'] ?? '/');

        $query = [];
        $rawQuery = (string) ($event['query'] ?? '');
        if ($rawQuery !== '') {
            parse_str($rawQuery, $query);
        }
        if (!isset($query['username']) && !empty($event['username'])) {
            $query['username'] = (string) $event['username'];
        }
        if (!isset($query['password']) && !empty($event['password'])) {
            $query['password'] = (string) $event['password'];
        }

        $ctx = RequestContext::build($host, $ip, $path, $query);

        if (!empty($event['user_agent'])) {
            $ctx->userAgent = substr((string) $event['user_agent'], 0, 300);
        }
        if (!empty($event['request_id'])) {
            $ctx->requestId = substr((string) $event['request_id'], 0, 64);
        }

        return $ctx;
    }

    /** Compat de major: músculo mais novo/velho não corrompe o cérebro. */
    public static function versionCompatible(string $reported): bool
    {
        $major = (int) explode('.', $reported)[0];
        return $major === self::MAJOR;
    }
}