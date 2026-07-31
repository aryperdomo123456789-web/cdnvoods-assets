<?php

/**
 * AppCodeRouter — resolve, POR USERNAME, qual XUI atende aquele assinante.
 *
 * Fluxo em um request público que chega pelo DNS do app:
 *
 *   1. cache quente  -> app_user_routes (1 SELECT por índice único)  => devolve na hora
 *   2. cache negativo-> usuário já falhou há pouco                    => devolve null
 *   3. rota textual  -> descoberta (probe player_api em cada XUI)     => gruda e devolve
 *   4. rota binária  -> NUNCA descobre; devolve null (fallback)
 *
 * Grudar (pin) o username em um único XUI é o que garante que a lista de
 * reprodução, o EPG e as conexões nunca embaralhem entre servidores.
 */
final class AppCodeRouter
{
    /** Timeout de cada probe. Curto de propósito: 8 servidores x 3 portas não pode travar o player. */
    private const PROBE_TIMEOUT = 3;
    private const PROBE_CONNECT_TIMEOUT = 2;

    /** Teto duro de probes por descoberta — protege a CPU da VPS. */
    private const MAX_PROBES = 24;

    private static string $lastReason = '';
    private static string $lastMode = '';

    /** Quantos XUIs estavam INALCANÇÁVEIS na última varredura. */
    private static int $probeErrors = 0;

    public static function lastReason(): string { return self::$lastReason; }
    public static function lastMode(): string { return self::$lastMode; }

    /**
     * @return array<string,mixed>|null origem no formato consumido por StreamProxy
     */
    public static function resolve(string $username, string $password, bool $mayDiscover): ?array
    {
        self::$lastReason = '';
        self::$lastMode = '';

        if ($username === '' || !AppCode::enabled()) {
            self::$lastReason = 'disabled';
            return null;
        }

        // 1) Cache quente — caminho de 99% dos requests (inclusive todo .ts).
        $route = AppCode::route($username);
        if ($route && (string) $route['status'] === 'ok') {
            $server = AppCode::server((int) $route['server_id']);
            if ($server && (int) $server['active'] === 1) {
                AppCode::touchRoute($username, (int) $route['last_epoch']);
                self::$lastMode = 'cache';
                self::$lastReason = 'route_cached';
                return AppCode::toOrigin($server, (int) $route['port'], (string) $route['scheme']);
            }
            // Servidor sumiu ou foi desativado: a rota morreu, redescobrir.
            AppCode::unpin($username);
        }

        if (!$mayDiscover) {
            self::$lastReason = 'no_discovery_on_binary';
            return null;
        }

        if (AppCode::isNegative($username)) {
            self::$lastReason = 'negative_cache';
            return null;
        }

        // 3) Descoberta com lock: 10 players do mesmo user = 1 varredura só.
        if (!AppCode::acquireLock($username)) {
            // Outro processo está descobrindo. Espera curta e tenta o cache.
            usleep(400000);
            $route = AppCode::route($username);
            if ($route && (string) $route['status'] === 'ok') {
                $server = AppCode::server((int) $route['server_id']);
                if ($server) {
                    self::$lastMode = 'cache_after_lock';
                    self::$lastReason = 'route_cached';
                    return AppCode::toOrigin($server, (int) $route['port'], (string) $route['scheme']);
                }
            }
            self::$lastReason = 'discovery_locked';
            return null;
        }

        try {
            $found = self::discover($username, $password);
        } finally {
            AppCode::releaseLock($username);
        }

        if ($found === null) {
            if (self::$probeErrors === 0) {
                // Varredura limpa: todos os XUIs responderam e nenhum conhece
                // esse usuário. Aí sim vale guardar o negativo.
                AppCode::markNegative($username);
                self::$lastReason = 'not_found_anywhere';
            } else {
                self::$lastReason = 'xui_unreachable';
            }
            return null;
        }

        AppCode::clearNegative($username);
        AppCode::pinRoute($username, $found['server'], $found['port'], $found['scheme']);
        self::$lastMode = 'discovery';
        self::$lastReason = 'discovered';
        return AppCode::toOrigin($found['server'], $found['port'], $found['scheme']);
    }

    /**
     * Teste de saúde do painel: bate no XUI sem credencial e só quer saber se
     * existe um player_api vivo ali. Não gruda nada e não cria rota.
     * @return array{ok:bool,port:int,scheme:string,ms:int,reason:string}
     */
    public static function probeServer(array $server): array
    {
        $inicio = microtime(true);
        foreach (self::candidates($server) as [$scheme, $port]) {
            $url = sprintf('%s://%s:%d/player_api.php', $scheme, (string) $server['host'], $port);
            $ch = curl_init($url);
            $headers = ['Accept: */*'];
            $hh = trim((string) ($server['host_header'] ?? ''));
            if ($hh !== '') { $headers[] = 'Host: ' . $hh; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::PROBE_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::PROBE_CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (cdnvoods-appcode)',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $erro = curl_error($ch);
            curl_close($ch);
            if ($code > 0 && $code < 500) {
                return [
                    'ok' => true, 'port' => $port, 'scheme' => $scheme,
                    'ms' => (int) round((microtime(true) - $inicio) * 1000), 'reason' => 'http_' . $code,
                ];
            }
            $ultimo = $erro !== '' ? $erro : ('http_' . $code);
        }
        return [
            'ok' => false, 'port' => 0, 'scheme' => '',
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
            'reason' => $ultimo ?? 'sem portas candidatas',
        ];
    }

    /**
     * Varre os XUIs cadastrados procurando quem autentica esse username.
     * @return array{server:array,port:int,scheme:string}|null
     */
    public static function discover(string $username, string $password): ?array
    {
        self::$probeErrors = 0;
        $servers = AppCode::servers(true);
        if (!$servers) { return null; }

        $probes = 0;
        foreach ($servers as $server) {
            foreach (self::candidates($server) as [$scheme, $port]) {
                if ($probes >= self::MAX_PROBES) { return null; }
                $probes++;
                if (self::authOk($server, $scheme, $port, $username, $password)) {
                    return ['server' => $server, 'port' => $port, 'scheme' => $scheme];
                }
            }
        }
        return null;
    }

    /**
     * Portas/protocolos a testar. Porta declarada no cadastro vem PRIMEIRO —
     * quem cadastra direito nunca paga o custo da varredura.
     * @return array<int,array{0:string,1:int}>
     */
    public static function candidates(array $server): array
    {
        $out = [];
        $seen = [];
        $declared = (int) ($server['port'] ?? 0);
        $scheme = (string) ($server['scheme'] ?? 'http');

        if ($declared >= 1 && $declared <= 65535) {
            $out[] = [$scheme, $declared];
            $seen[$scheme . ':' . $declared] = true;
            // Porta declarada é confiança total: não varremos mais nada.
            return $out;
        }

        foreach (AppCode::DEFAULT_PORTS as $p) {
            $sc = in_array($p, [443, 8443, 2083, 2096], true) ? 'https' : 'http';
            $k = $sc . ':' . $p;
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $out[] = [$sc, $p];
        }
        return $out;
    }

    /** Um probe: player_api.php sem action. Resposta válida traz user_info.auth = 1. */
    public static function authOk(array $server, string $scheme, int $port, string $username, string $password): bool
    {
        $host = (string) $server['host'];
        $url = sprintf('%s://%s:%d/player_api.php?username=%s&password=%s',
            $scheme, $host, $port, rawurlencode($username), rawurlencode($password));

        $ch = curl_init($url);
        $headers = ['Accept: */*'];
        $hostHeader = trim((string) ($server['host_header'] ?? ''));
        if ($hostHeader !== '') { $headers[] = 'Host: ' . $hostHeader; }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::PROBE_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::PROBE_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (cdnvoods-appcode)',
            // Resposta do player_api sem action é pequena; teto evita surpresa.
            CURLOPT_BUFFERSIZE     => 8192,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netErr = curl_errno($ch);
        curl_close($ch);

        // XUI fora do ar não é "usuário não existe": não pode virar cache
        // negativo, senão o assinante fica 5 minutos travado após o servidor voltar.
        if ($netErr !== 0 || $code === 0 || $code >= 500) { self::$probeErrors++; }

        if ($code !== 200 || !is_string($body) || $body === '') { return false; }
        return self::authenticated($body);
    }

    /** Regra de aceite: precisa ser JSON de XUI com auth verdadeiro. */
    public static function authenticated(string $body): bool
    {
        if (stripos($body, 'user_info') === false) { return false; }
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['user_info']) || !is_array($json['user_info'])) {
            return false;
        }
        $info = $json['user_info'];
        if (!isset($info['auth'])) { return false; }
        if ((int) $info['auth'] !== 1) { return false; }
        $status = strtolower((string) ($info['status'] ?? 'active'));
        // "Expired"/"Disabled" ainda é o XUI DONO desse usuário: grudamos mesmo
        // assim, para que o próprio XUI devolva a mensagem correta ao app.
        return $status !== '';
    }
}
