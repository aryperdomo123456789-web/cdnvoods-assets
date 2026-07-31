<?php

/**
 * Reescrita de playlists/JSON/EPG para esconder host/porta/credenciais da origem.
 *
 * A partir da v3 a reescrita é COMPILADA UMA VEZ (compile()) e aplicada
 * LINHA A LINHA (rewriteLine()). Isso permite que playlists gigantes
 * (90 MB+) sejam reescritas em streaming, com memória constante, em vez de
 * carregar o corpo inteiro na RAM do PHP-FPM.
 */
final class PlaylistRewriter
{
    /**
     * Todos os hosts que pertencem à origem e NUNCA podem sair no corpo:
     * host cadastrado, host_header do vhost e extra_hosts (main alternativo,
     * subdomínios de CDN interna do XUI).
     */
    public static function originHosts(array $origin): array
    {
        $hosts = [];
        foreach ([$origin['host'] ?? '', $origin['host_header'] ?? ''] as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '') $hosts[$h] = true;
        }
        foreach (preg_split('/[\s,;]+/', (string) ($origin['extra_hosts'] ?? '')) as $h) {
            $h = strtolower(trim($h));
            if ($h !== '') $hosts[$h] = true;
        }
        return array_keys($hosts);
    }

    /**
     * Pré-compila os padrões da reescrita. Feito uma vez por request.
     *
     * @return array{patterns:string[],replacements:string[],creds:string[],token:string,publicBase:string}
     */
    public static function compile(
        array $origin,
        string $publicHost,
        string $token = '',
        string $publicScheme = 'https'
    ): array {
        $publicScheme = $publicScheme === 'http' ? 'http' : 'https';
        $publicBase = $publicScheme . '://' . $publicHost;
        $originPort = (int) ($origin['port'] ?? 80);

        $patterns = [];
        $replacements = [];

        foreach (self::originHosts($origin) as $h) {
            $q = preg_quote($h, '#');
            // scheme://host[:porta]  e  //host[:porta]
            $patterns[]     = '#(https?:)?//' . $q . '(:\d+)?#i';
            $replacements[] = $publicBase;
            // Variante com barras escapadas de JSON: http:\/\/host\/...
            $patterns[]     = '#(https?:)?\\\\/\\\\/' . $q . '(:\d+)?#i';
            $replacements[] = str_replace('/', '\\/', $publicBase);
            // Sobra do host "cru" (EPG, campos de JSON tipo "server_url").
            $patterns[]     = '#\b' . $q . '(:' . $originPort . ')?\b#i';
            $replacements[] = $publicHost;
        }

        // Credenciais da ORIGEM nunca podem vazar. As do assinante ficam.
        $creds = [];
        foreach (['auth_user', 'auth_pass'] as $k) {
            $v = (string) ($origin[$k] ?? '');
            if ($v !== '' && strlen($v) >= 3) {
                $creds[] = $v;
                $enc = rawurlencode($v);
                if ($enc !== $v) $creds[] = $enc;
            }
        }

        return [
            'patterns'     => $patterns,
            'replacements' => $replacements,
            'creds'        => $creds,
            'token'        => $token,
            'publicBase'   => $publicBase,
        ];
    }

    /**
     * Aplica a reescrita a UMA linha (sem quebra de linha no fim).
     * URLs nunca atravessam linhas, então isso é seguro e barato.
     */
    public static function rewriteLine(array $ctx, string $line): string
    {
        // Anti-embaralhamento: inspeciona a linha ORIGINAL da origem antes de
        // qualquer reescrita. Custo: um stripos por linha e só quando armado.
        if (CredentialGuard::armed()) {
            CredentialGuard::inspect($line);
        }
        return self::apply($ctx, $line);
    }

    /**
     * Fast path para respostas gigantes do player_api.
     *
     * Se o corpo não contém nenhum host/credencial sensível da origem, não há
     * motivo para passar por regex pesada em dezenas de megabytes de JSON.
     */
    public static function needsRewrite(array $origin, string $body): bool
    {
        if ($body === '') {
            return false;
        }
        foreach (self::originHosts($origin) as $host) {
            if ($host !== '' && stripos($body, $host) !== false) {
                return true;
            }
        }
        foreach (['auth_user', 'auth_pass'] as $key) {
            $value = (string) ($origin[$key] ?? '');
            if ($value !== '' && stripos($body, $value) !== false) {
                return true;
            }
            $encoded = rawurlencode($value);
            if ($encoded !== '' && $encoded !== $value && stripos($body, $encoded) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Direct Source: quando o XUI devolve 302 para um CDN de terceiros
     * (ex.: readyondemand.click), esse host passa a ser tão sensível quanto a
     * origem. Registramos o host no contexto para que ele também seja
     * mascarado no corpo (playlists .m3u8 vindas do CDN).
     */
    public static function addHost(array $ctx, string $host): array
    {
        $host = strtolower(trim($host));
        if ($host === '' || in_array($host, $ctx['dynamic_hosts'] ?? [], true)) {
            return $ctx;
        }
        $ctx['dynamic_hosts'][] = $host;
        $q = preg_quote($host, '#');
        $publicHost = preg_replace('#^https?://#', '', $ctx['publicBase']);
        $ctx['patterns'][]     = '#(https?:)?//' . $q . '(:\d+)?#i';
        $ctx['replacements'][] = $ctx['publicBase'];
        $ctx['patterns'][]     = '#(https?:)?\\\\/\\\\/' . $q . '(:\d+)?#i';
        $ctx['replacements'][] = str_replace('/', '\\/', $ctx['publicBase']);
        $ctx['patterns'][]     = '#\b' . $q . '\b#i';
        $ctx['replacements'][] = $publicHost;
        return $ctx;
    }

    private static function apply(array $ctx, string $line): string
    {
        if ($line === '') {
            return $line;
        }
        if ($ctx['patterns']) {
            $line = preg_replace($ctx['patterns'], $ctx['replacements'], $line) ?? $line;
        }
        if ($ctx['creds']) {
            $line = str_replace($ctx['creds'], '', $line);
        }
        if ($ctx['token'] !== '' && $line[0] !== '#'
            && stripos($line, $ctx['publicBase']) === 0
            && !preg_match('/[?&]t=/', $line)) {
            $line .= (strpos($line, '?') === false ? '?' : '&') . 't=' . rawurlencode($ctx['token']);
        }
        return $line;
    }

    /**
     * Compatibilidade: reescreve um corpo inteiro já carregado em memória.
     * Usa o mesmo caminho linha a linha do modo streaming.
     */
    public static function rewrite(
        string $body,
        array $origin,
        string $publicHost,
        string $token = '',
        string $publicScheme = 'https'
    ): string {
        $ctx = self::compile($origin, $publicHost, $token, $publicScheme);
        $out = '';
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $pos = strpos($body, "\n", $offset);
            if ($pos === false) {
                $out .= self::rewriteLine($ctx, substr($body, $offset));
                break;
            }
            $line = substr($body, $offset, $pos - $offset);
            $eol = "\n";
            if ($line !== '' && substr($line, -1) === "\r") {
                $line = substr($line, 0, -1);
                $eol = "\r\n";
            }
            $out .= self::rewriteLine($ctx, $line) . $eol;
            $offset = $pos + 1;
        }
        return $out;
    }
}
