<?php

/**
 * Contexto rastreável de UM request público do proxy.
 *
 * Responsável por:
 *  - gerar request_id único (rastreabilidade fim a fim)
 *  - classificar a rota (m3u, api, live, movie, series, hls, segment, other)
 *  - extrair username/password do assinante (query OU path do XUI)
 *  - gerar credential_fingerprint = sha256(username:password)
 *  - mascarar a query para log
 *
 * NUNCA guarda a senha em claro.
 */
final class RequestContext
{
    public string $requestId;
    public float $startedAt;
    public string $clientIp = '';
    public string $publicHost = '';
    public string $method = 'GET';
    public string $path = '/';
    public string $queryMasked = '';
    public string $routeKind = 'other';
    public string $username = '';
    public string $fingerprint = '';
    public ?int $streamId = null;
    public string $userAgent = '';
    public string $referer = '';

    public static function build(string $host, string $clientIp, string $path, array $query): self
    {
        $c = new self();
        $c->requestId = bin2hex(random_bytes(8)) . dechex((int) (microtime(true) * 1000));
        $c->startedAt = microtime(true);
        $c->clientIp = $clientIp;
        $c->publicHost = $host;
        $c->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $c->path = $path;
        $c->userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 300);
        $c->referer = self::sanitizeReferer((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $c->routeKind = self::classify($path, $query);

        [$user, $pass] = self::extractCredentials($path, $query);
        $c->username = substr($user, 0, 120);
        $c->fingerprint = ($user !== '' || $pass !== '') ? self::fingerprint($user, $pass) : '';
        $c->streamId = self::extractStreamId($path, $query);
        $c->queryMasked = self::maskQuery($query);
        return $c;
    }

    public static function fingerprint(string $user, string $pass): string
    {
        return hash('sha256', $user . ':' . $pass);
    }

    /**
     * Tipos de consumo do plano:
     *  get.php => m3u | player_api/xmltv/panel_api => api
     *  /live/ => live | /movie/ => movie | /series/ => series
     *  .m3u8 / output=hls / /hls/ => hls (quando não for live/movie/series)
     *  .ts e afins => segment
     * Regra: o tipo mais específico de stream vence.
     */
    public static function classify(string $path, array $query): string
    {
        $p = strtolower($path);
        if (preg_match('#^/(live)/#', $p)) return 'live';
        if (preg_match('#^/(movie|vod)/#', $p)) return 'movie';
        if (preg_match('#^/(series)/#', $p)) return 'series';
        if (str_ends_with($p, 'get.php')) return 'm3u';
        if (preg_match('#(player_api\.php|panel_api\.php|xmltv\.php|enigma2\.php)$#', $p)) return 'api';
        if (str_ends_with($p, '.m3u8') || str_contains($p, '/hls/')
            || strtolower((string) ($query['output'] ?? '')) === 'hls') {
            return 'hls';
        }
        if (preg_match('#\.(ts|mp4|mkv|avi|m4s|aac)$#', $p)) return 'segment';
        if (str_ends_with($p, '.m3u')) return 'm3u';
        return 'other';
    }

    /** @return array{0:string,1:string} */
    public static function extractCredentials(string $path, array $query): array
    {
        $user = (string) ($query['username'] ?? '');
        $pass = (string) ($query['password'] ?? '');
        if ($user !== '') {
            return [$user, $pass];
        }
        // /live/USER/PASS/123.ts  |  /movie/USER/PASS/123.mp4  |  /series/...
        if (preg_match('#^/(live|movie|vod|series|hls)/([^/]+)/([^/]+)/#i', $path, $m)) {
            return [rawurldecode($m[2]), rawurldecode($m[3])];
        }
        return ['', ''];
    }

    public static function extractStreamId(string $path, array $query): ?int
    {
        if (!empty($query['stream_id']) && ctype_digit((string) $query['stream_id'])) {
            return (int) $query['stream_id'];
        }
        if (preg_match('#/(\d+)(?:_[^/]*)?\.(ts|m3u8|mp4|mkv|avi)$#i', $path, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#^/(live|movie|vod|series)/[^/]+/[^/]+/(\d+)#i', $path, $m)) {
            return (int) $m[2];
        }
        return null;
    }

    public static function maskQuery(array $query): string
    {
        $safe = [];
        foreach ($query as $k => $v) {
            if (!is_scalar($v)) { $v = ''; }
            $lk = strtolower((string) $k);
            if (in_array($lk, ['password', 'pass', 't', 'token'], true)) {
                $safe[$k] = '***';
            } else {
                $safe[$k] = substr((string) $v, 0, 120);
            }
        }
        return substr(http_build_query($safe), 0, 500);
    }

    private static function sanitizeReferer(string $referer): string
    {
        $referer = trim($referer);
        if ($referer === '') {
            return '';
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return substr(preg_replace('/[?&](username|password|token|t)=[^&]*/i', '$1=***', $referer) ?? $referer, 0, 300);
        }
        $safe = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $safe .= ':' . $parts['port'];
        }
        $safe .= $parts['path'] ?? '';
        return substr($safe, 0, 300);
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }
}
