<?php

/**
 * Inteligência de usuários da CDN.
 *
 * Responde a pergunta central do painel: "quais usuários existem no XUI,
 * quantas conexões cada um pode usar e quantas está usando AGORA?".
 *
 * A base é o espelho read-only do XUI (xui_users_cache) — ou seja, o usuário
 * aparece na lista mesmo sem nenhum request na CDN. Em cima disso entram:
 *
 *  - cdn_sessions            → contador PRÓPRIO da CDN (pega direct source)
 *  - xui_activity_now_cache  → o que o XUI enxerga (não pega direct source)
 *  - proxy_user_runtime      → último host/IP/player observados
 *
 * Conexão "de vídeo" = live/movie/series/hls/other. Playlist e API são
 * contadas à parte: baixar o m3u NÃO é ocupar conexão do plano, mas some do
 * painel se ninguém mostrar — por isso viram coluna própria.
 */
final class UserIntelligence
{
    /** Tipos de sessão que consomem conexão do plano. */
    public const VIDEO_KINDS = "('live','movie','series','hls','other','segment')";
    /** Tipos que são apenas fetch (não ocupam slot). */
    public const FETCH_KINDS = "('playlist','api')";

    /**
     * @param array<string,mixed> $filters q, only_active, over_limit, enabled_only
     * @return array<int,array<string,mixed>>
     */
    public static function users(array $filters = [], int $limit = 200): array
    {
        $key = 'users_' . md5(json_encode([$filters, $limit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Cache::remember($key, 3, static function () use ($filters, $limit): array {
            $now = time();
            $recentCut = $now - 120;
            $activeWhere = CdnSession::activeWhereSql($now);
            $publicClientWhere = CdnSession::publicClientWhereSql();
            $runtimeUptimeSql = Database::tableHasColumn('proxy_user_runtime', 'uptime_start_epoch')
                ? 'COALESCE(r.uptime_start_epoch, 0)'
                : '0';
            $runtimeLbSql = Database::tableHasColumn('proxy_user_runtime', 'last_lb_label')
                ? 'COALESCE(r.last_lb_label, \'main\')'
                : '\'main\'';
            $lbLabelsSql = Database::isPgsql()
                ? "STRING_AGG(DISTINCT CASE
                            WHEN lb_id > 0 THEN COALESCE(lb.label, lb.public_ip, 'LB#' || lb_id::text)
                            ELSE 'main'
                         END, ',')"
                : 'GROUP_CONCAT(DISTINCT CASE
                            WHEN lb_id > 0 THEN COALESCE(lb.label, lb.public_ip, "LB#" || lb_id)
                            ELSE "main"
                         END)';
            $sql = 'WITH base_users AS (
                    SELECT username FROM xui_users_cache
                    UNION
                    SELECT username
                      FROM proxy_user_runtime
                     WHERE username <> \'\'
                       AND (
                            last_activity_epoch >= ' . $recentCut . '
                            OR requests_5m > 0
                            OR active_connections_now > 0
                            OR xui_connections_now > 0
                       )
                    UNION
                    SELECT username
                      FROM cdn_sessions
                     WHERE ' . $activeWhere . '
                       AND ' . $publicClientWhere . '
                       AND username <> \'\'
                )
                SELECT
                  COALESCE(u.user_id, r.user_id, 0) AS user_id,
                  b.username,
                  COALESCE(u.max_connections, r.max_connections, 0) AS max_connections,
                  COALESCE(u.enabled, 1) AS enabled,
                  COALESCE(u.exp_date, \'\') AS exp_date,
                  COALESCE(u.is_trial, 0) AS is_trial,
                  COALESCE(u.is_restreamer, 0) AS is_restreamer,
                  COALESCE(u.synced_at, \'\') AS synced_at,
                  COALESCE(v.c, 0)  AS cdn_connections_now,
                  COALESCE(v.fc, 0) AS fetch_sessions_now,
                  COALESCE(v.dc, 0) AS direct_sessions_now,
                  COALESCE(x.c, COALESCE(r.xui_connections_now, 0)) AS xui_connections_now,
                  COALESCE(v.bytes, 0) AS bytes_now,
                  COALESCE(r.public_host_last_seen, \'\') AS last_host,
                  COALESCE(r.client_ip_last_seen, \'\')   AS last_ip,
                  COALESCE(r.user_agent_last_seen, \'\')  AS last_player,
                  COALESCE(r.last_route_kind, \'\')       AS last_kind,
                  COALESCE(r.last_activity_epoch, 0)    AS last_epoch,
                  ' . $runtimeUptimeSql . '             AS uptime_start_epoch,
                  ' . $runtimeLbSql . '                 AS last_lb_label,
                  COALESCE(r.requests_5m, 0)            AS requests_5m,
                  COALESCE(r.bytes_5m, 0)               AS bytes_5m,
                  COALESCE(v.last_seen_epoch, 0)        AS session_epoch,
                  COALESCE(v.lb_labels, \'main\')       AS lb_labels,
                  COALESCE(route.lb_id, 0)              AS route_lb_id,
                  COALESCE(route.mode, \'main_only\')   AS route_mode,
                  COALESCE(route_lb.label, CASE WHEN COALESCE(route.lb_id, 0) > 0 THEN route_lb.public_ip ELSE \'main\' END, \'main\') AS route_lb_label
                FROM base_users b
                -- Uma passada só nas sessões ativas: antes eram 3 subqueries
                -- idênticas (vídeo, fetch, direct) varrendo cdn_sessions.
                LEFT JOIN (
                  SELECT username,
                         SUM(CASE WHEN session_kind IN ' . self::VIDEO_KINDS . ' THEN 1 ELSE 0 END) AS c,
                         SUM(CASE WHEN session_kind IN ' . self::VIDEO_KINDS . ' THEN bytes ELSE 0 END) AS bytes,
                         SUM(CASE WHEN session_kind IN ' . self::FETCH_KINDS . ' THEN 1 ELSE 0 END) AS fc,
                         SUM(CASE WHEN ' . CdnSession::directEffectiveSql('cdn_sessions') . ' = 1 THEN 1 ELSE 0 END) AS dc,
                         MAX(cdn_sessions.last_seen_epoch) AS last_seen_epoch,
                         ' . $lbLabelsSql . ' AS lb_labels
                    FROM cdn_sessions
               LEFT JOIN lb_nodes lb ON lb.id = cdn_sessions.lb_id
                   WHERE ' . $activeWhere . '
                     AND ' . CdnSession::publicClientWhereSql() . '
                   GROUP BY username
                ) v ON v.username = b.username
                LEFT JOIN xui_users_cache u ON u.username = b.username
                LEFT JOIN proxy_user_runtime r ON r.username = b.username
                LEFT JOIN (
                  SELECT user_id, COUNT(*) c FROM xui_activity_now_cache GROUP BY user_id
                ) x ON x.user_id = COALESCE(u.user_id, r.user_id, 0)
                LEFT JOIN lb_user_routes route ON route.username = b.username
                LEFT JOIN lb_nodes route_lb ON route_lb.id = route.lb_id
                WHERE 1=1';
            $params = [];
            if (!empty($filters['q'])) {
                $sql .= ' AND b.username LIKE :q';
                $params[':q'] = '%' . $filters['q'] . '%';
            }
            if (!empty($filters['enabled_only'])) { $sql .= ' AND COALESCE(u.enabled, 1) = 1'; }
            if (!empty($filters['only_active'])) {
                $sql .= ' AND (
                    COALESCE(v.c,0) > 0
                    OR COALESCE(v.fc,0) > 0
                    OR COALESCE(x.c,0) > 0
                    OR (
                        COALESCE(r.last_activity_epoch,0) >= :recent_cut
                        AND (
                            COALESCE(r.requests_5m,0) > 0
                            OR COALESCE(r.bytes_5m,0) > 0
                            OR COALESCE(r.client_ip_last_seen, \'\') <> \'\'
                        )
                    )
                )';
                $params[':recent_cut'] = $recentCut;
            }
            $peakUsageSql = Database::isPgsql()
                ? 'GREATEST(COALESCE(v.c,0), COALESCE(x.c,0))'
                : 'MAX(COALESCE(v.c,0), COALESCE(x.c,0))';
            if (!empty($filters['over_limit'])) {
                $sql .= ' AND COALESCE(u.max_connections, r.max_connections, 0) > 0 AND ' . $peakUsageSql . ' > COALESCE(u.max_connections, r.max_connections, 0)';
            }
            $sql .= ' ORDER BY ' . $peakUsageSql . ' DESC,
                           COALESCE(r.last_activity_epoch,0) DESC, b.username ASC
                  LIMIT ' . max(1, min(2000, $limit));

            $st = Database::pdo()->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();

            foreach ($rows as &$r) {
                $cdn = (int) $r['cdn_connections_now'];
                $xui = (int) $r['xui_connections_now'];
                $max = (int) $r['max_connections'];
                $used = max($cdn, $xui);
                $r['connections_used'] = $used;
                $r['connections_free'] = $max > 0 ? max(0, $max - $used) : null;
                $r['usage_pct'] = $max > 0 ? (int) round($used * 100 / $max) : 0;
                $r['divergence'] = $cdn - $xui;
                $r['count_source'] = $cdn === $xui ? 'merged' : ($cdn > $xui ? 'cdn_local' : 'xui_activity_now');
                $lastEpoch = (int) ($r['last_epoch'] ?? 0);
                $lastKind = strtolower(trim((string) ($r['last_kind'] ?? '')));
                $recentActivity = $lastEpoch >= $recentCut
                    && (
                        (int) ($r['requests_5m'] ?? 0) > 0
                        || (int) ($r['bytes_5m'] ?? 0) > 0
                        || trim((string) ($r['last_ip'] ?? '')) !== ''
                    );
                $recentFetch = $recentActivity && in_array($lastKind, ['playlist', 'api', 'm3u'], true);
                $r['recent_activity'] = $recentActivity ? 1 : 0;
                $r['recent_fetch'] = $recentFetch ? 1 : 0;
                $r['online'] = $used > 0 || (int) $r['fetch_sessions_now'] > 0 || $recentActivity;
                $r['status'] = self::statusOf($r, $used, $max);
            }
            unset($r);
            return $rows;
        });
    }

    /** @param array<string,mixed> $r */
    private static function statusOf(array $r, int $used, int $max): string
    {
        if ((int) $r['enabled'] !== 1) { return 'disabled'; }
        if (self::expired((string) $r['exp_date'])) { return 'expired'; }
        if ($max > 0 && $used > $max) { return 'over_limit'; }
        if ($max > 0 && $used === $max && $used > 0) { return 'full'; }
        if ($used > 0) { return 'streaming'; }
        if ((int) $r['fetch_sessions_now'] > 0) { return 'fetching'; }
        if ((int) ($r['recent_fetch'] ?? 0) === 1) { return 'recent_fetch'; }
        if ((int) ($r['recent_activity'] ?? 0) === 1) { return 'recent'; }
        return 'idle';
    }

    private static function expired(string $expDate): bool
    {
        $exp = trim($expDate);
        if ($exp === '' || $exp === '0') { return false; } // sem validade = eterno no XUI
        $ts = ctype_digit($exp) ? (int) $exp : (strtotime($exp) ?: 0);
        return $ts > 0 && $ts < time();
    }

    /** Totais do parque de usuários (cards do painel). */
    public static function totals(): array
    {
        return Cache::remember('user_totals', 5, static fn(): array => self::totalsFresh());
    }

    public static function totalsFresh(): array
    {
        $pdo = Database::pdo();
        // Antes vinha de `latestMetrics` (rollup). Se o JobRunner atrasa — e o
        // painel real já mostrou `match_sessions atrasado 1698s` — os cards
        // exibem 0 conexão com gente assistindo. Contagem AO VIVO lê a fonte
        // primária (`cdn_sessions`), que o próprio request quente escreve.
        $live = self::liveCounts();
        $video = $live['connections_video'];
        $fetch = $live['sessions_fetch'];
        $onlineUsers = $live['users_online'];
        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM xui_users_cache')->fetchColumn();
        $enabled = (int) $pdo->query('SELECT COUNT(*) FROM xui_users_cache WHERE enabled = 1')->fetchColumn();
        $slots = (int) $pdo->query('SELECT COALESCE(SUM(max_connections),0) FROM xui_users_cache WHERE enabled = 1')->fetchColumn();
        $xuiNow = (int) $pdo->query('SELECT COUNT(*) FROM xui_activity_now_cache')->fetchColumn();
        $over = (int) $pdo->query(
            'SELECT COUNT(*) FROM proxy_user_runtime WHERE max_connections > 0 AND active_connections_now > max_connections'
        )->fetchColumn();

        return [
            'users_total' => $totalUsers,
            'users_enabled' => $enabled,
            'users_online' => $onlineUsers,
            'connections_video' => $video,
            'sessions_fetch' => $fetch,
            'sessions_total' => $video + $fetch,
            'xui_connections' => $xuiNow,
            'slots_sold' => $slots,
            'slots_used_pct' => $slots > 0 ? (int) round($video * 100 / $slots) : 0,
            'over_limit' => $over,
            'source' => 'cdn_sessions_live',
            'generated_at' => date('c'),
        ];
    }

    /**
     * Contagem viva direto de `cdn_sessions`, sem depender de job/rollup.
     *
     * @return array{connections_video:int,sessions_fetch:int,users_online:int}
     */
    public static function liveCounts(): array
    {
        $now = time();
        $where = CdnSession::activeWhereSql($now) . ' AND ' . CdnSession::publicClientWhereSql();
        $row = Database::pdo()->query(
            "SELECT
                SUM(CASE WHEN session_kind NOT IN ('playlist','api') THEN 1 ELSE 0 END) AS video,
                SUM(CASE WHEN session_kind IN ('playlist','api') THEN 1 ELSE 0 END) AS fetch_n,
                COUNT(DISTINCT username) AS users
              FROM cdn_sessions WHERE " . $where
        )->fetch();

        return [
            'connections_video' => (int) ($row['video'] ?? 0),
            'sessions_fetch' => (int) ($row['fetch_n'] ?? 0),
            'users_online' => (int) ($row['users'] ?? 0),
        ];
    }

    /** Segundos -> "1h 04m 12s", que é o que o operador lê no painel. */
    public static function humanUptime(int $seconds): string
    {
        if ($seconds <= 0) { return '0s'; }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) { return sprintf('%dh %02dm %02ds', $h, $m, $s); }
        if ($m > 0) { return sprintf('%dm %02ds', $m, $s); }
        return $s . 's';
    }

    /**
     * Conexões vivas de UM usuário, uma a uma, já resolvidas pela CDN:
     * o que está vendo (canal/filme/série + nome), há quanto tempo, por onde sai.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function connections(string $username, int $limit = 100): array
    {
        $rows = CdnSession::live(['username' => $username], $limit);
        // Filtro exato: `live()` usa LIKE, e "joao" não pode arrastar "joao2".
        $rows = array_values(array_filter(
            $rows,
            static fn(array $r): bool => strcasecmp((string) ($r['username'] ?? ''), $username) === 0
        ));
        $rows = StreamCatalog::enrichSessions($rows);

        $now = time();
        foreach ($rows as &$r) {
            $start = (int) ($r['uptime_start_epoch'] ?? 0);
            if ($start <= 0) { $start = (int) ($r['started_epoch'] ?? 0); }
            $uptime = $start > 0 ? max(0, $now - $start) : 0;
            $r['uptime_seconds'] = $uptime;
            $r['uptime_human'] = self::humanUptime($uptime);
            $r['idle_seconds'] = max(0, $now - (int) ($r['last_seen_epoch'] ?? 0));
            $r['is_video'] = in_array((string) ($r['session_kind'] ?? ''), ['playlist', 'api'], true) ? 0 : 1;
            $r['exit_label'] = (string) ($r['lb_label'] ?? 'main');
            $r['streaming'] = ((int) ($r['active_requests'] ?? 0) > 0) ? 1 : 0;
        }
        unset($r);

        // Quem está transmitindo agora primeiro; depois por uptime maior.
        usort($rows, static function (array $a, array $b): int {
            return [$b['streaming'], $b['uptime_seconds']] <=> [$a['streaming'], $a['uptime_seconds']];
        });
        return $rows;
    }

    /**
     * Trilha viva GLOBAL: cada conexão aberta agora da CDN inteira, já com o
     * conteúdo resolvido (canal / filme / série), uptime, estado e saída.
     *
     * Isto é o que o painel ao vivo mostra: uma linha = uma conexão real.
     *
     * @param array<string,mixed> $filters username, ip, kind (live|movie|series), only_streaming, direct
     * @return array{rows:array<int,array<string,mixed>>,totals:array<string,int>}
     */
    public static function liveConnections(array $filters = [], int $limit = 200): array
    {
        $rows = CdnSession::live([
            'username' => (string) ($filters['username'] ?? ''),
            'ip' => (string) ($filters['ip'] ?? ''),
        ], max(20, min(500, $limit)));
        $rows = StreamCatalog::enrichSessions($rows);

        $now = time();
        $totals = [
            'connections' => 0,
            'live' => 0,
            'movie' => 0,
            'series' => 0,
            'other' => 0,
            'fetch' => 0,
            'streaming' => 0,
            'paused' => 0,
            'direct' => 0,
            'users' => 0,
            'ips' => 0,
        ];
        $users = [];
        $ips = [];
        $out = [];

        foreach ($rows as $r) {
            $kindRoute = (string) ($r['session_kind'] ?? '');
            $isVideo = !in_array($kindRoute, ['playlist', 'api'], true);
            $start = (int) ($r['uptime_start_epoch'] ?? 0);
            if ($start <= 0) { $start = (int) ($r['direct_first_epoch'] ?? 0); }
            if ($start <= 0) { $start = (int) ($r['started_epoch'] ?? 0); }
            $uptime = $start > 0 ? max(0, $now - $start) : 0;
            $idle = max(0, $now - (int) ($r['last_seen_epoch'] ?? 0));
            $streaming = $idle <= 25;

            $r['is_video'] = $isVideo ? 1 : 0;
            $r['uptime_seconds'] = $uptime;
            $r['uptime_human'] = self::humanUptime($uptime);
            $r['idle_seconds'] = $idle;
            $r['streaming'] = $streaming ? 1 : 0;
            $r['live_state'] = $streaming ? 'transmitindo' : ($idle <= 150 ? 'pausado' : 'encerrando');
            $r['exit_label'] = (string) ($r['lb_label'] ?? 'main');
            $r['delivery_effective'] = ((int) ($r['effective_direct_source'] ?? $r['direct_source'] ?? 0) === 1)
                ? 'direct_source'
                : (string) ($r['delivery_mode'] ?? 'restream');

            $ck = $isVideo ? (string) ($r['content_kind'] ?? 'other') : 'fetch';
            if ($ck === 'fetch') {
                $totals['fetch']++;
            } else {
                $totals['connections']++;
                $totals[$ck] = ($totals[$ck] ?? 0) + 1;
                if ($streaming) { $totals['streaming']++; } else { $totals['paused']++; }
                if ($r['delivery_effective'] === 'direct_source') { $totals['direct']++; }
            }
            $u = (string) ($r['username'] ?? '');
            if ($u !== '') { $users[$u] = true; }
            $ip = (string) ($r['client_ip'] ?? '');
            if ($ip !== '') { $ips[$ip] = true; }

            // Filtros de tela (aplicados depois dos totais, para os cards
            // continuarem mostrando o parque inteiro).
            $want = strtolower(trim((string) ($filters['kind'] ?? '')));
            if ($want !== '' && $want !== 'all') {
                if ($want === 'fetch' && $isVideo) { continue; }
                if ($want !== 'fetch' && (!$isVideo || $ck !== $want)) { continue; }
            }
            if (!empty($filters['only_streaming']) && !$streaming) { continue; }
            if (!empty($filters['direct']) && $r['delivery_effective'] !== 'direct_source') { continue; }
            if (empty($filters['include_fetch']) && !$isVideo && ($want === '' || $want === 'all')) { continue; }
            $out[] = $r;
        }

        $totals['users'] = count($users);
        $totals['ips'] = count($ips);

        usort($out, static fn(array $a, array $b): int =>
            [$b['streaming'], $b['uptime_seconds']] <=> [$a['streaming'], $a['uptime_seconds']]);

        return ['rows' => $out, 'totals' => $totals];
    }

    /** Detalhe de um usuário: plano + conexões abertas agora, uma a uma. */
    public static function detail(string $username): array
    {
        $rows = self::users(['q' => $username], 5);
        $user = null;
        foreach ($rows as $r) {
            if (strcasecmp((string) $r['username'], $username) === 0) { $user = $r; break; }
        }
        $connections = self::connections($username, 100);
        $video = 0;
        $fetch = 0;
        $kinds = ['live' => 0, 'movie' => 0, 'series' => 0, 'other' => 0];
        $ips = [];
        foreach ($connections as $c) {
            if ((int) $c['is_video'] === 1) {
                $video++;
                $k = (string) ($c['content_kind'] ?? 'other');
                $kinds[$k] = ($kinds[$k] ?? 0) + 1;
            } else {
                $fetch++;
            }
            $ip = (string) ($c['client_ip'] ?? '');
            if ($ip !== '') { $ips[$ip] = true; }
        }
        $limit = (int) ($user['max_connections'] ?? 0);

        return [
            'user' => $user,
            'connections' => $connections,
            'summary' => [
                'limit' => $limit,
                'in_use' => $video,
                'free' => $limit > 0 ? max(0, $limit - $video) : 0,
                'fetch' => $fetch,
                'distinct_ips' => count($ips),
                'by_kind' => $kinds,
                'over_limit' => $limit > 0 && $video > $limit ? 1 : 0,
            ],
            'ip_lock' => UserIpLock::get($username),
            'generated_at' => date('c'),
            'server_epoch' => time(),
        ];
    }
}
