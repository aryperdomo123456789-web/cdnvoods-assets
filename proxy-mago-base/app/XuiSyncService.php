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
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO xui_streams_cache
                   (stream_id, type, stream_display_name, category_id, target_container,
                    direct_source, direct_proxy, stream_source_raw, parse_status, synced_at)
                 VALUES (:id,:t,:n,:c,:tc,:ds,:dp,:src,"pending",:sy)
                 ON CONFLICT(stream_id) DO UPDATE SET type=excluded.type,
                   stream_display_name=excluded.stream_display_name, category_id=excluded.category_id,
                   target_container=excluded.target_container,
                   direct_source=excluded.direct_source, direct_proxy=excluded.direct_proxy,
                   stream_source_raw=excluded.stream_source_raw,
                   parse_status=CASE WHEN xui_streams_cache.stream_source_raw = excluded.stream_source_raw
                                     THEN xui_streams_cache.parse_status ELSE "pending" END,
                   synced_at=excluded.synced_at'
            );
            $now = date('c');
            foreach ($rows as $r) {
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
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $stats['details']['streams'] = $stats['processed'];
        $stats['details']['direct_db'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM xui_streams_cache WHERE direct_source = 1'
        )->fetchColumn();
    }

    public static function syncActivity(array &$stats): void
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
            $stmt = $pdo->prepare(
                'INSERT OR REPLACE INTO xui_activity_now_cache
                 (activity_id, user_id, stream_id, server_id, user_agent, user_ip, container,
                  date_start, date_end, hls_last_read, hls_end, synced_at)
                 VALUES (:a,:u,:s,:sv,:ua,:ip,:c,:ds,:de,:hr,:he,:sy)'
            );
            $now = date('c');
            foreach ($rows as $r) {
                $stmt->execute([
                    ':a' => (int) $r['activity_id'],
                    ':u' => (int) ($r['user_id'] ?? 0),
                    ':s' => (int) ($r['stream_id'] ?? 0),
                    ':sv' => (int) ($r['server_id'] ?? 0),
                    ':ua' => substr((string) ($r['user_agent'] ?? ''), 0, 300),
                    ':ip' => (string) ($r['user_ip'] ?? ''),
                    ':c' => (string) ($r['container'] ?? ''),
                    ':ds' => (int) ($r['date_start'] ?? 0),
                    ':de' => (int) ($r['date_end'] ?? 0),
                    ':hr' => (int) ($r['hls_last_read'] ?? 0),
                    ':he' => (int) ($r['hls_end'] ?? 0),
                    ':sy' => $now,
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
