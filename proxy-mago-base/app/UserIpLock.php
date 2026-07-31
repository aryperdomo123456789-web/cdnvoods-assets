<?php

/**
 * Trava de acesso por IP aplicada PELA CDN, antes de qualquer request ao XUI.
 *
 * Regras aceitas (uma por linha, vírgula ou espaço):
 *   - IPv4/IPv6 exato          45.140.192.237 | 2001:db8::10
 *   - CIDR IPv4/IPv6           45.140.192.0/24 | 2001:db8::/32
 *   - faixa IPv4               45.140.192.10-45.140.192.30
 *   - curinga IPv4 por octeto  45.140.192.* | 45.140.* | 45.*
 *
 * Lista vazia = usuário liberado (fail-open proposital: trava é opt-in por
 * usuário, ninguém fica sem stream por esquecer de cadastrar IP).
 */
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

    /**
     * Salva a trava e DEVOLVE o que foi aceito e o que foi recusado, para o
     * painel avisar em vez de engolir regra inválida em silêncio.
     *
     * @return array{valid: string[], invalid: string[]}
     */
    public static function save(string $username, string $allowedIps, string $notes = ''): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new RuntimeException('username vazio para trava de IP');
        }

        $result = self::validateList($allowedIps);

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
            ':ips' => implode("\n", $result['valid']),
            ':n' => substr(trim($notes), 0, 500),
            ':at' => date('c'),
            ':ae' => time(),
        ]);

        return $result;
    }

    public static function enabledFor(string $username): bool
    {
        return self::get($username)['ips'] !== [];
    }

    public static function matches(string $username, string $clientIp): bool
    {
        return self::explain($username, $clientIp)['allowed'];
    }

    /**
     * Veredito explicável: usado pelo proxy (log/auditoria) e pelo painel.
     *
     * @return array{allowed: bool, active: bool, rule: string, rules: int, reason: string}
     */
    public static function explain(string $username, string $clientIp): array
    {
        $ips = self::get($username)['ips'];
        if ($ips === []) {
            return ['allowed' => true, 'active' => false, 'rule' => '', 'rules' => 0, 'reason' => 'no_lock'];
        }
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            return ['allowed' => false, 'active' => true, 'rule' => '', 'rules' => count($ips), 'reason' => 'client_ip_invalid'];
        }
        foreach ($ips as $rule) {
            if (self::ipMatches($rule, $clientIp)) {
                return ['allowed' => true, 'active' => true, 'rule' => $rule, 'rules' => count($ips), 'reason' => 'rule_match'];
            }
        }
        return ['allowed' => false, 'active' => true, 'rule' => '', 'rules' => count($ips), 'reason' => 'no_rule_match'];
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

    /** @return array{valid: string[], invalid: string[]} */
    public static function validateList(string $raw): array
    {
        $valid = [];
        $invalid = [];
        foreach (self::parseList($raw) as $item) {
            $normalized = self::normalizeRule($item);
            if ($normalized === null) {
                $invalid[] = $item;
                continue;
            }
            $valid[] = $normalized;
        }
        return [
            'valid' => array_values(array_unique($valid)),
            'invalid' => array_values(array_unique($invalid)),
        ];
    }

    /** @return string[] compatibilidade: só as regras válidas */
    public static function normalizeList(string $raw): array
    {
        return self::validateList($raw)['valid'];
    }

    /** Devolve a regra canônica (sem espaço na faixa) ou null se for inválida. */
    public static function normalizeRule(string $rule): ?string
    {
        $rule = trim($rule);
        if ($rule === '') {
            return null;
        }

        if (filter_var($rule, FILTER_VALIDATE_IP) !== false) {
            return $rule;
        }

        if (str_contains($rule, '/')) {
            [$net, $bits] = explode('/', $rule, 2);
            if ($bits === '' || !ctype_digit($bits)) {
                return null;
            }
            $bits = (int) $bits;
            if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return ($bits >= 0 && $bits <= 32) ? $net . '/' . $bits : null;
            }
            if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return ($bits >= 0 && $bits <= 128) ? $net . '/' . $bits : null;
            }
            return null;
        }

        if (str_contains($rule, '-')) {
            $parts = preg_split('/\s*-\s*/', $rule, 2) ?: [];
            if (count($parts) !== 2) {
                return null;
            }
            $start = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            $end = filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if ($start === false || $end === false) {
                return null;
            }
            if (self::v4($start) > self::v4($end)) {
                return null;
            }
            return $start . '-' . $end;
        }

        if (str_contains($rule, '*')) {
            $octets = explode('.', $rule);
            if (count($octets) < 2 || count($octets) > 4) {
                return null;
            }
            $seenStar = false;
            foreach ($octets as $octet) {
                if ($octet === '*') {
                    $seenStar = true;
                    continue;
                }
                if ($seenStar) {
                    return null; // 10.*.5.* não existe: curinga só no fim
                }
                if (!ctype_digit($octet) || (int) $octet > 255) {
                    return null;
                }
            }
            return $seenStar ? $rule : null;
        }

        return null;
    }

    private static function v4(string $ip): int
    {
        return (int) sprintf('%u', ip2long($ip));
    }

    private static function ipMatches(string $rule, string $ip): bool
    {
        if ($rule === $ip) {
            return true;
        }

        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

        if (str_contains($rule, '*')) {
            if (!$isV4) {
                return false;
            }
            $prefix = rtrim(substr($rule, 0, (int) strpos($rule, '*')), '.');
            return $prefix === '' ? true : str_starts_with($ip . '.', $prefix . '.');
        }

        if (str_contains($rule, '/')) {
            [$net, $bits] = explode('/', $rule, 2);
            $bits = (int) $bits;
            $netIsV4 = filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            if ($netIsV4 !== $isV4) {
                return false;
            }
            if ($isV4) {
                if ($bits <= 0) {
                    return true;
                }
                $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
                return (self::v4($ip) & $mask) === (self::v4($net) & $mask);
            }
            return self::v6InCidr($ip, $net, $bits);
        }

        if (str_contains($rule, '-')) {
            if (!$isV4) {
                return false;
            }
            $parts = preg_split('/\s*-\s*/', $rule, 2) ?: [];
            if (count($parts) !== 2) {
                return false;
            }
            if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return false;
            }
            $value = self::v4($ip);
            return $value >= self::v4($parts[0]) && $value <= self::v4($parts[1]);
        }

        return false;
    }

    private static function v6InCidr(string $ip, string $net, int $bits): bool
    {
        if ($bits <= 0) {
            return true;
        }
        $a = inet_pton($ip);
        $b = inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== 16 || strlen($b) !== 16) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && strncmp($a, $b, $fullBytes) !== 0) {
            return false;
        }
        $restBits = $bits % 8;
        if ($restBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $restBits)) & 0xFF;
        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }
}
