<?php

final class SettingsRepository
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        $value = json_decode($row['value'], true);
        return json_last_error() === JSON_ERROR_NONE ? $value : $row['value'];
    }

    public static function set(string $key, mixed $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, :updated_at)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':key' => $key,
            ':value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_at' => date('c'),
        ]);
    }

    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT key, value FROM settings ORDER BY key ASC');
        $items = [];

        foreach ($stmt->fetchAll() as $row) {
            $value = json_decode($row['value'], true);
            $items[$row['key']] = json_last_error() === JSON_ERROR_NONE ? $value : $row['value'];
        }

        return $items;
    }

    public static function seeded(): bool
    {
        return (bool) self::get('admin_password_hash', '');
    }
}
