<?php

/**
 * Camada única de validação para o proxy:
 *  - alias público está ativo e conhecido no SQLite?
 *  - origem está ativa?
 *  - token existe, não expirou e (opcionalmente) casa com IP?
 *  - IP do cliente dentro do rate limit?
 *  - User-Agent permitido (se configurado)?
 *
 * Tokens são OBRIGATÓRIOS. Requisições sem token são rejeitadas com 401.
 * Cabeçalhos CF-Connecting-IP / X-Forwarded-For só são confiáveis quando a conexão TCP
 * veio de uma faixa oficial da Cloudflare.
 */
final class AccessGuard
{
    /** Faixas oficiais Cloudflare (IPv4). Fonte: https://www.cloudflare.com/ips-v4 */
    private const CF_V4 = [
        '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
        '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
        '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
        '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
    ];
    private const CF_V6 = [
        '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
        '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
    ];

    public static function check(string $hostname, string $clientIp, string $userAgent, string $token = ''): array
    {
        $alias = AliasRepository::findByHostname($hostname);
        if (!$alias) {
            return self::deny(404, 'unknown_alias');
        }
        if ((int) $alias['origin_active'] !== 1) {
            return self::deny(503, 'origin_disabled');
        }

        $allowedUa = trim((string) SettingsRepository::get('allowed_user_agent', Config::get('allowed_user_agent', '')));
        if ($allowedUa !== '' && stripos($userAgent, $allowedUa) === false) {
            return self::deny(403, 'ua_blocked');
        }

        if (!self::rateLimitOk($clientIp)) {
            return self::deny(429, 'rate_limited');
        }

        // Token OBRIGATÓRIO.
        if ($token === '') {
            return self::deny(401, 'token_required');
        }
        $tokenRow = Tokens::find($token);
        if (!$tokenRow) {
            return self::deny(401, 'token_invalid');
        }
        if (strtotime((string) $tokenRow['expires_at']) < time()) {
            return self::deny(401, 'token_expired');
        }
        if ((int) $tokenRow['alias_id'] !== (int) $alias['id']) {
            return self::deny(401, 'token_alias_mismatch');
        }
        if ($tokenRow['allowed_ip'] !== '' && !self::ipMatches($tokenRow['allowed_ip'], $clientIp)) {
            return self::deny(401, 'token_ip_mismatch');
        }
        Tokens::touch((int) $tokenRow['id']);

        return [
            'ok' => true,
            'code' => 200,
            'reason' => 'ok',
            'alias' => $alias,
            'origin' => [
                'id' => (int) $alias['origin_id'],
                'host' => (string) $alias['origin_host'],
                'port' => (int) $alias['origin_port'],
                'scheme' => (string) $alias['origin_scheme'],
                'base_path' => (string) $alias['origin_base_path'],
                'auth_user' => (string) $alias['origin_user'],
                'auth_pass' => (string) $alias['origin_pass'],
                'name' => (string) $alias['origin_name'],
                'type' => (string) ($alias['origin_type'] ?? 'a'),
                'host_header' => (string) ($alias['origin_host_header'] ?? ''),
            ],
            'token' => $tokenRow,
        ];
    }

    private static function deny(int $code, string $reason): array
    {
        return ['ok' => false, 'code' => $code, 'reason' => $reason, 'alias' => null, 'origin' => null, 'token' => null];
    }

    private static function rateLimitOk(string $ip): bool
    {
        if ($ip === '' || $ip === '-') return true;
        $limit = (int) SettingsRepository::get('rate_limit_per_minute', Config::get('rate_limit_per_minute', 120));
        if ($limit <= 0) return true;
        $window = (int) floor(time() / 60);
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO rate_limit (client_ip, window_start, hits) VALUES (:ip, :w, 1)
             ON CONFLICT(client_ip, window_start) DO UPDATE SET hits = hits + 1'
        )->execute([':ip' => $ip, ':w' => $window]);
        $stmt = $pdo->prepare('SELECT hits FROM rate_limit WHERE client_ip = :ip AND window_start = :w');
        $stmt->execute([':ip' => $ip, ':w' => $window]);
        $hits = (int) ($stmt->fetchColumn() ?: 0);
        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM rate_limit WHERE window_start < :old')->execute([':old' => $window - 5]);
        }
        return $hits <= $limit;
    }

    private static function ipMatches(string $rule, string $ip): bool
    {
        if ($rule === '' || $rule === $ip) return true;
        $ruleParts = explode('.', $rule);
        $ipParts = explode('.', $ip);
        if (count($ruleParts) === 4 && count($ipParts) === 4 && $ruleParts[3] === '0') {
            return array_slice($ruleParts, 0, 3) === array_slice($ipParts, 0, 3);
        }
        return false;
    }

    /**
     * Só devolve IP de header (CF-Connecting-IP / XFF) quando o TCP peer é Cloudflare.
     * Caso contrário devolve o REMOTE_ADDR real, evitando spoofing.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
        if (self::isCloudflare($remote)) {
            $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
            if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
            $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }
        return $remote;
    }

    public static function isCloudflare(string $ip): bool
    {
        if ($ip === '' || $ip === '-') return false;
        $ranges = strpos($ip, ':') !== false ? self::CF_V6 : self::CF_V4;
        foreach ($ranges as $cidr) {
            if (self::cidrMatch($ip, $cidr)) return true;
        }
        return false;
    }

    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) return false;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
        if ($rem === 0) return true;
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subBin[$bytes]) & ord($mask));
    }

    public static function logAccess(string $host, string $path, int $status, int $bytes, ?int $tokenId, ?int $originId, string $reason): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO access_log (ts, client_ip, host, path, status, bytes, token_id, origin_id, reason)
             VALUES (:ts, :ip, :host, :path, :status, :bytes, :tok, :orig, :reason)'
        );
        $stmt->execute([
            ':ts' => date('c'),
            ':ip' => self::clientIp(),
            ':host' => $host,
            ':path' => $path,
            ':status' => $status,
            ':bytes' => $bytes,
            ':tok' => $tokenId,
            ':orig' => $originId,
            ':reason' => $reason,
        ]);
    }
}
