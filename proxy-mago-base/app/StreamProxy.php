<?php

/**
 * StreamProxy — puxa da origem XUI via cURL e devolve ao cliente.
 *
 * - m3u/m3u8 / get.php: buffer em memoria, reescreve URL/credenciais, devolve.
 * - .ts / demais: streaming chunk a chunk via CURLOPT_WRITEFUNCTION (baixa RAM).
 *
 * Nunca vaza:
 *  - IP/host da origem em cabecalhos de resposta
 *  - user/pass da origem em cabecalhos ou body reescrito
 */
final class StreamProxy
{
    /** Marcado quando o CredentialGuard aborta a entrega por swap de usuário. */
    private static bool $swapAborted = false;

    /**
     * Trilha de hops do request atual (direct source).
     * @var array<int,array{hop:int,from:string,to:string,off_origin:int,outcome:string}>
     */
    private static array $hops = [];

    /** Host final realmente acessado por dentro (pode ser um CDN de terceiros). */
    private static string $finalHost = '';

    /** Sessão atual da CDN para heartbeat durante streams longos. */
    private static string $sessionKey = '';

    /** Último heartbeat emitido durante o request atual. */
    private static int $lastHeartbeatAt = 0;

    /** Intervalo de heartbeat do stream para manter a sessão viva no painel. */
    private const HEARTBEAT_SECONDS = 15;

    public static function swapAborted(): bool
    {
        return self::$swapAborted;
    }

    /** Zera a trilha — chamado uma vez por request público. */
    public static function resetTrace(): void
    {
        self::$hops = [];
        self::$finalHost = '';
        self::$swapAborted = false;
        self::$sessionKey = '';
        self::$lastHeartbeatAt = 0;
    }

    public static function setSessionKey(string $sessionKey): void
    {
        self::$sessionKey = $sessionKey;
        self::$lastHeartbeatAt = time();
    }

    private static function heartbeat(): void
    {
        if (self::$sessionKey === '') {
            return;
        }
        $now = time();
        if (($now - self::$lastHeartbeatAt) < self::HEARTBEAT_SECONDS) {
            return;
        }
        CdnSession::heartbeat(self::$sessionKey, self::directHost());
        self::$lastHeartbeatAt = $now;
    }

    /** @return array<int,array<string,mixed>> */
    public static function hops(): array
    {
        return self::$hops;
    }

    public static function finalHost(): string
    {
        return self::$finalHost;
    }

    /** Host final é "direct" quando saiu do domínio da origem XUI. */
    public static function directHost(): string
    {
        foreach (array_reverse(self::$hops) as $hop) {
            if ((int) $hop['off_origin'] === 1 && $hop['outcome'] === 'followed') {
                return (string) $hop['to'];
            }
        }
        return '';
    }

    private static function trace(string $from, string $to, bool $offOrigin, string $outcome): void
    {
        if (count(self::$hops) >= 12) { return; }
        self::$hops[] = [
            'hop' => count(self::$hops) + 1,
            'from' => strtolower((string) (parse_url($from, PHP_URL_HOST) ?: '')),
            'to' => strtolower((string) (parse_url($to, PHP_URL_HOST) ?: $to)),
            'off_origin' => $offOrigin ? 1 : 0,
            'outcome' => $outcome,
        ];
        if ($outcome === 'followed') {
            self::$finalHost = strtolower((string) (parse_url($to, PHP_URL_HOST) ?: ''));
        }
    }

    private static function buildOriginUrl(array $origin, string $path, array $publicQuery): string
    {
        $url = sprintf('%s://%s:%d', $origin['scheme'], $origin['host'], (int) $origin['port']);
        if (!empty($origin['base_path'])) {
            $url .= '/' . ltrim((string) $origin['base_path'], '/');
        }
        $url .= '/' . ltrim($path, '/');

        // Fluxo XUI clássico: as credenciais do ASSINANTE vêm na query pública e
        // são repassadas como estão. As credenciais da origem só entram quando o
        // assinante não mandou nada (origem com conta única).
        if (empty($publicQuery['username']) && !empty($origin['auth_user'])) {
            $publicQuery['username'] = $origin['auth_user'];
            $publicQuery['password'] = (string) ($origin['auth_pass'] ?? '');
        }
        unset($publicQuery['t']);
        if (!empty($publicQuery)) {
            $url .= '?' . http_build_query($publicQuery);
        }
        return $url;
    }

    /**
     * Se a origem definiu host_header, envia isso em vez do host da URL.
     * Isso permite conectar por IP (tipo A) mas se apresentar ao XUI com o
     * hostname que o vhost dele espera.
     */
    private static function buildHeaders(array $origin, array $extra = []): array
    {
        $headers = ['Accept: */*'];
        if (!empty($origin['host_header']) && empty($origin['__off_origin'])) {
            $headers[] = 'Host: ' . $origin['host_header'];
        }
        foreach ($extra as $h) {
            $headers[] = $h;
        }
        return $headers;
    }

    /**
     * Redirect é seguido quando o destino é relativo, quando aponta para um host
     * da própria origem ou, no caso de "Direct Source" (o XUI devolve 302 para
     * um CDN de terceiros, ex.: readyondemand.click), quando o host é público.
     *
     * O destino NUNCA é repassado ao player: a VPS busca e reencaminha o corpo,
     * então nem a origem nem o direct source aparecem para o cliente.
     * Bloqueamos loopback/redes privadas para não virar um SSRF interno.
     */
    private static function safeRedirectTarget(array $origin, string $currentUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            self::trace($currentUrl, '', false, 'empty_location');
            return null;
        }
        if (!preg_match('#^https?://#i', $location)) {
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
                self::trace($currentUrl, $location, false, 'blocked_scheme');
                return null; // só http(s)
            }
            $base = parse_url($currentUrl);
            if (!$base || empty($base['host'])) {
                self::trace($currentUrl, $location, false, 'blocked_relative');
                return null;
            }
            $prefix = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
            $next = $prefix . '/' . ltrim($location, '/');
            self::trace($currentUrl, $next, false, 'followed');
            return $next;
        }
        $host = strtolower((string) (parse_url($location, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            self::trace($currentUrl, $location, false, 'blocked_no_host');
            return null;
        }
        if (in_array($host, PlaylistRewriter::originHosts($origin), true)) {
            self::trace($currentUrl, $location, false, 'followed');
            return $location;
        }
        if (!self::followExternal()) {
            self::trace($currentUrl, $location, true, 'blocked_external_disabled');
            return null;
        }
        if (!self::isPublicHost($host)) {
            self::trace($currentUrl, $location, true, 'blocked_private_host');
            return null;
        }
        self::trace($currentUrl, $location, true, 'followed');
        return $location;
    }

    /** Direct Source: por padrão ligado, senão filme/série simplesmente não toca. */
    private static function followExternal(): bool
    {
        return (int) SettingsRepository::get('follow_external_redirects', 1) === 1;
    }

    /**
     * Repassa o User-Agent do player (XCIPTV, Smarters, TiviMate, IBO, VLC).
     * Muitos XUI e CDNs de direct source respondem diferente para UA genérico,
     * então imitar o cliente é o que dá maior compatibilidade real.
     */
    private static function clientUserAgent(): string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '' || $ua === '-') {
            return 'VLC/3.0.20 LibVLC/3.0.20';
        }
        return preg_replace('/[^\x20-\x7e]/', '', $ua) ?: 'VLC/3.0.20 LibVLC/3.0.20';
    }

    /**
     * Depois de um hop para fora da origem, o Host header do XUI não vale mais
     * (o CDN de direct source rejeitaria). Marca o contexto do hop atual.
     */
    private static function markHop(array $origin, string $url): array
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $origin['__off_origin'] = !in_array($host, PlaylistRewriter::originHosts($origin), true);
        return $origin;
    }

    /** Rejeita loopback, link-local e redes privadas (proteção anti-SSRF). */
    private static function isPublicHost(string $host): bool
    {
        if ($host === 'localhost') return false;
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
        if ($ip === null) {
            return (bool) filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        }
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * @return array{status:int,body:string,content_type:string,bytes:int}
     */
    public static function fetchBuffered(array $origin, string $path, array $query): array
    {
        $url = self::buildOriginUrl($origin, $path, $query);
        $hops = 0;
        do {
        $followed = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Nunca seguir Location automaticamente: um redirect da origem para
            // outro host faria o proxy servir (e expor) terceiros.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => self::clientUserAgent(),
            CURLOPT_HTTPHEADER => self::buildHeaders($origin),
            CURLOPT_HEADER => true,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($response === false) {
            return ['status' => 502, 'body' => '', 'content_type' => 'text/plain', 'bytes' => 0];
        }
        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize) ?: '';
        if ($status >= 300 && $status < 400 && $hops < 3
            && preg_match('/^location:\s*(.+)$/mi', $rawHeaders, $m)) {
            $next = self::safeRedirectTarget($origin, $url, $m[1]);
            if ($next !== null) {
                $url = $next;
                $origin = self::markHop($origin, $next);
                $hops++;
                $followed = true;
                continue;
            }
            return ['status' => 502, 'body' => '', 'content_type' => 'text/plain', 'bytes' => 0];
        }
        return [
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'bytes' => strlen($body),
        ];
        } while ($followed);
        return ['status' => 502, 'body' => '', 'content_type' => 'text/plain', 'bytes' => 0];
    }

    /**
     * Streaming: escreve chunk a chunk direto no output do PHP.
     * @return array{status:int,bytes:int}
     */
    public static function stream(array $origin, string $path, array $query, string $forwardedRange = ''): array
    {
        return self::pump($origin, $path, $query, $forwardedRange, null);
    }

    /**
     * Streaming TEXTUAL: puxa da origem e reescreve LINHA A LINHA enquanto os
     * bytes chegam. Playlist de 90 MB sai com memória constante (~1 linha),
     * em vez dos ~350 MB do modo bufferizado.
     *
     * @param array $ctx contexto de PlaylistRewriter::compile()
     * @return array{status:int,bytes:int}
     */
    public static function streamTextual(array $origin, string $path, array $query, array $ctx, string $forcedType = ''): array
    {
        return self::pump($origin, $path, $query, '', $ctx, $forcedType, []);
    }

    private static function pump(
        array $origin,
        string $path,
        array $query,
        string $forwardedRange = '',
        ?array $ctx = null,
        string $forcedType = '',
        array $extraResponseHeaders = []
    ): array
    {
        $url = self::buildOriginUrl($origin, $path, $query);
        $relayUrl = trim((string) Config::get('xui_internal_relay_url', ''));
        $relayToken = trim((string) Config::get('xui_internal_relay_token', ''));
        $xuiOrigin = Config::get('xui_origin');
        $xuiHost = is_array($xuiOrigin) ? strtolower(trim((string) ($xuiOrigin['host'] ?? ''))) : '';
        $originHost = strtolower(trim((string) ($origin['host'] ?? '')));
        $useRelay = $relayUrl !== '' && $relayToken !== '' && $xuiHost !== '' && $originHost !== '' && $originHost === $xuiHost;
        if ($useRelay) {
            $join = str_contains($relayUrl, '?') ? '&' : '?';
            $url = $relayUrl . $join . 'target=' . rawurlencode($url);
        }
        $bytesOut = 0;
        $headersSent = false;
        $status = 0;
        $redirect = '';
        $hops = 0;
        $pending = '';           // resto de linha incompleta (modo textual)
        $textual = $ctx !== null;

        redo:

        if ($textual && !$headersSent) {
            if ($forcedType !== '') {
                header('Content-Type: ' . $forcedType);
            }
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            foreach ($extraResponseHeaders as $responseHeader) {
                header($responseHeader);
            }
        }

        $ch = curl_init($url);
        $extra = [];
        if ($forwardedRange !== '') {
            $extra[] = 'Range: ' . $forwardedRange;
        }
        $headers = self::buildHeaders($origin, $extra);
        if ($useRelay) {
            $headers[] = 'X-Relay-Token: ' . $relayToken;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            // Redirects são resolvidos manualmente com allowlist de host.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_USERAGENT => self::clientUserAgent(),
            CURLOPT_BUFFERSIZE => 128 * 1024,
        ]);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$status, &$headersSent, &$redirect, $textual, $forcedType, $extraResponseHeaders) {
            $len = strlen($header);
            $line = trim($header);
            if ($line === '') {
                if ($textual && !$headersSent) {
                    if ($status === 0) {
                        $status = 200;
                    }
                    http_response_code($status);
                    $headersSent = true;
                }
                return $len;
            }
            if (!$headersSent && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                return $len;
            }
            if ($status >= 300 && $status < 400) {
                if (stripos($line, 'location:') === 0) {
                    $redirect = trim(substr($line, 9));
                }
                return $len; // nunca repassa Location da origem ao cliente
            }
            // Em modo textual o corpo muda de tamanho na reescrita: Content-Length
            // da origem seria mentira e trava o player.
            $allowed = $textual
                ? ['content-type']
                : ['content-type', 'content-length', 'content-range', 'accept-ranges', 'cache-control', 'last-modified'];
            $lower = strtolower($line);
            foreach ($allowed as $prefix) {
                if (str_starts_with($lower, $prefix . ':')) {
                    if ($textual && $forcedType !== '') {
                        header('Content-Type: ' . $forcedType);
                    } else {
                        header($line);
                    }
                    break;
                }
            }
            return $len;
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$bytesOut, &$headersSent, &$status, &$pending, $ctx, $textual, $forcedType) {
            if ($status >= 300 && $status < 400) {
                return strlen($chunk); // corpo de redirect é descartado
            }
            $received = strlen($chunk);
            if (!$headersSent) {
                if ($status === 0) {
                    $status = 200;
                }
                http_response_code($status);
                $headersSent = true;
            }
            if ($textual) {
                $buf = $pending . $chunk;
                $pending = '';
                $start = 0;
                $out = '';
                while (($pos = strpos($buf, "\n", $start)) !== false) {
                    $line = substr($buf, $start, $pos - $start);
                    $eol = "\n";
                    if ($line !== '' && substr($line, -1) === "\r") {
                        $line = substr($line, 0, -1);
                        $eol = "\r\n";
                    }
                    $rewritten = PlaylistRewriter::rewriteLine($ctx, $line);
                    if (CredentialGuard::tripped()) {
                        // Conteúdo do usuário ERRADO: aborta a entrega imediatamente.
                        self::$swapAborted = true;
                        $pending = '';
                        return 0; // encerra o cURL
                    }
                    $out .= $rewritten . $eol;
                    $start = $pos + 1;
                }
                $pending = substr($buf, $start);
                if ($out !== '') {
                    echo $out;
                    $bytesOut += strlen($out);
                    @ob_flush();
                    @flush();
                    self::heartbeat();
                }
                return $received;
            }
            echo $chunk;
            $bytesOut += $received;
            @ob_flush();
            @flush();
            self::heartbeat();
            return $received;
        });
        curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($textual && $pending !== '' && $headersSent) {
            $tail = PlaylistRewriter::rewriteLine($ctx, $pending);
            $pending = '';
            if (CredentialGuard::tripped()) {
                self::$swapAborted = true;
                return ['status' => 502, 'bytes' => $bytesOut];
            }
            echo $tail;
            $bytesOut += strlen($tail);
            @ob_flush();
            @flush();
            self::heartbeat();
        }
        if (self::$swapAborted) {
            if (!$headersSent) {
                http_response_code(502);
            }
            return ['status' => 502, 'bytes' => $bytesOut];
        }
        if ($status >= 300 && $status < 400 && !$headersSent && $hops < 3) {
            $next = self::safeRedirectTarget($origin, $url, $redirect);
            $redirect = '';
            if ($next !== null) {
                $url = $next;
                $origin = self::markHop($origin, $next);
                if ($textual && !empty($origin['__off_origin'])) {
                    // O host do direct source vira sensível: mascarar no corpo.
                    $ctx = PlaylistRewriter::addHost($ctx, (string) (parse_url($next, PHP_URL_HOST) ?: ''));
                }
                $hops++;
                $status = 0;
                goto redo;
            }
            http_response_code(502);
            return ['status' => 502, 'bytes' => 0];
        }
        if ($err !== 0 && !$headersSent) {
            http_response_code(502);
            return ['status' => 502, 'bytes' => 0];
        }
        return ['status' => $status ?: 200, 'bytes' => $bytesOut];
    }
}
