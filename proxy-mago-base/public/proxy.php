<?php

/**
 * Entrada única de tudo que é PROXY (m3u/m3u8/ts/get.php etc.).
 * O Nginx redireciona /get.php e o fallback de streaming para este arquivo.
 *
 * Fluxo:
 *   1. AccessGuard valida alias público, origem, token, rate-limit e UA.
 *   2. Se path é uma playlist (get.php ou *.m3u/*.m3u8), busca buffered e reescreve.
 *   3. Caso contrário, faz streaming direto via cURL.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$host = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host);
$clientIp = AccessGuard::clientIp();
$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '-');

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$parts = parse_url($requestUri);
$path = $parts['path'] ?? '/';
parse_str($parts['query'] ?? '', $query);
$token = (string) ($query['t'] ?? '');

$decision = AccessGuard::check($host, $clientIp, $userAgent, $token);

if (!$decision['ok']) {
    http_response_code($decision['code']);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo 'Denied';
    AccessGuard::logAccess($host, $path, $decision['code'], 0, null, null, $decision['reason']);
    exit;
}

$origin = $decision['origin'];
$alias = $decision['alias'];
$tokenId = $decision['token'] ? (int) $decision['token']['id'] : null;

// Detecta se é playlist (buffered + rewrite) ou stream binário.
$isPlaylist = (bool) preg_match('#(get\.php$|\.m3u8?$)#i', $path);

if ($isPlaylist) {
    // Emite um novo token se o request veio sem um. Senão reaproveita o existente.
    $tokenForRewrite = $token;
    if ($tokenForRewrite === '') {
        $issued = Tokens::issue((int) $alias['id'], '', null);
        $tokenForRewrite = $issued['token'];
    }
    $upstream = StreamProxy::fetchBuffered($origin, $path, $query);
    $publicBody = PlaylistRewriter::rewrite($upstream['body'], $origin, $host, $tokenForRewrite);
    $ct = stripos($upstream['content_type'], 'mpegurl') !== false
        ? $upstream['content_type']
        : 'application/vnd.apple.mpegurl';
    header('Content-Type: ' . $ct);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    http_response_code($upstream['status'] ?: 200);
    echo $publicBody;
    AccessGuard::logAccess($host, $path, $upstream['status'] ?: 200, strlen($publicBody), $tokenId, (int) $origin['id'], 'playlist');
    exit;
}

// Streaming binário (segmentos .ts, HLS parts, etc.)
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
$result = StreamProxy::stream($origin, $path, $query, $range);
AccessGuard::logAccess($host, $path, $result['status'], $result['bytes'], $tokenId, (int) $origin['id'], 'stream');
