<?php

/**
 * Jobs de espelhamento read-only do XUI para o SQLite local.
 *
 * Nada aqui roda no caminho do stream. Se o MySQL do XUI cair, o job falha,
 * registra o erro em job_runs/job_state e o painel segue mostrando o último
 * snapshot com estado "degradado".
 */
final class XuiSyncService
{
    private static function usersTable(): string
    {
        if (XuiReadOnly::hasTable('lines')) {
            return 'lines';
        }
        return 'users';
    }

    private static function activityTable(): string
    {
        if (XuiReadOnly::hasTable('user_activity_now')) {
            return 'user_activity_now';
        }
        if (XuiReadOnly::hasTable('lines_live')) {
            return 'lines_live';
        }
        throw new RuntimeException('nenhuma tabela de sessões ativas compatível encontrada no XUI');
    }

    public static function syncUsers(array &$stats): void
    {
        $table = self::usersTable();
        $rows = XuiReadOnly::select(
            'SELECT id, username, password, max_connections, enabled, exp_date,
                    is_trial, is_restreamer, allowed_ips, allowed_ua
             FROM `' . $table . '`'
        );
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO xui_users_cache
                 (user_id, username, password_masked, credential_fingerprint, max_connections, enabled,
                  exp_date, is_trial, is_restreamer, allowed_ips, allowed_ua, synced_at)
                 VALUES (:id,:u,:pm,:fp,:mc,:en,:exp,:tr,:rs,:ips,:ua,:sy)
                 ON CONFLICT(user_id) DO UPDATE SET
                   username=excluded.username, password_masked=excluded.password_masked,
                   credential_fingerprint=excluded.credential_fingerprint,
                   max_connections=excluded.max_connections, enabled=excluded.enabled,
                   exp_date=excluded.exp_date, is_trial=excluded.is_trial,
                   is_restreamer=excluded.is_restreamer, allowed_ips=excluded.allowed_ips,
                   allowed_ua=excluded.allowed_ua, synced_at=excluded.synced_at'
            );
            $now = date('c');
            $seen = [];
            foreach ($rows as $r) {
                $user = (string) ($r['username'] ?? '');
                $pass = (string) ($r['password'] ?? '');
                $stmt->execute([
                    ':id' => (int) $r['id'],
                    ':u' => $user,
                    ':pm' => self::mask($pass),
                    ':fp' => RequestContext::fingerprint($user, $pass),
                    ':mc' => (int) ($r['max_connections'] ?? 0),
                    ':en' => (int) ($r['enabled'] ?? 1),
                    ':exp' => (string) ($r['exp_date'] ?? ''),
                    ':tr' => (int) ($r['is_trial'] ?? 0),
                    ':rs' => (int) ($r['is_restreamer'] ?? 0),
                    ':ips' => substr((string) ($r['allowed_ips'] ?? ''), 0, 500),
                    ':ua' => substr((string) ($r['allowed_ua'] ?? ''), 0, 500),
                    ':sy' => $now,
                ]);
                $seen[] = (int) $r['id'];
                $stats['processed']++;
            }
            if ($seen) {
                $pdo->exec('DELETE FROM xui_users_cache WHERE user_id NOT IN (' . implode(',', $seen) . ')');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $stats['details']['users'] = $stats['processed'];
        $stats['details']['users_table'] = $table;
    }

    public static function syncStreams(array &$stats): void
    {
        $rows = XuiReadOnly::each(
            // Este XUI guarda a verdade do direct source no próprio catálogo:
            // `direct_source` (flag) e `stream_source` (URL/JSON externo já pronto).
            'SELECT id, type, stream_display_name, category_id, target_container,
                    direct_source, direct_proxy, stream_source
               FROM streams'
        );
        $pdo = Database::pdo();
        $batchSize = 2000;
        $inTx = false;
        $batchCount = 0;
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO xui_streams_cache
                   (stream_id, type, stream_display_name, category_id, target_container,
                    direct_source, direct_proxy, stream_source_raw, parse_status, synced_at)
                 VALUES (:id,:t,:n,:c,:tc,:ds,:dp,:src,'pending',:sy)
                 ON CONFLICT(stream_id) DO UPDATE SET type=excluded.type,
                   stream_display_name=excluded.stream_display_name, category_id=excluded.category_id,
                   target_container=excluded.target_container,
                   direct_source=excluded.direct_source, direct_proxy=excluded.direct_proxy,
                   stream_source_raw=excluded.stream_source_raw,
                   parse_status=CASE WHEN xui_streams_cache.stream_source_raw = excluded.stream_source_raw
                                     THEN xui_streams_cache.parse_status ELSE 'pending' END,
                   synced_at=excluded.synced_at"
            );
            $now = date('c');
            foreach ($rows as $r) {
                if (!$inTx) {
                    $pdo->beginTransaction();
                    $inTx = true;
                }
                $stmt->execute([
                    ':id' => (int) $r['id'],
                    ':t' => (string) ($r['type'] ?? ''),
                    ':n' => substr((string) ($r['stream_display_name'] ?? ''), 0, 200),
                    ':c' => substr((string) ($r['category_id'] ?? ''), 0, 100),
                    ':tc' => substr((string) ($r['target_container'] ?? ''), 0, 60),
                    ':ds' => (int) ($r['direct_source'] ?? 0),
                    ':dp' => (int) ($r['direct_proxy'] ?? 0),
                    // Guardamos mascarado: nunca user:pass da origem em claro no painel.
                    ':src' => DirectSourceParser::maskCredentials((string) ($r['stream_source'] ?? '')),
                    ':sy' => $now,
                ]);
                $stats['processed']++;
                $batchCount++;
                if (($batchCount % $batchSize) === 0) {
                    $pdo->commit();
                    $inTx = false;
                }
            }
            if ($inTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $stats['details']['streams'] = $stats['processed'];
        $stats['details']['stream_batches'] = (int) ceil(max(1, $stats['processed']) / $batchSize);
        $stats['details']['direct_db'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM xui_streams_cache WHERE direct_source = 1'
        )->fetchColumn();
    }

    public static function syncActivity(array &$stats): void
    {
        // (mantido abaixo) — o espelho de séries entra antes por ordem lógica.
        self::syncActivityInner($stats);
    }

    /**
     * Espelha streams_series + streams_episodes.
     *
     * Volume real: milhares de séries e centenas de milhares de episódios. Vai
     * em transações por lote com streaming de cursor (XuiReadOnly::each) para
     * manter RAM constante, igual ao syncStreams.
     */
    public static function syncSeries(array &$stats): void
    {
        $pdo = Database::pdo();
        if (!Database::tableExists('xui_series_cache') || !Database::tableExists('xui_episodes_cache')) {
            $stats['details']['skipped'] = 'schema antigo (rode as migrações)';
            return;
        }
        $now = date('c');
        $batchSize = 2000;

        $seriesStmt = $pdo->prepare(
            'INSERT INTO xui_series_cache (series_id, title, category_id, synced_at)
             VALUES (:id,:t,:c,:sy)
             ON CONFLICT(series_id) DO UPDATE SET title=excluded.title,
               category_id=excluded.category_id, synced_at=excluded.synced_at'
        );
        $episodeStmt = $pdo->prepare(
            'INSERT INTO xui_episodes_cache (stream_id, series_id, season_num, episode_num, synced_at)
             VALUES (:sid,:se,:sn,:en,:sy)
             ON CONFLICT(stream_id) DO UPDATE SET series_id=excluded.series_id,
               season_num=excluded.season_num, episode_num=excluded.episode_num,
               synced_at=excluded.synced_at'
        );

        $series = 0;
        $episodes = 0;
        $inTx = false;
        $n = 0;
        try {
            foreach (XuiReadOnly::each('SELECT id, title, category_id FROM streams_series') as $r) {
                if (!$inTx) { $pdo->beginTransaction(); $inTx = true; }
                $seriesStmt->execute([
                    ':id' => (int) $r['id'],
                    ':t' => substr((string) ($r['title'] ?? ''), 0, 200),
                    ':c' => substr((string) ($r['category_id'] ?? ''), 0, 100),
                    ':sy' => $now,
                ]);
                $series++; $stats['processed']++; $n++;
                if (($n % $batchSize) === 0) { $pdo->commit(); $inTx = false; }
            }
            if ($inTx) { $pdo->commit(); $inTx = false; }

            $n = 0;
            foreach (XuiReadOnly::each(
                'SELECT stream_id, series_id, season_num, episode_num FROM streams_episodes'
            ) as $r) {
                if (!$inTx) { $pdo->beginTransaction(); $inTx = true; }
                $episodeStmt->execute([
                    ':sid' => (int) $r['stream_id'],
                    ':se' => (int) ($r['series_id'] ?? 0),
                    ':sn' => (int) ($r['season_num'] ?? 0),
                    ':en' => (int) ($r['episode_num'] ?? 0),
                    ':sy' => $now,
                ]);
                $episodes++; $stats['processed']++; $n++;
                if (($n % $batchSize) === 0) { $pdo->commit(); $inTx = false; }
            }
            if ($inTx) { $pdo->commit(); $inTx = false; }
        } catch (Throwable $e) {
            if ($inTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        $stats['details']['series'] = $series;
        $stats['details']['episodes'] = $episodes;
    }

    private static function syncActivityInner(array &$stats): void
    {
        $table = self::activityTable();
        $rows = XuiReadOnly::select(
            'SELECT activity_id, user_id, stream_id, server_id, user_agent, user_ip, container,
                    date_start, date_end, hls_last_read, hls_end
             FROM `' . $table . '`'
        );
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Snapshot inteiro: sessões ativas mudam por completo a cada ciclo.
            $pdo->exec('DELETE FROM xui_activity_now_cache');
            // S2-P0-4: `INSERT OR REPLACE` é dialeto SQLite e explode no
            // PostgreSQL. Upsert portável, mesmo efeito.
            $stmt = $pdo->prepare(Sql::upsert(
                'xui_activity_now_cache',
                ['activity_id', 'user_id', 'stream_id', 'server_id', 'user_agent', 'user_ip',
                 'container', 'date_start', 'date_end', 'hls_last_read', 'hls_end', 'synced_at'],
                ['activity_id']
            ));
            $now = date('c');
            foreach ($rows as $r) {
                $stmt->execute([
                    ':activity_id' => (int) $r['activity_id'],
                    ':user_id' => (int) ($r['user_id'] ?? 0),
                    ':stream_id' => (int) ($r['stream_id'] ?? 0),
                    ':server_id' => (int) ($r['server_id'] ?? 0),
                    ':user_agent' => substr((string) ($r['user_agent'] ?? ''), 0, 300),
                    ':user_ip' => (string) ($r['user_ip'] ?? ''),
                    ':container' => (string) ($r['container'] ?? ''),
                    ':date_start' => (int) ($r['date_start'] ?? 0),
                    ':date_end' => (int) ($r['date_end'] ?? 0),
                    ':hls_last_read' => (int) ($r['hls_last_read'] ?? 0),
                    ':hls_end' => (int) ($r['hls_end'] ?? 0),
                    ':synced_at' => $now,
                ]);
                $stats['processed']++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $stats['details']['sessions'] = $stats['processed'];
        $stats['details']['activity_table'] = $table;
    }

    private static function mask(string $value): string
    {
        $len = strlen($value);
        if ($len === 0) return '';
        if ($len <= 2) return str_repeat('*', $len);
        return substr($value, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($value, -1);
    }
}
