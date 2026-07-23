<?php

final class OriginRepository
{
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM origins ORDER BY id ASC');
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM origins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $now = date('c');
        $stmt = Database::pdo()->prepare(
            'INSERT INTO origins (name, host, port, scheme, base_path, auth_user, auth_pass, active, created_at, updated_at)
             VALUES (:name, :host, :port, :scheme, :base_path, :auth_user, :auth_pass, :active, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => trim((string) ($data['name'] ?? '')),
            ':host' => trim((string) ($data['host'] ?? '')),
            ':port' => (int) ($data['port'] ?? 80),
            ':scheme' => in_array($data['scheme'] ?? 'http', ['http', 'https'], true) ? $data['scheme'] : 'http',
            ':base_path' => trim((string) ($data['base_path'] ?? '')),
            ':auth_user' => (string) ($data['auth_user'] ?? ''),
            ':auth_pass' => (string) ($data['auth_pass'] ?? ''),
            ':active' => !empty($data['active']) ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $current = self::find($id);
        if (!$current) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE origins
                SET name = :name,
                    host = :host,
                    port = :port,
                    scheme = :scheme,
                    base_path = :base_path,
                    auth_user = :auth_user,
                    auth_pass = :auth_pass,
                    active = :active,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            ':name' => trim((string) ($data['name'] ?? $current['name'])),
            ':host' => trim((string) ($data['host'] ?? $current['host'])),
            ':port' => (int) ($data['port'] ?? $current['port']),
            ':scheme' => in_array($data['scheme'] ?? $current['scheme'], ['http', 'https'], true) ? $data['scheme'] : $current['scheme'],
            ':base_path' => trim((string) ($data['base_path'] ?? $current['base_path'])),
            // auth_user/auth_pass: se vier vazio, PRESERVAR o atual (nao apagar por acidente)
            ':auth_user' => array_key_exists('auth_user', $data) && $data['auth_user'] !== '' ? (string) $data['auth_user'] : $current['auth_user'],
            ':auth_pass' => array_key_exists('auth_pass', $data) && $data['auth_pass'] !== '' ? (string) $data['auth_pass'] : $current['auth_pass'],
            ':active' => !empty($data['active']) ? 1 : 0,
            ':updated_at' => date('c'),
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM origins WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
