<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/proxy-bootstrap.php';
require_once dirname(__DIR__) . '/app/LbNode.php';
require_once dirname(__DIR__) . '/app/XuiOrigin.php';

header('X-Content-Type-Options: nosniff');

$token = (string) ($_SERVER['HTTP_X_LB_TOKEN'] ?? '');
$node = $token !== '' ? LbNode::findByToken($token) : null;
if (!$node) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$target = trim((string) ($_GET['target'] ?? ''));
if ($target === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'missing_target';
    exit;
}

$parts = parse_url($target);
$host = strtolower(trim((string) ($parts['host'] ?? '')));
$scheme = strtolower(trim((string) ($parts['scheme'] ?? 'http')));
$path = trim((string) ($parts['path'] ?? '/'));
if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid_target';
    exit;
}

$allowed = [];
foreach (preg_split('/[\s,;]+/', strtolower(trim((string) Config::get('brain_direct_fallback_hosts', '')))) ?: [] as $item) {
    $item = trim($item);
    if ($item !== '') {
        $allowed[$item] = true;
    }
}
$xuiOrigin = Config::get('xui_origin');
if (!is_array($xuiOrigin)) {
    $xuiOrigin = XuiOrigin::get();
}
$xuiHost = '';
if (is_array($xuiOrigin)) {
    $xuiHost = strtolower(trim((string) ($xuiOrigin['host'] ?? '')));
}
$isMediaPath = str_starts_with(strtolower($path), '/movie/') || str_starts_with(strtolower($path), '/series/');
$allowViaDirectHost = $allowed !== [] && isset($allowed[$host]);
$allowViaXuiRelay = $xuiHost !== '' && $host === $xuiHost && $isMediaPath;
if (!$allowViaDirectHost && !$allowViaXuiRelay) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'host_not_allowed';
    exit;
}

$relayUrl = trim((string) Config::get('xui_internal_relay_url', ''));
$relayToken = trim((string) Config::get('xui_internal_relay_token', ''));
$upstreamUrl = $target;
if ($relayUrl !== '' && $relayToken !== '' && ($allowViaDirectHost || $allowViaXuiRelay)) {
    $join = str_contains($relayUrl, '?') ? '&' : '?';
    $upstreamUrl = $relayUrl . $join . 'target=' . rawurlencode($target);
}

$ch = curl_init($upstreamUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 6,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FAILONERROR => false,
    CURLOPT_ENCODING => '',
]);

$ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($ua !== '') {
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
}

$headers = [];
foreach (['HTTP_RANGE' => 'Range', 'HTTP_ACCEPT' => 'Accept', 'HTTP_ACCEPT_ENCODING' => 'Accept-Encoding'] as $key => $label) {
    $value = trim((string) ($_SERVER[$key] ?? ''));
    if ($value !== '') {
        $headers[] = $label . ': ' . $value;
    }
}
if ($headers !== []) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}
if ($relayUrl !== '' && $relayToken !== '') {
    $headers[] = 'X-Relay-Token: ' . $relayToken;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}

$responseHeaders = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$responseHeaders): int {
    $len = strlen($line);
    $trim = trim($line);
    if ($trim === '' || stripos($trim, 'HTTP/') === 0) {
        return $len;
    }
    $parts = explode(':', $trim, 2);
    if (count($parts) === 2) {
        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    return $len;
});

$sentHeaders = false;
curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$sentHeaders, &$responseHeaders): int {
    if (!$sentHeaders) {
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        http_response_code($status > 0 ? $status : 502);
        foreach (['content-type', 'content-length', 'accept-ranges', 'content-range', 'cache-control', 'expires'] as $key) {
            if (!empty($responseHeaders[$key])) {
                header(ucwords($key, '-') . ': ' . $responseHeaders[$key]);
            }
        }
        $sentHeaders = true;
    }
    echo $chunk;
    @flush();
    return strlen($chunk);
});

$ok = curl_exec($ch);
if ($ok === false) {
    if (!headers_sent()) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'brain_fetch_failed';
}
curl_close($ch);
