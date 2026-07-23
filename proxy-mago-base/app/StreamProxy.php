<?php

/**
 * StreamProxy — puxa da origem XUI via cURL e devolve ao cliente.
 *
 * - m3u/m3u8 / get.php: buffer em memória, reescreve URL/credenciais, devolve.
 * - .ts / demais: streaming chunk a chunk via CURLOPT_WRITEFUNCTION (baixa RAM).
 *
 * Nunca vaza:
 *  - IP/host da origem em cabeçalhos de resposta
 *  - user/pass da origem em cabeçalhos ou body reescrito
 */
final class StreamProxy
{
    /**
     * Constrói a URL final a partir do path público que chegou no proxy.
     * Se a origem tem base_path, ele é prefixado. O painel injeta user/pass
     * da origem no lugar dos que o player mandou (o player só carrega token
     * público, nunca credenciais reais).
     */
    private static function buildOriginUrl(array $origin, string $path, array $publicQuery): string
    {
        $url = sprintf('%s://%s:%d', $origin['scheme'], $origin['host'], (int) $origin['port']);
        if (!empty($origin['base_path'])) {
            $url .= '/' . ltrim((string) $origin['base_path'], '/');
        }
        $url .= '/' . ltrim($path, '/');

        // Reescreve credenciais: se a origem tem user/pass e é um get.php estilo XUI,
        // injetamos as credenciais reais no lugar do que veio do cliente.
        if (!empty($origin['auth_user']) && !empty($origin['auth_pass'])) {
            $publicQuery['username'] = $origin['auth_user'];
            $publicQuery['password'] = $origin['auth_pass'];
        }
        // Remove nosso token interno da querystring enviada à origem.
        unset($publicQuery['t']);
        if (!empty($publicQuery)) {
            $url .= '?' . http_build_query($publicQuery);
        }
        return $url;
    }

    /**
     * @return array{status:int,body:string,content_type:string,bytes:int}
     */
    public static function fetchBuffered(array $origin, string $path, array $query): array
    {
        $url = self::buildOriginUrl($origin, $path, $query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'ProxyMago/1.0',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
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
        $body = substr($response, $headerSize) ?: '';
        return [
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'bytes' => strlen($body),
        ];
    }

    /**
     * Streaming: escreve chunk a chunk direto no output do PHP.
     * @return array{status:int,bytes:int}
     */
    public static function stream(array $origin, string $path, array $query, string $forwardedRange = ''): array
    {
        $url = self::buildOriginUrl($origin, $path, $query);
        $bytesOut = 0;
        $headersSent = false;
        $status = 0;

        $ch = curl_init($url);
        $headers = ['Accept: */*'];
        if ($forwardedRange !== '') {
            $headers[] = 'Range: ' . $forwardedRange;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_USERAGENT => 'ProxyMago/1.0',
            CURLOPT_BUFFERSIZE => 128 * 1024,
        ]);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$status, &$headersSent) {
            $len = strlen($header);
            $line = trim($header);
            if ($line === '') {
                return $len;
            }
            if (!$headersSent && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                return $len;
            }
            // Repassa apenas cabeçalhos seguros. Nada que revele origem.
            $allowed = ['content-type', 'content-length', 'content-range', 'accept-ranges', 'cache-control', 'last-modified'];
            $lower = strtolower($line);
            foreach ($allowed as $prefix) {
                if (str_starts_with($lower, $prefix . ':')) {
                    header($line);
                    break;
                }
            }
            return $len;
        });
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$bytesOut, &$headersSent, &$status) {
            if (!$headersSent) {
                if ($status === 0) {
                    $status = 200;
                }
                http_response_code($status);
                $headersSent = true;
            }
            echo $chunk;
            $bytesOut += strlen($chunk);
            @ob_flush();
            @flush();
            return strlen($chunk);
        });
        curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err !== 0 && !$headersSent) {
            http_response_code(502);
            return ['status' => 502, 'bytes' => 0];
        }
        return ['status' => $status ?: 200, 'bytes' => $bytesOut];
    }
}
