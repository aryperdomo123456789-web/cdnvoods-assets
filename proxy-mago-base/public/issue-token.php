<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard.php');
    exit;
}
csrf_verify();

$aliasId = (int) ($_POST['alias_id'] ?? 0);
$allowedIp = trim((string) ($_POST['allowed_ip'] ?? ''));
$ttl = (int) ($_POST['ttl'] ?? 0);

$alias = AliasRepository::find($aliasId);
if (!$alias) {
    http_response_code(422);
    exit('Alias inexistente.');
}

$issued = Tokens::issue($aliasId, $allowedIp, $ttl > 0 ? $ttl : null);
Audit::log('token_issue', 'Token issued for alias #' . $aliasId, $_SERVER['REMOTE_ADDR'] ?? '-', $_SERVER['HTTP_USER_AGENT'] ?? '-');

$_SESSION['last_issued_token'] = [
    'alias_hostname' => $alias['hostname'],
    'token' => $issued['token'],
    'expires_at' => $issued['expires_at'],
];

header('Location: /dashboard.php#tokens');
exit;
