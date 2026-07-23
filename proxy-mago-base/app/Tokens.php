<?php

/**
 * Tokens efêmeros que o painel emite para o player.
 *
 * O painel emite tokens vinculados a um alias (hostname público). O token vai
 * na querystring das URLs reescritas da playlist. A origem XUI real nunca
 * aparece na URL — o token só serve para o AccessGuard resolver internamente
 * qual origin usar.
 */
final class Tokens
{
    public static function issue(int $aliasId, string $allowedIp = '', ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? (int) SettingsRepository::get('token_ttl', Config::get('token_ttl', 3600));
        $token = bin2hex(random_bytes(16));
        $now = time();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO tokens (token, alias_id, allowed_ip, expires_at, created_at)
             VALUES (:token, :alias_id, :allowed_ip, :expires_at, :created_at)'
        );
        $stmt->execute([
            ':token' => $token,
            ':alias_id' => $aliasId,
            ':allowed_ip' => $allowedIp,
            ':expires_at' => date('c', $now + $ttl),
            ':created_at' => date('c', $now),
        ]);
        return [
            'token' => $token,
            'expires_at' => date('c', $now + $ttl),
            'ttl' => $ttl,
        ];
    }

    public static function find(string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM tokens WHERE token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function touch(int $tokenId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE tokens SET hits = hits + 1, last_used_at = :ts WHERE id = :id'
        );
        $stmt->execute([':ts' => date('c'), ':id' => $tokenId]);
    }

    public static function purgeExpired(): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM tokens WHERE expires_at < :now');
        $stmt->execute([':now' => date('c')]);
        return $stmt->rowCount();
    }
}
