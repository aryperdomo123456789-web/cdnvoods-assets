<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

// Vários hostnames públicos, um por linha ou separados por vírgula/espaço.
$raw = (string) ($_POST['hostname'] ?? '');
$domainRe = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

function fail_domain(string $code): void { header('Location: /dashboard.php?err=' . $code); exit; }

$originId = XuiOrigin::id();
if (!$originId) { fail_domain('sem_xui'); }

$hostnames = [];
foreach (preg_split('/[\s,;]+/', strtolower($raw)) as $h) {
    $h = trim($h);
    if ($h === '') { continue; }
    $h = preg_replace('#^https?://#', '', $h);
    $h = preg_replace('#[/:].*$#', '', $h);
    if (!preg_match($domainRe, $h)) { fail_domain('dominio'); }
    $hostnames[$h] = true;
}
if (!$hostnames) { fail_domain('dominio'); }

$added = [];
foreach (array_keys($hostnames) as $hostname) {
    if (AliasRepository::findByHostname($hostname)) { continue; }
    $aliasId = AliasRepository::create([
        'hostname'      => $hostname,
        'origin_id'     => $originId,
        'is_primary'    => AliasRepository::primary() === null,
        'active'        => true,
        'require_token' => false,
    ]);
    Audit::log('domain.create', 'domínio #' . $aliasId . ' ' . $hostname);
    $added[] = $hostname;
}

if (!$added) { fail_domain('duplicado'); }
$_SESSION['flash'] = count($added) === 1
    ? 'Domínio ' . $added[0] . ' protegido com sucesso.'
    : count($added) . ' domínios protegidos com sucesso.';
header('Location: /dashboard.php');
