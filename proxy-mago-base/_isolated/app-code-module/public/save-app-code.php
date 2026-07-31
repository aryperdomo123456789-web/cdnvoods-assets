<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

function ac_fail(string $code): void { header('Location: /app-code.php?err=' . $code); exit; }
function ac_done(string $msg): void { $_SESSION['flash'] = $msg; header('Location: /app-code.php'); exit; }

$domainRe = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';
$action   = (string) ($_POST['action'] ?? '');

if ($action === 'config') {
    $hosts = [];
    foreach (preg_split('/[\s,;]+/', (string) ($_POST['hosts'] ?? '')) as $h) {
        $h = strtolower(trim($h));
        if ($h === '') { continue; }
        if (!preg_match($domainRe, $h)) { ac_fail('app_host'); }
        $hosts[] = $h;
    }
    AppCode::setEnabled(!empty($_POST['enabled']));
    AppCode::setFallbackToDefault(!empty($_POST['fallback']));
    AppCode::setHosts($hosts);

    // Cada DNS de app também precisa existir como domínio protegido, senão o
    // AccessGuard rejeita o host antes de chegar no roteador multi-XUI.
    $originId = XuiOrigin::id();
    $novos = 0;
    if ($originId !== null) {
        foreach ($hosts as $h) {
            if (AliasRepository::findByHostname($h) === null) {
                AliasRepository::create([
                    'hostname'      => $h,
                    'origin_id'     => $originId,
                    'is_primary'    => false,
                    'active'        => true,
                    'require_token' => false,
                ]);
                $novos++;
            }
        }
    }

    Audit::log('appcode.config', sprintf('enabled=%d hosts=%d novos_alias=%d',
        AppCode::enabled() ? 1 : 0, count($hosts), $novos));
    ac_done($originId === null
        ? 'Configuração salva, mas cadastre a origem padrão do XUI para liberar o proxy.'
        : 'Configuração salva. ' . $novos . ' domínio(s) de app registrados no proxy.');
}

if ($action === 'server') {
    $id     = (int) ($_POST['id'] ?? 0);
    $host   = strtolower(trim((string) ($_POST['host'] ?? '')));
    $port   = (int) ($_POST['port'] ?? 0);
    $scheme = ($_POST['scheme'] ?? 'http') === 'https' ? 'https' : 'http';

    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    if ($host === '' || (!$isIp && !preg_match($domainRe, $host))) { ac_fail('host'); }
    if ($port < 0 || $port > 65535) { ac_fail('porta'); }

    $data = [
        'name'        => trim((string) ($_POST['name'] ?? '')) ?: $host,
        'host'        => $host,
        'port'        => $port,
        'scheme'      => $scheme,
        'host_header' => strtolower(trim((string) ($_POST['host_header'] ?? ''))),
        'extra_hosts' => strtolower(trim((string) ($_POST['extra_hosts'] ?? ''))),
        'priority'    => max(1, min(999, (int) ($_POST['priority'] ?? 100))),
        'active'      => !empty($_POST['active']),
    ];

    $data['id'] = $id;
    $novo = AppCode::saveServer($data);
    Audit::log($id > 0 ? 'appcode.server.update' : 'appcode.server.create', 'servidor #' . $novo . ' ' . $host);
    ac_done($id > 0 ? 'Servidor XUI atualizado.' : 'Servidor XUI adicionado. Ele já entra na descoberta.');
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    $soltos = AppCode::deleteServer($id);
    Audit::log('appcode.server.delete', 'servidor #' . $id . ' rotas_soltas=' . $soltos);
    ac_done('Servidor removido. ' . $soltos . ' usuário(s) serão redescobertos no próximo acesso.');
}

if ($action === 'unpin') {
    $u = trim((string) ($_POST['username'] ?? ''));
    if ($u !== '') {
        AppCode::unpin($u);
        Audit::log('appcode.unpin', 'user=' . $u);
    }
    ac_done('Usuário desgrudado. O XUI dono será redescoberto no próximo login.');
}

if ($action === 'test') {
    $id = (int) ($_POST['id'] ?? 0);
    $server = AppCode::server($id);
    if (!$server) { ac_fail('host'); }
    $r = AppCodeRouter::probeServer($server);
    Audit::log('appcode.server.test', 'servidor #' . $id . ' ok=' . ($r['ok'] ? 1 : 0));
    ac_done($r['ok']
        ? sprintf('Servidor respondeu na porta %d em %dms.', (int) $r['port'], (int) $r['ms'])
        : 'Servidor não respondeu: ' . $r['reason']);
}

header('Location: /app-code.php');
