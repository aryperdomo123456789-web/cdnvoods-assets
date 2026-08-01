<?php

/**
 * Configuração da conexão READ-ONLY com o MySQL do XUI.
 * Linha única (id = 1) em xui_sync_config.
 */
final class XuiSyncConfig
{
    public static function get(): array
    {
        $pdo = Database::pdo();
        $row = $pdo->query('SELECT * FROM xui_sync_config WHERE id = 1')->fetch();
        if (!$row) {
            try {
                if (Database::isPgsql()) {
                    $pdo->exec("INSERT INTO xui_sync_config (id, updated_at) VALUES (1, '') ON CONFLICT (id) DO NOTHING");
                } else {
                    $pdo->exec("INSERT INTO xui_sync_config (id, updated_at) VALUES (1, '')");
                }
            } catch (Throwable $e) {
                // Outra execução pode ter criado a linha 1 no intervalo.
            }
            $row = $pdo->query('SELECT * FROM xui_sync_config WHERE id = 1')->fetch();
        }
        return $row ?: [];
    }

    public static function save(array $data): void
    {
        self::get();
        $current = self::get();
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $password = (string) ($current['password'] ?? '');
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE xui_sync_config SET host=:h, port=:p, database_name=:d, username=:u, password=:pw,
             api_url=:apiu, api_token=:apit,
             use_tls=:tls, sync_enabled=:en, sync_interval_seconds=:si, users_interval_seconds=:ui,
             streams_interval_seconds=:sti, connect_timeout_seconds=:ct, read_timeout_seconds=:rt,
             updated_at=:up WHERE id = 1'
        );
        $stmt->execute([
            ':h' => trim((string) ($data['host'] ?? '')),
            ':p' => max(1, (int) ($data['port'] ?? 3306)),
            ':d' => trim((string) ($data['database_name'] ?? 'xtream_iptvpro')),
            ':u' => trim((string) ($data['username'] ?? '')),
            ':pw' => $password,
            ':apiu' => trim((string) ($data['api_url'] ?? ($current['api_url'] ?? ''))),
            ':apit' => trim((string) ($data['api_token'] ?? ($current['api_token'] ?? ''))),
            ':tls' => !empty($data['use_tls']) ? 1 : 0,
            ':en' => !empty($data['sync_enabled']) ? 1 : 0,
            ':si' => max(2, (int) ($data['sync_interval_seconds'] ?? 5)),
            ':ui' => max(15, (int) ($data['users_interval_seconds'] ?? 60)),
            ':sti' => max(30, (int) ($data['streams_interval_seconds'] ?? 300)),
            ':ct' => max(1, (int) ($data['connect_timeout_seconds'] ?? 3)),
            ':rt' => max(1, (int) ($data['read_timeout_seconds'] ?? 5)),
            ':up' => date('c'),
        ]);
    }

    public static function markSync(string $status, string $error = ''): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE xui_sync_config SET last_sync_at=:t, last_sync_status=:s, last_sync_error=:e WHERE id = 1'
        );
        $stmt->execute([':t' => date('c'), ':s' => $status, ':e' => substr($error, 0, 400)]);
    }

    public static function enabled(): bool
    {
        $c = self::get();
        return (int) ($c['sync_enabled'] ?? 0) === 1 && trim((string) ($c['host'] ?? '')) !== '';
    }
}
