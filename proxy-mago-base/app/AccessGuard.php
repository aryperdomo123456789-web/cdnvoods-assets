<?php

/**
 * Camada única de validação para o proxy:
 *  - alias público está ativo e conhecido no SQLite?
 *  - origem está ativa?
 *  - token existe, não expirou e (opcionalmente) casa com IP?
 *  - IP do cliente dentro do rate limit?
 *  - User-Agent permitido (se configurado)?
 *
 * Retorno padronizado: ['ok' => bool, 'code' => int, 'reason' => string, 'alias' => ?array, 'origin' => ?array, 'token' => ?array].
 * A origem retornada carrega host/port/scheme/base_path/user/pass reais (para uso interno).
 */
final class AccessGuard
{
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
            // UA restrito é opcional. Se configurou e não bate, bloqueia.
            return self::deny(403, 'ua_blocked');
        }

        // Rate limit por IP (janela de 60s)
        if (!self::rateLimitOk($clientIp)) {
            return self::deny(429, 'rate_limited');
        }

        $tokenRow = null;
        if ($token !== '') {
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
        }

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
        if ($ip === '' || $ip === '-') {
            return true;
        }
        $limit = (int) SettingsRepository::get('rate_limit_per_minute', Config::get('rate_limit_per_minute', 120));
        if ($limit <= 0) {
            return true;
        }
        $window = (int) floor(time() / 60);
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO rate_limit (client_ip, window_start, hits) VALUES (:ip, :w, 1)
             ON CONFLICT(client_ip, window_start) DO UPDATE SET hits = hits + 1'
        )->execute([':ip' => $ip, ':w' => $window]);

        $stmt = $pdo->prepare('SELECT hits FROM rate_limit WHERE client_ip = :ip AND window_start = :w');
        $stmt->execute([':ip' => $ip, ':w' => $window]);
        $hits = (int) ($stmt->fetchColumn() ?: 0);

        // GC leve: 1% das requisições limpa janelas antigas.
        if (random_int(1, 100) === 1) {
            $pdo->prepare('DELETE FROM rate_limit WHERE window_start < :old')->execute([':old' => $window - 5]);
        }
        return $hits <= $limit;
    }

    /**
     * Match de IP simples: exato ou match do prefixo /24 (para minimizar quebras em rede móvel).
     */
    private static function ipMatches(string $rule, string $ip): bool
    {
        if ($rule === '' || $rule === $ip) {
            return true;
        }
        // Se rule termina com .0, comparar apenas 3 primeiros octetos.
        $ruleParts = explode('.', $rule);
        $ipParts = explode('.', $ip);
        if (count($ruleParts) === 4 && count($ipParts) === 4 && $ruleParts[3] === '0') {
            return array_slice($ruleParts, 0, 3) === array_slice($ipParts, 0, 3);
        }
        return false;
    }

    public static function clientIp(): string
    {
        // Trás Cloudflare
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
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
