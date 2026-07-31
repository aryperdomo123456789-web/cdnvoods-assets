<?php

/**
 * Sessão LÓGICA da própria CDN.
 *
 * Request != conexão. Um player abre dezenas de requests HLS por minuto e o
 * XUI enxerga isso como uma sessão só (ou nem enxerga, no caso de direct
 * source). Aqui a CDN passa a ter contador PRÓPRIO, independente do XUI:
 *
 *  - agrupa requests correlatos (mesmo usuário + IP + player + tipo + stream)
 *    numa sessão com início, atividade, idle timeout e encerramento;
 *  - deduplica bursts de HLS (todos caem na mesma session_key);
 *  - conta conexões ativas mesmo quando o XUI não vê nada (direct source).
 *
 * Custo por request: 1 UPSERT (open) + 1 UPDATE (close). Nada de SELECT
 * pesado no caminho do stream.
 */
final class CdnSession
{
    public const UPTIME_RESUME_GRACE = [
        'movie'  => 1800,
        'series' => 1800,
        'live'   => 120,
        'hls'    => 120,
        'other'  => 600,
    ];

    /** Timeout de ociosidade por tipo de consumo (segundos). */
    public const IDLE = [
        'playlist' => 180,   // get.php / m3u — o player baixa e some
        'api'      => 180,   // player_api / xmltv / panel_api
        'live'     => 45,    // live + segmentos do live
        'movie'    => 120,
        'series'   => 120,
        'hls'      => 45,
        'other'    => 60,
    ];

    /**
     * Teto de vida de um request "em voo" (in_flight).
     *
     * `active_requests > 0` mantinha a sessão viva mesmo depois de o request
     * morrer sem `record()` (cliente derrubou a conexão, worker do FPM foi
     * morto, timeout de rede). Resultado: contador inflado por horas e
     * sessão fantasma piscando no painel. Passado este teto sem NENHUM
     * heartbeat, o in_flight deixa de contar e o sweep o zera.
     */
    public const IN_FLIGHT_MAX = 900;

    /**
     * Janela expandida para consumo DIRECT de VOD.
     *
     * Em IBO Player / XCIPTV um filme/série pode virar um único request longo
     * ou um fetch inicial seguido de buffer local. Se a CDN encerrar a sessão
     * com 120s, o painel perde a trilha mesmo com o app ainda reproduzindo.
     */
    public const DIRECT_IDLE = [
        'movie'  => 7200,
        'series' => 7200,
        'other'  => 1800,
    ];

    public static function effectiveExpirySql(string $table = 'cdn_sessions'): string
    {
        return '(CASE
            WHEN ' . $table . '.direct_source = 1 AND ' . $table . '.session_kind = \'movie\'
                THEN ' . $table . '.last_seen_epoch + MAX(' . $table . '.idle_timeout, ' . self::DIRECT_IDLE['movie'] . ')
            WHEN ' . $table . '.direct_source = 1 AND ' . $table . '.session_kind = \'series\'
                THEN ' . $table . '.last_seen_epoch + MAX(' . $table . '.idle_timeout, ' . self::DIRECT_IDLE['series'] . ')
            WHEN ' . $table . '.direct_source = 1 AND ' . $table . '.session_kind = \'other\'
                THEN ' . $table . '.last_seen_epoch + MAX(' . $table . '.idle_timeout, ' . self::DIRECT_IDLE['other'] . ')
            ELSE ' . $table . '.last_seen_epoch + ' . $table . '.idle_timeout
        END)';
    }

    /**
     * Tolerância de RETOMADA do uptime.
     *
     * Pausa curta (buffer, troca de faixa, tela bloqueada, reconexão de Wi-Fi)
     * não pode zerar o uptime: o painel passava a mostrar "assistindo há 4s"
     * para quem estava no mesmo filme há duas horas. A janela vale por TIPO de
     * consumo, com ou sem `direct source` — antes só sessão direct tinha
     * tolerância, então VOD normal resetava a cada pausa acima do idle.
     */
    private static function resumeGraceSql(string $table = 'cdn_sessions'): string
    {
        return '(CASE
            WHEN ' . $table . '.session_kind = \'movie\'
                THEN ' . self::UPTIME_RESUME_GRACE['movie'] . '
            WHEN ' . $table . '.session_kind = \'series\'
                THEN ' . self::UPTIME_RESUME_GRACE['series'] . '
            WHEN ' . $table . '.session_kind = \'live\'
                THEN ' . self::UPTIME_RESUME_GRACE['live'] . '
            WHEN ' . $table . '.session_kind = \'hls\'
                THEN ' . self::UPTIME_RESUME_GRACE['hls'] . '
            WHEN ' . $table . '.session_kind = \'other\'
                THEN ' . self::UPTIME_RESUME_GRACE['other'] . '
            ELSE 0
        END)';
    }

    public static function activeWhereSql(int $now, string $table = 'cdn_sessions'): string
    {
        // O CASE do expiry não é indexável: o SQLite varria a tabela inteira em
        // TODO COUNT do painel. A maior janela possível é DIRECT_IDLE['movie'],
        // então este pré-filtro é um superconjunto seguro (não muda resultado)
        // e usa idx_cdnsess_status(status, last_seen_epoch).
        $floor = $now - (int) max(self::DIRECT_IDLE) - (int) max(self::IDLE);
        return '(' . $table . '.status = \'active\')
            AND ' . $table . '.last_seen_epoch >= ' . $floor . '
            AND (
                (' . $table . '.active_requests > 0
                    AND ' . $table . '.last_seen_epoch >= ' . ($now - self::IN_FLIGHT_MAX) . ')
                OR ' . self::effectiveExpirySql($table) . ' >= ' . $now . '
            )';
    }

    /**
     * Tráfego gerado dentro da própria VPS (smoke, curl local, dev harness) não
     * pode poluir o painel ao vivo do operador.
     */
    public static function publicClientWhereSql(string $table = 'cdn_sessions'): string
    {
        if (self::countsLoopback()) {
            return '(1 = 1)';
        }

        return '(' . $table . '.client_ip NOT IN (\'127.0.0.1\', \'::1\', \'\', \'-\'))';
    }

    /**
     * Em produção o loopback é ruído (health check, cron, curl local) e fica fora
     * dos KPIs. No laboratório o tráfego real chega por 127.0.0.1, então existe
     * um interruptor explícito para contar loopback sem mudar nada em produção.
     */
    public static function countsLoopback(): bool
    {
        static $flag = null;

        if ($flag === null) {
            $flag = getenv('CDN_LAB_COUNT_LOOPBACK') === '1'
                || (int) SettingsRepository::get('lab_count_loopback', 0) === 1;
        }

        return (bool) $flag;
    }

    public static function directEffectiveSql(string $table = 'cdn_sessions'): string
    {
        return '(CASE
            WHEN ' . $table . '.direct_source = 1 THEN 1
            WHEN ' . $table . '.stream_id > 0
             AND ' . $table . '.stream_id IN (SELECT stream_id FROM direct_stream_state WHERE direct_flag_db = 1)
                THEN 1
            ELSE 0
        END)';
    }

    public static function enabled(): bool
    {
        return (int) SettingsRepository::get('cdn_sessions_enabled', 1) === 1;
    }

    /** Agrupa route_kind em "tipo de conexão" da CDN. */
    public static function kindOf(RequestContext $ctx): string
    {
        switch ($ctx->routeKind) {
            case 'm3u':     return 'playlist';
            case 'api':     return 'api';
            case 'live':    return 'live';
            case 'movie':   return 'movie';
            case 'series':  return 'series';
            case 'hls':     return 'hls';
            case 'segment': return $ctx->streamId ? 'live' : 'other';
        }
        return 'other';
    }

    /**
     * Chave estável da sessão. Playlist/API não dependem de stream_id;
     * consumo de vídeo é por stream para separar duas telas do mesmo login.
     */
    public static function keyFor(RequestContext $ctx): string
    {
        $kind = self::kindOf($ctx);
        $streamPart = in_array($kind, ['playlist', 'api'], true) ? '-' : (string) ((int) $ctx->streamId);
        $identity = $ctx->username !== '' ? $ctx->username : ('fp:' . substr($ctx->fingerprint, 0, 16));
        return substr(hash('sha256', implode('|', [
            $identity,
            $ctx->clientIp,
            strtolower(substr($ctx->userAgent, 0, 120)),
            $kind,
            $streamPart,
        ])), 0, 32);
    }

    /** Abre (ou reaproveita) a sessão local deste request. Retorna a chave. */
    public static function touch(RequestContext $ctx): string
    {
        if (!self::enabled() || ($ctx->username === '' && $ctx->fingerprint === '')) {
            return '';
        }
        $kind = self::kindOf($ctx);
        $key = self::keyFor($ctx);
        $now = time();
        $streamId = (int) ($ctx->streamId ?? 0);
        $directDb = $streamId > 0 ? DirectCatalog::dbHostFor($streamId) : ['direct' => 0, 'host' => ''];
        $directFlag = (int) ($directDb['direct'] ?? 0) === 1;
        $directHostDb = (string) ($directDb['host'] ?? '');
        $idle = $directFlag
            ? (self::DIRECT_IDLE[$kind] ?? self::DIRECT_IDLE['other'])
            : (self::IDLE[$kind] ?? 60);
        $ok = Database::write(static function (PDO $pdo) use ($ctx, $key, $kind, $now, $idle, $streamId, $directFlag, $directHostDb): void {
            $pdo->prepare(
                'INSERT INTO cdn_sessions
                   (session_key, username, credential_fingerprint, client_ip, user_agent, public_host,
                    session_kind, last_route_kind, stream_id, started_at, started_epoch, uptime_start_epoch,
                    last_seen_at, last_seen_epoch, idle_timeout, status, requests, last_request_id,
                    direct_source, direct_mode, direct_host_db, direct_host_effective, direct_first_epoch, direct_last_epoch,
                    active_requests, last_open_epoch)
                 VALUES (:k,:u,:f,:ip,:ua,:h,:kind,:rk,:sid,:sa,:se,:use,:la,:le,:idle,\'active\',1,:rid,
                         :ds,:dm,:hdb,:heff,:dfe,:dle,1,:le2)
                 ON CONFLICT(session_key) DO UPDATE SET
                   last_seen_at=excluded.last_seen_at,
                   last_seen_epoch=excluded.last_seen_epoch,
                   last_route_kind=excluded.last_route_kind,
                   public_host=excluded.public_host,
                   idle_timeout=excluded.idle_timeout,
                   active_requests=cdn_sessions.active_requests + 1,
                   last_open_epoch=excluded.last_seen_epoch,
                   requests=cdn_sessions.requests + 1,
                   last_request_id=excluded.last_request_id,
                   status=\'active\',
                   close_reason=\'\',
                   ended_epoch=0,
                   direct_source=CASE
                        WHEN excluded.direct_source = 1 THEN 1
                        ELSE cdn_sessions.direct_source
                   END,
                   direct_mode=CASE
                        WHEN excluded.direct_source = 1 AND cdn_sessions.direct_host_runtime <> \'\' THEN \'db_runtime\'
                        WHEN excluded.direct_source = 1 THEN excluded.direct_mode
                        ELSE cdn_sessions.direct_mode
                   END,
                   direct_host_db=CASE
                        WHEN excluded.direct_host_db <> \'\' THEN excluded.direct_host_db
                        ELSE cdn_sessions.direct_host_db
                   END,
                   direct_host_effective=CASE
                        WHEN cdn_sessions.direct_host_runtime <> \'\' THEN cdn_sessions.direct_host_runtime
                        WHEN excluded.direct_host_effective <> \'\' THEN excluded.direct_host_effective
                        ELSE cdn_sessions.direct_host_effective
                   END,
                   direct_first_epoch=CASE
                        WHEN excluded.direct_source = 1 AND cdn_sessions.direct_first_epoch = 0 THEN excluded.direct_first_epoch
                        ELSE cdn_sessions.direct_first_epoch
                   END,
                   direct_last_epoch=CASE
                        WHEN excluded.direct_source = 1 THEN excluded.direct_last_epoch
                        ELSE cdn_sessions.direct_last_epoch
                   END,
                   uptime_start_epoch=CASE
                        WHEN cdn_sessions.uptime_start_epoch = 0 THEN excluded.uptime_start_epoch
                        WHEN (excluded.last_seen_epoch - cdn_sessions.last_seen_epoch) <= ' . self::resumeGraceSql('cdn_sessions') . '
                            THEN cdn_sessions.uptime_start_epoch
                        WHEN cdn_sessions.status <> \'active\'
                         OR (excluded.last_seen_epoch - cdn_sessions.last_seen_epoch) > cdn_sessions.idle_timeout
                            THEN excluded.uptime_start_epoch
                        ELSE cdn_sessions.uptime_start_epoch
                   END,
                   started_epoch=CASE WHEN cdn_sessions.status <> \'active\'
                        OR (excluded.last_seen_epoch - cdn_sessions.last_seen_epoch) > cdn_sessions.idle_timeout
                        THEN excluded.started_epoch ELSE cdn_sessions.started_epoch END,
                   started_at=CASE WHEN cdn_sessions.status <> \'active\'
                        OR (excluded.last_seen_epoch - cdn_sessions.last_seen_epoch) > cdn_sessions.idle_timeout
                        THEN excluded.started_at ELSE cdn_sessions.started_at END'
            )->execute([
                ':k' => $key, ':u' => $ctx->username, ':f' => $ctx->fingerprint,
                ':ip' => $ctx->clientIp, ':ua' => substr($ctx->userAgent, 0, 200), ':h' => $ctx->publicHost,
                ':kind' => $kind, ':rk' => $ctx->routeKind, ':sid' => $streamId,
                ':sa' => date('c', $now), ':se' => $now, ':use' => $now,
                ':la' => date('c', $now), ':le' => $now, ':idle' => $idle,
                ':rid' => $ctx->requestId,
                ':ds' => $directFlag ? 1 : 0,
                ':dm' => $directFlag ? 'db_only' : 'none',
                ':hdb' => $directHostDb,
                ':heff' => $directHostDb,
                ':dfe' => $directFlag ? $now : 0,
                ':dle' => $directFlag ? $now : 0,
                ':le2' => $now,
            ]);
        }, 'cdnsession.touch');
        if ($ok) {
            self::supersedePrevious($ctx, $key, $kind, $streamId, $now);
        }
        return $ok ? $key : '';
    }

    /**
     * Troca de filme/série NÃO é conexão nova.
     *
     * Quando o mesmo usuário, no mesmo IP e no mesmo app, abre outro VOD, a
     * sessão anterior de VOD tem que morrer na hora como `superseded`. Sem
     * isso o contador da CDN inflava (cada filme zapeado virava +1 conexão
     * viva por até 2h por causa do DIRECT_IDLE) e envenenava o limite do XUI.
     *
     * Live fica de fora de propósito: duas telas ao vivo são duas conexões
     * reais e devem continuar contando.
     */
    private static function supersedePrevious(
        RequestContext $ctx,
        string $key,
        string $kind,
        int $streamId,
        int $now
    ): void {
        if (!in_array($kind, ['movie', 'series'], true) || $streamId <= 0) {
            return;
        }
        $identity = $ctx->username !== '' ? $ctx->username : '';
        Database::write(static function (PDO $pdo) use ($identity, $ctx, $key, $now): void {
            $pdo->prepare(
                'UPDATE cdn_sessions
                    SET status = \'closed\',
                        close_reason = \'superseded\',
                        ended_epoch = :now,
                        active_requests = 0
                  WHERE status = \'active\'
                    AND session_key <> :k
                    AND session_kind IN (\'movie\',\'series\')
                    AND client_ip = :ip
                    AND user_agent = :ua
                    AND ((:u <> \'\' AND username = :u2) OR (:u3 = \'\' AND credential_fingerprint = :f))'
            )->execute([
                ':now' => $now,
                ':k' => $key,
                ':ip' => $ctx->clientIp,
                ':ua' => substr($ctx->userAgent, 0, 200),
                ':u' => $identity,
                ':u2' => $identity,
                ':u3' => $identity,
                ':f' => $ctx->fingerprint,
            ]);
        }, 'cdnsession.supersede', 2);
    }

    /** Fecha o ciclo do request dentro da sessão (bytes, erro, direct source). */
    public static function record(string $key, int $status, int $bytes, string $directHost = ''): void
    {
        if ($key === '') { return; }
        Database::write(static function (PDO $pdo) use ($key, $status, $bytes, $directHost): void {
            $pdo->prepare(
                'UPDATE cdn_sessions
                    SET bytes = bytes + :b,
                        errors = errors + :e,
                        last_seen_at = :la,
                        last_seen_epoch = :le,
                        active_requests = CASE WHEN active_requests > 0 THEN active_requests - 1 ELSE 0 END,
                        last_close_epoch = :lce,
                        idle_timeout = CASE
                            WHEN :dh <> \'\' AND session_kind = \'movie\' AND idle_timeout < ' . self::DIRECT_IDLE['movie'] . '
                                THEN ' . self::DIRECT_IDLE['movie'] . '
                            WHEN :dh <> \'\' AND session_kind = \'series\' AND idle_timeout < ' . self::DIRECT_IDLE['series'] . '
                                THEN ' . self::DIRECT_IDLE['series'] . '
                            WHEN :dh <> \'\' AND session_kind = \'other\' AND idle_timeout < ' . self::DIRECT_IDLE['other'] . '
                                THEN ' . self::DIRECT_IDLE['other'] . '
                            ELSE idle_timeout
                        END,
                        status = \'active\',
                        close_reason = \'\',
                        ended_epoch = 0,
                        direct_source = CASE WHEN :dh <> \'\' THEN 1 ELSE direct_source END,
                        uptime_start_epoch = CASE
                            WHEN :dh <> \'\' AND uptime_start_epoch = 0 AND direct_first_epoch > 0 THEN direct_first_epoch
                            WHEN :dh <> \'\' AND uptime_start_epoch = 0 THEN started_epoch
                            ELSE uptime_start_epoch
                        END,
                        direct_host = CASE WHEN :dh2 <> \'\' THEN :dh3 ELSE direct_host END,
                        -- Coerência de rastreio: o host observado em runtime também
                        -- alimenta as colunas que o painel e a triagem leem, para
                        -- sessão nenhuma ficar com direct sem host final.
                        direct_host_runtime = CASE WHEN :dh4 <> \'\' THEN :dh5 ELSE direct_host_runtime END,
                        direct_host_effective = CASE WHEN :dh6 <> \'\' THEN :dh7 ELSE direct_host_effective END,
                        direct_first_epoch = CASE
                            WHEN :dh8 <> \'\' AND direct_first_epoch = 0 THEN :dfe
                            ELSE direct_first_epoch
                        END,
                        direct_last_epoch = CASE WHEN :dh9 <> \'\' THEN :dle ELSE direct_last_epoch END
                  WHERE session_key = :k'
            )->execute([
                ':b' => max(0, $bytes), ':e' => $status >= 400 ? 1 : 0,
                ':la' => date('c'), ':le' => time(),
                ':lce' => time(),
                ':dh' => $directHost, ':dh2' => $directHost, ':dh3' => $directHost,
                ':dh4' => $directHost, ':dh5' => $directHost,
                ':dh6' => $directHost, ':dh7' => $directHost,
                ':dh8' => $directHost, ':dh9' => $directHost,
                ':dfe' => time(), ':dle' => time(),
                ':k' => $key,
            ]);
        }, 'cdnsession.record');
    }

    /** Marca por qual músculo (LB) esta sessão lógica está passando. */
    public static function tagLb(string $key, int $lbId): void
    {
        if ($key === '' || $lbId <= 0) { return; }
        Database::run(
            'UPDATE cdn_sessions SET lb_id = :l WHERE session_key = :k AND lb_id <> :l2',
            [':l' => $lbId, ':k' => $key, ':l2' => $lbId],
            'cdnsession.tag_lb'
        );
    }

    /**
     * Heartbeat leve para request longo: renova a sessão enquanto o stream
     * ainda está saindo, sem esperar o request terminar.
     */
    public static function heartbeat(string $key, string $directHost = ''): void
    {
        if ($key === '') { return; }
        Database::write(static function (PDO $pdo) use ($key, $directHost): void {
            $pdo->prepare(
                'UPDATE cdn_sessions
                    SET last_seen_at = :la,
                        last_seen_epoch = :le,
                        active_requests = CASE WHEN active_requests < 1 THEN 1 ELSE active_requests END,
                        idle_timeout = CASE
                            WHEN :dh <> \'\' AND session_kind = \'movie\' AND idle_timeout < ' . self::DIRECT_IDLE['movie'] . '
                                THEN ' . self::DIRECT_IDLE['movie'] . '
                            WHEN :dh <> \'\' AND session_kind = \'series\' AND idle_timeout < ' . self::DIRECT_IDLE['series'] . '
                                THEN ' . self::DIRECT_IDLE['series'] . '
                            WHEN :dh <> \'\' AND session_kind = \'other\' AND idle_timeout < ' . self::DIRECT_IDLE['other'] . '
                                THEN ' . self::DIRECT_IDLE['other'] . '
                            ELSE idle_timeout
                        END,
                        status = \'active\',
                        close_reason = \'\',
                        ended_epoch = 0,
                        direct_source = CASE WHEN :dh <> \'\' THEN 1 ELSE direct_source END,
                        uptime_start_epoch = CASE
                            WHEN :dh <> \'\' AND uptime_start_epoch = 0 AND direct_first_epoch > 0 THEN direct_first_epoch
                            WHEN :dh <> \'\' AND uptime_start_epoch = 0 THEN started_epoch
                            ELSE uptime_start_epoch
                        END,
                        direct_host = CASE WHEN :dh2 <> \'\' THEN :dh3 ELSE direct_host END
                  WHERE session_key = :k'
            )->execute([
                ':la' => date('c'),
                ':le' => time(),
                ':dh' => $directHost,
                ':dh2' => $directHost,
                ':dh3' => $directHost,
                ':k' => $key,
            ]);
        }, 'cdnsession.heartbeat', 2);
    }

    /** Cancela imediatamente a sessão recém-aberta quando o request é negado. */
    public static function reject(string $key, string $reason = 'rejected'): void
    {
        if ($key === '') { return; }
        Database::write(static function (PDO $pdo) use ($key, $reason): void {
            $now = time();
            $pdo->prepare(
                'UPDATE cdn_sessions
                    SET active_requests = CASE WHEN active_requests > 0 THEN active_requests - 1 ELSE 0 END,
                        status = CASE
                            WHEN active_requests <= 1 THEN \'closed\'
                            ELSE status
                        END,
                        close_reason = CASE
                            WHEN active_requests <= 1 THEN :reason
                            ELSE close_reason
                        END,
                        ended_epoch = CASE
                            WHEN active_requests <= 1 THEN :now
                            ELSE ended_epoch
                        END,
                        last_close_epoch = :now2,
                        last_seen_at = :seen_at,
                        last_seen_epoch = :seen_epoch
                  WHERE session_key = :k'
            )->execute([
                ':reason' => substr($reason, 0, 60),
                ':now' => $now,
                ':now2' => $now,
                ':seen_at' => date('c', $now),
                ':seen_epoch' => $now,
                ':k' => $key,
            ]);
        }, 'cdnsession.reject', 2);
    }

    /** Conexões ativas AGORA contadas pela CDN (não pelo XUI). */
    public static function activeCount(string $username): int
    {
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM cdn_sessions
              WHERE username = :u AND ' . self::activeWhereSql(time()) . '
                AND ' . self::publicClientWhereSql() . '
                AND session_kind NOT IN (\'playlist\',\'api\')
            '
        );
        $st->execute([':u' => $username]);
        return (int) $st->fetchColumn();
    }

    /** @return array<string,int> username => conexões ativas locais */
    public static function activeCounts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT username, COUNT(*) AS c FROM cdn_sessions
              WHERE ' . self::activeWhereSql(time()) . '
                AND ' . self::publicClientWhereSql() . '
                AND session_kind NOT IN (\'playlist\',\'api\')
              GROUP BY username'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[(string) $r['username']] = (int) $r['c']; }
        return $out;
    }

    /** Job: encerra sessões ociosas e devolve estatísticas auditáveis. */
    public static function sweep(array &$stats): void
    {
        $pdo = Database::pdo();
        $now = time();
        JobRunner::step('encerrar_ociosas');

        // in_flight preso: request morreu sem record() (cliente cortou, worker
        // do FPM morreu). Sem isto a sessão contava viva por horas e o painel
        // via conexão "sumindo e voltando" a cada tick.
        JobRunner::step('soltar_in_flight');
        $inFlight = 0;
        Database::write(static function (PDO $pdo) use ($now, &$inFlight): void {
            $st = $pdo->prepare(
                'UPDATE cdn_sessions
                    SET active_requests = 0,
                        last_close_epoch = CASE WHEN last_close_epoch > 0 THEN last_close_epoch ELSE :now END
                  WHERE status = \'active\'
                    AND active_requests > 0
                    AND last_seen_epoch < :cut'
            );
            $st->execute([':now' => $now, ':cut' => $now - self::IN_FLIGHT_MAX]);
            $inFlight = $st->rowCount();
        }, 'cdnsession.release_in_flight', 2);

        Database::run(
            'UPDATE cdn_sessions
                SET idle_timeout = CASE
                    WHEN session_kind = \'movie\' THEN ' . self::DIRECT_IDLE['movie'] . '
                    WHEN session_kind = \'series\' THEN ' . self::DIRECT_IDLE['series'] . '
                    WHEN session_kind = \'other\' THEN ' . self::DIRECT_IDLE['other'] . '
                    ELSE idle_timeout
                END
              WHERE status = \'active\'
                AND direct_source = 1
                AND active_requests = 0
                AND session_kind IN (\'movie\',\'series\',\'other\')
                AND idle_timeout < ' . self::DIRECT_IDLE['movie'],
            [],
            'cdnsession.normalize_direct_idle'
        );

        // Era 1 SELECT de TODAS as sessões ativas + 1 UPDATE por linha (N+1 em
        // cima do arquivo que o stream está escrevendo — fonte direta de
        // "database is locked"). Agora é UM UPDATE conjunto com a mesma regra
        // de expiração usada nas leituras do painel.
        $expiry = self::effectiveExpirySqlForSweep();
        $closed = 0;
        Database::write(static function (PDO $pdo) use ($expiry, $now, &$closed): void {
            $st = $pdo->prepare(
                'UPDATE cdn_sessions
                    SET status = \'closed\', close_reason = \'idle_timeout\', ended_epoch = :now
                  WHERE status = \'active\' AND ' . $expiry . ' < :now2'
            );
            $st->execute([':now' => $now, ':now2' => $now]);
            $closed = $st->rowCount();
        }, 'cdnsession.sweep');

        // Retenção curta: sessão encerrada há mais de 6h já virou histórico do
        // proxy_request_events; não precisa ficar no contador ao vivo.
        JobRunner::step('retencao');
        Database::write(static function (PDO $pdo) use ($now): void {
            $pdo->exec('DELETE FROM cdn_sessions WHERE status = \'closed\' AND ended_epoch < ' . ($now - 21600));
        }, 'cdnsession.retention');

        JobRunner::step('contar_ativas');
        $active = (int) $pdo->query(
            'SELECT COUNT(*) FROM cdn_sessions WHERE ' . self::activeWhereSql($now)
        )->fetchColumn();

        $stats['processed'] += $closed + $active;
        $stats['details'] = [
            'closed' => $closed,
            'active' => $active,
            'in_flight_released' => $inFlight,
        ];
    }

    /**
     * Expiração para o sweep: mesma regra do painel, mais o teto curto para
     * tráfego interno (smoke/curl local não pode segurar sessão por 2h).
     */
    private static function effectiveExpirySqlForSweep(string $table = 'cdn_sessions'): string
    {
        $internal = '(' . $table . '.client_ip IN (\'127.0.0.1\', \'::1\', \'\', \'-\'))';
        return '(CASE
            WHEN ' . $internal . '
                THEN ' . $table . '.last_seen_epoch + MIN(' . $table . '.idle_timeout, 180)
            ELSE ' . self::effectiveExpirySql($table) . '
        END)';
    }

    /** @return array<int,array<string,mixed>> sessões ao vivo (painel) */
    public static function live(array $filters = [], int $limit = 200): array
    {
        $key = 'sessions_' . md5(json_encode([$filters, $limit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Cache::remember($key, 1, static function () use ($filters, $limit): array {
            $sql = 'SELECT s.*,
                           ' . self::directEffectiveSql('s') . ' AS effective_direct_source,
                           COALESCE(n.label, CASE WHEN s.lb_id > 0 THEN n.public_ip ELSE \'main\' END, \'main\') AS lb_label,
                           COALESCE(n.public_ip, \'\') AS lb_ip
                      FROM cdn_sessions s
                 LEFT JOIN lb_nodes n ON n.id = s.lb_id
                     WHERE ' . self::activeWhereSql(time(), 's');
            $params = [];
            if (empty($filters['include_internal'])) { $sql .= ' AND ' . self::publicClientWhereSql('s'); }
            if (!empty($filters['username'])) { $sql .= ' AND s.username LIKE :u'; $params[':u'] = '%' . $filters['username'] . '%'; }
            if (!empty($filters['kind'])) { $sql .= ' AND s.session_kind = :k'; $params[':k'] = $filters['kind']; }
            if (!empty($filters['ip'])) { $sql .= ' AND s.client_ip LIKE :i'; $params[':i'] = '%' . $filters['ip'] . '%'; }
            if (!empty($filters['direct'])) { $sql .= ' AND ' . self::directEffectiveSql('s') . ' = 1'; }
            $sql .= ' ORDER BY s.last_seen_epoch DESC LIMIT ' . max(1, min(1000, $limit));
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        });
    }

    public static function forUser(string $username, int $limit = 50): array
    {
        $st = Database::pdo()->prepare(
            'SELECT * FROM cdn_sessions WHERE username = :u
              ORDER BY status ASC, last_seen_epoch DESC LIMIT ' . max(1, min(200, $limit))
        );
        $st->execute([':u' => $username]);
        return $st->fetchAll();
    }
}
