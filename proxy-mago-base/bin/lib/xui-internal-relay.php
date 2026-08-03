<?php

declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$allowedIps = [
    '127.0.0.1' => true,
    '45.140.192.237' => true,
];

$remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
if ($remote === '' || !isset($allowedIps[$remote])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden_ip';
    exit;
}

$token = trim((string) ($_SERVER['HTTP_X_RELAY_TOKEN'] ?? ''));
$expected = '';
$tokenFile = '/home/xui/config/internal-relay.token';
if (is_file($tokenFile)) {
    $expected = trim((string) file_get_contents($tokenFile));
}
if ($expected === '') {
    $expected = trim((string) ($_SERVER['XUI_INTERNAL_RELAY_TOKEN'] ?? ''));
}
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden_token';
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
$scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
$path = trim((string) ($parts['path'] ?? '/'));
if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid_target';
    exit;
}

$allowedHosts = [
    '38.190.176.170' => true,
    'readyondemand.click' => true,
    'highcdnvideo.link' => true,
];

$hostOk = false;
foreach (array_keys($allowedHosts) as $candidate) {
    if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
        $hostOk = true;
        break;
    }
}
if (!$hostOk) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'host_not_allowed';
    exit;
}

$ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$forwardHeaders = [];
foreach (['HTTP_RANGE' => 'Range', 'HTTP_ACCEPT' => 'Accept', 'HTTP_ACCEPT_ENCODING' => 'Accept-Encoding'] as $key => $label) {
    $value = trim((string) ($_SERVER[$key] ?? ''));
    if ($value !== '') {
        $forwardHeaders[] = $label . ': ' . $value;
    }
}
if ($host === '38.190.176.170') {
    $forwardHeaders[] = 'Host: 38.190.176.170';
}

if ($host === '38.190.176.170' && (str_starts_with(strtolower($path), '/movie/') || str_starts_with(strtolower($path), '/series/'))) {
    $resolved = xuiRelayResolveOnce($target, $ua, $forwardHeaders);
    if ($resolved !== '' && $resolved !== $target) {
        $target = $resolved;
    }
}

while (ob_get_level() > 0) {
    @ob_end_clean();
}
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);
ignore_user_abort(false);

$ch = curl_init($target);
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

if ($ua !== '') {
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
}

if ($forwardHeaders !== []) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
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
    echo 'relay_fetch_failed';
}
curl_close($ch);

function xuiRelayResolveOnce(string $target, string $ua, array $headers): string
{
    if (str_contains($target, '/lb-direct-fetch.php')) {
        return '';
    }
    $cmd = ['curl', '-sS', '-D', '-', '-o', '/dev/null', '--max-redirs', '0', '--connect-timeout', '10'];
    if ($ua !== '') {
        $cmd[] = '-A';
        $cmd[] = $ua;
    }
    foreach ($headers as $header) {
        $cmd[] = '-H';
        $cmd[] = $header;
    }
    $cmd[] = $target;
    $escaped = array_map('escapeshellarg', $cmd);
    $output = shell_exec(implode(' ', $escaped) . ' 2>/dev/null');
    if (!is_string($output) || $output === '') {
        return '';
    }
    foreach (preg_split("/\r\n|\n|\r/", $output) as $line) {
        if (stripos($line, 'Location:') === 0) {
            return trim(substr($line, 9));
        }
    }
    return '';
}
