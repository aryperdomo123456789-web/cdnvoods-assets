<?php

/**
 * Salva os controles de ESCALA (Fase 2): estado vivo compartilhado (Redis) e
 * cérebro puro (entrega obrigatória pelo músculo).
 *
 * Ficou fora de /save.php de propósito: essas chaves mudam o comportamento do
 * caminho quente e precisam de auditoria própria, com o valor anterior gravado.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /avancado.php#escala');
    exit;
}

csrf_verify();

$stateDriver = (string) ($_POST['state_driver'] ?? 'sqlite');
if (!in_array($stateDriver, StateStore::DRIVERS, true)) {
    http_response_code(422);
    exit('Driver de estado inválido.');
}

$defaultMode = (string) ($_POST['lb_default_mode'] ?? 'main_only');
if (!in_array($defaultMode, LbRouter::MODES, true)) {
    http_response_code(422);
    exit('Modo padrão de rota inválido.');
}

$redisHost = trim((string) ($_POST['redis_host'] ?? '127.0.0.1'));
$redisPort = (int) ($_POST['redis_port'] ?? 6379);
$redisDb = (int) ($_POST['redis_db'] ?? 0);
$redisPass = (string) ($_POST['redis_pass'] ?? '');
$requireDelivery = isset($_POST['lb_require_delivery']) ? 1 : 0;

if ($redisHost === '' || $redisPort < 1 || $redisPort > 65535 || $redisDb < 0 || $redisDb > 15) {
    http_response_code(422);
    exit('Coordenadas do Redis inválidas.');
}

$before = [
    'state_driver' => StateStore::configured(),
    'lb_require_delivery' => LbRouter::requireDelivery() ? 1 : 0,
    'lb_default_mode' => LbRouter::defaultMode(),
];

SettingsRepository::set('redis_host', $redisHost);
SettingsRepository::set('redis_port', $redisPort);
SettingsRepository::set('redis_db', $redisDb);
if ($redisPass !== '') {
    SettingsRepository::set('redis_pass', $redisPass);
}
SettingsRepository::set('lb_default_mode', $defaultMode);

// Trava de segurança: cérebro puro sem NENHUM músculo apto derruba o serviço
// inteiro (503 para todo player). Só aceita ligar quando existe LB saudável.
$flash = '';
if ($requireDelivery === 1) {
    $totals = LbRouter::totals();
    if ((int) $totals['installed'] < 1 || (int) $totals['healthy'] < 1) {
        $requireDelivery = 0;
        $flash = 'Entrega obrigatória pelo LB foi recusada: nenhum LB instalado e saudável agora. ';
    }
}
SettingsRepository::set('lb_require_delivery', $requireDelivery);

// Troca de driver: valida ANTES de deixar o valor salvo mandar no runtime.
SettingsRepository::set('state_driver', $stateDriver);
StateStore::reset();
$health = StateStore::health();
if ($stateDriver === 'redis' && $health['degraded']) {
    SettingsRepository::set('state_driver', 'sqlite');
    StateStore::reset();
    $flash .= 'Redis não respondeu (' . $health['reason'] . '); estado vivo mantido em SQLite. ';
} else {
    $flash .= 'Estado vivo: ' . $health['driver'] . '. ';
}

Audit::log(
    'scale_settings_update',
    sprintf(
        'state_driver=%s (antes %s) lb_require_delivery=%d (antes %d) lb_default_mode=%s (antes %s)',
        StateStore::configured(), $before['state_driver'],
        $requireDelivery, $before['lb_require_delivery'],
        $defaultMode, $before['lb_default_mode']
    ),
    $_SERVER['REMOTE_ADDR'] ?? '-',
    $_SERVER['HTTP_USER_AGENT'] ?? '-'
);

$_SESSION['flash'] = trim($flash . 'Configuração de escala salva.');
header('Location: /avancado.php#escala');
exit;
