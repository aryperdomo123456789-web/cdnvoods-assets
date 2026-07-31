<?php

/**
 * Parser real de `streams.stream_source` do XUI (banco `xui`, host 38.190.176.170).
 *
 * Este XUI específico NÃO usa um formato único. Na prática aparecem:
 *
 *   1. JSON textual de array:      ["http://cdn.exemplo/live.ts","http://bkp/live.ts"]
 *   2. JSON textual de objeto:     {"0":"http:\/\/cdn.exemplo\/live.ts"}
 *   3. string simples:             http://cdn.exemplo/live.ts
 *   4. múltiplas URLs por quebra:  uma por linha, ou separadas por vírgula
 *   5. lixo/legado:                vazio, "0", "null", HTML, caminho local
 *
 * A CDN não pode explodir em nenhum desses casos. O parse é puro PHP, roda só
 * em job (nunca no caminho do stream) e sempre devolve um `parse_status`
 * auditável, para que o painel consiga mostrar exatamente por que um stream
 * ficou sem host de direct source.
 *
 * parse_status:
 *   ok          -> pelo menos uma URL absoluta com host válido
 *   empty       -> stream_source vazio/nulo (normal em conteúdo local do XUI)
 *   no_host     -> tinha conteúdo, mas nenhuma URL com host extraível
 *   bad_json    -> começava como JSON e não decodificou
 *   unsupported -> formato inesperado (não é URL, não é JSON, não é lista)
 */
final class DirectSourceParser
{
    /** Esquemas que valem como "fonte externa real" de vídeo. */
    private const SCHEMES = ['http', 'https', 'rtmp', 'rtmps', 'rtsp', 'udp', 'rtp', 'srt', 'mms'];

    /**
     * @return array{
     *   hosts: array<int,string>, urls: array<int,string>, host: string,
     *   count: int, status: string, error: string
     * }
     */
    public static function parse(?string $raw): array
    {
        $out = ['hosts' => [], 'urls' => [], 'host' => '', 'count' => 0, 'status' => 'empty', 'error' => ''];
        $raw = trim((string) $raw);
        if ($raw === '' || in_array(strtolower($raw), ['0', 'null', '[]', '{}', '""'], true)) {
            return $out;
        }

        [$candidates, $status, $error] = self::candidates($raw);
        $out['status'] = $status;
        $out['error'] = $error;

        $urls = [];
        $hosts = [];
        foreach ($candidates as $candidate) {
            $url = trim((string) $candidate);
            if ($url === '') { continue; }
            $host = self::hostOf($url);
            if ($host === '') { continue; }
            $urls[] = self::maskCredentials($url);
            if (!in_array($host, $hosts, true)) { $hosts[] = $host; }
        }

        $out['urls'] = array_slice($urls, 0, 10);
        $out['hosts'] = array_slice($hosts, 0, 10);
        $out['count'] = count($urls);
        $out['host'] = $hosts[0] ?? '';

        if ($out['host'] === '' && $status === 'ok') {
            $out['status'] = 'no_host';
            $out['error'] = $out['error'] ?: 'stream_source sem URL absoluta com host';
        }
        return $out;
    }

    /**
     * Quebra o valor bruto em candidatos a URL, dizendo se o formato foi
     * reconhecido.
     *
     * @return array{0:array<int,string>,1:string,2:string}
     */
    private static function candidates(string $raw): array
    {
        $first = $raw[0];
        if ($first === '[' || $first === '{') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                // Alguns registros vêm com barras escapadas duas vezes.
                $decoded = json_decode(stripslashes($raw), true);
            }
            if (!is_array($decoded)) {
                return [[], 'bad_json', 'json inválido em stream_source'];
            }
            return [self::flatten($decoded), 'ok', ''];
        }

        if (str_contains($raw, "\n") || str_contains($raw, "\r") || str_contains($raw, ',')) {
            $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
            return [array_values(array_filter(array_map('trim', $parts))), 'ok', ''];
        }

        if (self::hostOf($raw) !== '') {
            return [[$raw], 'ok', ''];
        }

        return [[], 'unsupported', 'formato não reconhecido em stream_source'];
    }

    /** @param array<mixed> $data @return array<int,string> */
    private static function flatten(array $data): array
    {
        $out = [];
        array_walk_recursive($data, static function ($value) use (&$out): void {
            if (is_string($value) || is_numeric($value)) { $out[] = (string) $value; }
        });
        return $out;
    }

    /** Host normalizado (minúsculo, sem porta, sem credenciais). */
    public static function hostOf(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '/')) { return ''; }
        if (!preg_match('#^([a-z][a-z0-9+.\-]*)://#i', $url, $m)) { return ''; }
        if (!in_array(strtolower($m[1]), self::SCHEMES, true)) { return ''; }
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost') { return $host === 'localhost' ? 'localhost' : ''; }
        if (!preg_match('/^[a-z0-9\.\-:_]+$/', $host)) { return ''; }
        return $host;
    }

    /** Nunca guardamos user:pass da origem em claro no SQLite do painel. */
    public static function maskCredentials(string $url): string
    {
        $masked = preg_replace('#://[^/@\s]+:[^/@\s]+@#', '://***:***@', $url) ?? $url;
        $masked = preg_replace('#(password=)[^&\s]+#i', '$1***', $masked) ?? $masked;
        return substr($masked, 0, 400);
    }

    /**
     * Modo de origem do direct source a partir da verdade do DB.
     * db_source  -> flag + URL externa cadastrada
     * db_flag    -> flag ligada, mas sem host utilizável (parse falhou/vazio)
     * local      -> não é direct source no DB
     */
    public static function sourceMode(int $directFlag, string $host, string $status): string
    {
        if ($directFlag !== 1) { return 'local'; }
        if ($host !== '' && $status === 'ok') { return 'db_source'; }
        return 'db_flag';
    }
}
