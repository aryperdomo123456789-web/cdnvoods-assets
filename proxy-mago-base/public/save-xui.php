<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

$type   = strtolower(trim((string) ($_POST['type'] ?? 'a'))) === 'cname' ? 'cname' : 'a';
$target = strtolower(trim((string) ($_POST['target'] ?? '')));
$port   = (int) ($_POST['port'] ?? 80);

$domainRe = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

function fail_xui(string $code): void { header('Location: /dashboard.php?err=' . $code); exit; }

if ($port < 1 || $port > 65535) { fail_xui('porta'); }
if ($type === 'a' && filter_var($target, FILTER_VALIDATE_IP) === false) { fail_xui('ip'); }
if ($type === 'cname' && !preg_match($domainRe, $target)) { fail_xui('host'); }

$id = XuiOrigin::save($type, $target, $port);

Audit::log('xui.save', 'origem XUI #' . $id . ' (' . $type . ')');
$_SESSION['flash'] = 'Origem XUI salva. Todos os domínios protegidos já usam ela.';
header('Location: /dashboard.php');