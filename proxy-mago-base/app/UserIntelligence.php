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
            $activeWhere = CdnSession::activeWhereSql($now);
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
            $sql = 'SELECT
                  u.user_id, u.username, u.max_connections, u.enabled, u.exp_date,
                  u.is_trial, u.is_restreamer, u.synced_at,
                  COALESCE(v.c, 0)  AS cdn_connections_now,
                  COALESCE(v.fc, 0) AS fetch_sessions_now,
                  COALESCE(v.dc, 0) AS direct_sessions_now,
                  COALESCE(x.c, 0)  AS xui_connections_now,
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
                FROM xui_users_cache u
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
                ) v ON v.username = u.username
                LEFT JOIN (
                  SELECT user_id, COUNT(*) c FROM xui_activity_now_cache GROUP BY user_id
                ) x ON x.user_id = u.user_id
                LEFT JOIN proxy_user_runtime r ON r.username = u.username
                LEFT JOIN lb_user_routes route ON route.username = u.username
                LEFT JOIN lb_nodes route_lb ON route_lb.id = route.lb_id
                WHERE 1=1';
            $params = [];
            if (!empty($filters['q'])) {
                $sql .= ' AND u.username LIKE :q';
                $params[':q'] = '%' . $filters['q'] . '%';
            }
            if (!empty($filters['enabled_only'])) { $sql .= ' AND u.enabled = 1'; }
            if (!empty($filters['only_active'])) {
                $sql .= ' AND (COALESCE(v.c,0) > 0 OR COALESCE(v.fc,0) > 0 OR COALESCE(x.c,0) > 0)';
            }
            if (!empty($filters['over_limit'])) {
                $sql .= ' AND u.max_connections > 0 AND MAX(COALESCE(v.c,0), COALESCE(x.c,0)) > u.max_connections';
            }
            $sql .= ' ORDER BY MAX(COALESCE(v.c,0), COALESCE(x.c,0)) DESC,
                           COALESCE(r.last_activity_epoch,0) DESC, u.username ASC
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
                $r['online'] = $used > 0 || (int) $r['fetch_sessions_now'] > 0;
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
        $metrics = RestreamRuntime::latestMetrics([
            'connections_active',
            'fetch_active',
            'users_active',
        ]);
        $video = (int) ($metrics['connections_active'] ?? 0);
        $fetch = (int) ($metrics['fetch_active'] ?? 0);
        $onlineUsers = (int) ($metrics['users_active'] ?? 0);
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
            'generated_at' => date('c'),
        ];
    }

    /** Detalhe de um usuário: plano + conexões abertas agora, uma a uma. */
    public static function detail(string $username): array
    {
        $rows = self::users(['q' => $username], 5);
        $user = null;
        foreach ($rows as $r) {
            if (strcasecmp((string) $r['username'], $username) === 0) { $user = $r; break; }
        }
        return [
            'user' => $user,
            'connections' => CdnSession::live(['username' => $username], 50),
        ];
    }
}
