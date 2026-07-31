<?php

final class UserIpLock
{
    public static function get(string $username): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM cdn_user_ip_lock WHERE username = :u LIMIT 1');
        $st->execute([':u' => $username]);
        $row = $st->fetch();
        if (!$row) {
            return [
                'username' => $username,
                'allowed_ips' => '',
                'notes' => '',
                'updated_at' => '',
                'updated_epoch' => 0,
                'ips' => [],
            ];
        }
        $row['ips'] = self::parseList((string) ($row['allowed_ips'] ?? ''));
        return $row;
    }

    public static function save(string $username, string $allowedIps, string $notes = ''): void
    {
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException('username vazio para trava de IP');
        }
        $ips = self::normalizeList($allowedIps);
        Database::pdo()->prepare(
            'INSERT INTO cdn_user_ip_lock (username, allowed_ips, notes, updated_at, updated_epoch)
             VALUES (:u,:ips,:n,:at,:ae)
             ON CONFLICT(username) DO UPDATE SET
               allowed_ips = excluded.allowed_ips,
               notes = excluded.notes,
               updated_at = excluded.updated_at,
               updated_epoch = excluded.updated_epoch'
        )->execute([
            ':u' => $username,
            ':ips' => implode("\n", $ips),
            ':n' => substr(trim($notes), 0, 500),
            ':at' => date('c'),
            ':ae' => time(),
        ]);
    }

    public static function enabledFor(string $username): bool
    {
        return self::get($username)['ips'] !== [];
    }

    public static function matches(string $username, string $clientIp): bool
    {
        $ips = self::get($username)['ips'];
        if ($ips === []) {
            return true;
        }
        foreach ($ips as $rule) {
            if (self::ipMatches($rule, $clientIp)) {
                return true;
            }
        }
        return false;
    }

    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $out[] = $part;
        }
        return array_values(array_unique($out));
    }

    public static function normalizeList(string $raw): array
    {
        $items = self::parseList($raw);
        $valid = [];
        foreach ($items as $item) {
            if (self::isValidRule($item)) {
                $valid[] = $item;
            }
        }
        return array_values(array_unique($valid));
    }

    private static function isValidRule(string $rule): bool
    {
        if (filter_var($rule, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}\/\d{1,2}$/', $rule)) {
            [$ip, $bits] = explode('/', $rule, 2);
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) $bits >= 0 && (int) $bits <= 32;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){2}\.\*$/', $rule)) {
            return true;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}\s*-\s*\d{1,3}(?:\.\d{1,3}){3}$/', $rule)) {
            [$startIp, $endIp] = preg_split('/\s*-\s*/', $rule, 2);
            $start = ip2long((string) $startIp);
            $end = ip2long((string) $endIp);
            return $start !== false && $end !== false && $start <= $end;
        }
        return false;
    }

    private static function ipMatches(string $rule, string $ip): bool
    {
        if ($rule === $ip) {
            return true;
        }
        if (str_contains($rule, '/*')) {
            return false;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){2}\.\*$/', $rule)) {
            $prefix = substr($rule, 0, -1);
            return str_starts_with($ip, $prefix);
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}\/\d{1,2}$/', $rule)) {
            [$subnet, $bits] = explode('/', $rule, 2);
            $ipLong = ip2long($ip);
            $subLong = ip2long($subnet);
            if ($ipLong === false || $subLong === false) {
                return false;
            }
            $mask = -1 << (32 - (int) $bits);
            return ($ipLong & $mask) === ($subLong & $mask);
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}\s*-\s*\d{1,3}(?:\.\d{1,3}){3}$/', $rule)) {
            [$startIp, $endIp] = preg_split('/\s*-\s*/', $rule, 2);
            $ipLong = ip2long($ip);
            $start = ip2long((string) $startIp);
            $end = ip2long((string) $endIp);
            if ($ipLong === false || $start === false || $end === false) {
                return false;
            }
            return $ipLong >= $start && $ipLong <= $end;
        }
        return false;
    }
}
