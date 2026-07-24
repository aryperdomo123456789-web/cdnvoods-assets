<?php

final class AliasRepository
{
    public static function all(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT a.*, o.name AS origin_name, o.active AS origin_active
               FROM aliases a
               LEFT JOIN origins o ON o.id = a.origin_id
              ORDER BY a.is_primary DESC, a.hostname ASC'
        );
        return $stmt->fetchAll();
    }

    public static function findByHostname(string $hostname): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT a.*, o.host AS origin_host, o.port AS origin_port, o.scheme AS origin_scheme,
                    o.base_path AS origin_base_path, o.auth_user AS origin_user, o.auth_pass AS origin_pass,
                    o.active AS origin_active, o.name AS origin_name,
                    o.host_header AS origin_host_header, o.type AS origin_type
               FROM aliases a
               JOIN origins o ON o.id = a.origin_id
              WHERE lower(a.hostname) = lower(:h) AND a.active = 1
              LIMIT 1'
        );
        $stmt->execute([':h' => $hostname]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM aliases WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function primary(): ?array
    {
        $stmt = Database::pdo()->query('SELECT * FROM aliases WHERE is_primary = 1 AND active = 1 LIMIT 1');
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $now = date('c');
        if (!empty($data['is_primary'])) {
            Database::pdo()->exec('UPDATE aliases SET is_primary = 0');
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO aliases (hostname, origin_id, is_primary, active, created_at, updated_at)
             VALUES (:hostname, :origin_id, :is_primary, :active, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':hostname' => strtolower(trim((string) ($data['hostname'] ?? ''))),
            ':origin_id' => (int) ($data['origin_id'] ?? 0),
            ':is_primary' => !empty($data['is_primary']) ? 1 : 0,
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
        if (!empty($data['is_primary'])) {
            Database::pdo()->exec('UPDATE aliases SET is_primary = 0');
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE aliases
                SET hostname = :hostname,
                    origin_id = :origin_id,
                    is_primary = :is_primary,
                    active = :active,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            ':hostname' => strtolower(trim((string) ($data['hostname'] ?? $current['hostname']))),
            ':origin_id' => (int) ($data['origin_id'] ?? $current['origin_id']),
            ':is_primary' => !empty($data['is_primary']) ? 1 : 0,
            ':active' => !empty($data['active']) ? 1 : 0,
            ':updated_at' => date('c'),
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM aliases WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
