<?php

declare(strict_types=1);

/**
 * SMOKE do StateStore + contrato LB v1.
 *
 * Roda a MESMA bateria nos dois drivers (sqlite sempre; redis quando houver
 * servidor local) e exige PARIDADE de comportamento. Se um driver divergir do
 * outro, o corte da Fase 2 não pode acontecer.
 *
 * Uso:
 *   php bin/smoke-statestore.php
 *   PROXY_MAGO_REDIS_HOST=127.0.0.1 php bin/smoke-statestore.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/LbContract.php';

$fail = 0;
$ok = 0;

function check(string $name, bool $cond, string $extra = ''): void
{
    global $fail, $ok;
    if ($cond) {
        $ok++;
        echo "  ok   {$name}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$name}" . ($extra !== '' ? " -> {$extra}" : '') . "\n";
}

function redisAvailable(): bool
{
    $host = (string) (getenv('PROXY_MAGO_REDIS_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('PROXY_MAGO_REDIS_PORT') ?: 6379);
    $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $e1, $e2, 0.5);
    if ($sock === false) {
        return false;
    }
    fclose($sock);
    return true;
}

/** Bateria de paridade. Deve passar IGUAL nos dois drivers. */
function battery(string $driver): void
{
    echo "\n[driver={$driver}] efetivo=" . StateStore::driver()
        . ' degradado=' . (StateStore::health()['degraded'] ? '1' : '0') . "\n";

    check("{$driver}: driver efetivo confere", StateStore::driver() === $driver, StateStore::driver());

    StateStore::flushAll();

    // ---- chave/valor
    StateStore::kvSet('smoke:str', 'valor-cru');
    check("{$driver}: kv string", StateStore::kvGet('smoke:str') === 'valor-cru');

    StateStore::kvSet('smoke:arr', ['a' => 1, 'b' => ['c' => true]]);
    $arr = StateStore::kvGet('smoke:arr');
    check("{$driver}: kv array roundtrip", is_array($arr) && $arr['b']['c'] === true);

    check("{$driver}: kv default em chave ausente", StateStore::kvGet('smoke:nao-existe', 'zzz') === 'zzz');

    StateStore::kvDel('smoke:str');
    check("{$driver}: kvDel apaga", StateStore::kvGet('smoke:str') === null);

    StateStore::kvSet('smoke:ttl', 'morre', 1);
    check("{$driver}: ttl vivo antes de expirar", StateStore::kvGet('smoke:ttl') === 'morre');
    sleep(2);
    check("{$driver}: ttl expirou", StateStore::kvGet('smoke:ttl') === null);

    // ---- contador
    StateStore::kvDel('smoke:cnt');
    $a = StateStore::incr('smoke:cnt');
    $b = StateStore::incr('smoke:cnt', 4);
    check("{$driver}: incr sequencial 1 -> 5", $a === 1 && $b === 5, "a={$a} b={$b}");

    // ---- sessão viva + índice por usuário
    $user = 'smoke_user_' . $driver;
    StateStore::sessionTouch('k1', $user, ['kind' => 'live', 'ip' => '10.0.0.1'], 60);
    StateStore::sessionTouch('k2', $user, ['kind' => 'vod', 'ip' => '10.0.0.2'], 60);

    $sess = StateStore::sessionGet('k1');
    check("{$driver}: sessionGet devolve campos", is_array($sess) && ($sess['kind'] ?? '') === 'live');
    check("{$driver}: sessionGet injeta identity", is_array($sess) && ($sess['identity'] ?? '') === $user);

    check("{$driver}: userCount conta 2", StateStore::userCount($user) === 2, (string) StateStore::userCount($user));
    check("{$driver}: userSessions lista ambas", StateStore::userSessions($user) === ['k1', 'k2']);

    StateStore::sessionTouch('k1', $user, ['kind' => 'live'], 60);
    check("{$driver}: touch repetido não duplica", StateStore::userCount($user) === 2);

    StateStore::sessionClose('k2', $user);
    check("{$driver}: close remove do índice", StateStore::userSessions($user) === ['k1']);

    // Sessão que expira sozinha precisa sair do contador SEM job de limpeza.
    StateStore::sessionTouch('k3', $user, ['kind' => 'live'], 1);
    check("{$driver}: sessão nova entra no contador", StateStore::userCount($user) === 2);
    sleep(2);
    check("{$driver}: índice auto-poda sessão expirada", StateStore::userCount($user) === 1, (string) StateStore::userCount($user));

    check("{$driver}: usuário desconhecido = 0", StateStore::userCount('ninguem_' . $driver) === 0);
    check("{$driver}: identity vazia não grava", StateStore::sessionTouch('kx', '', [], 60) === false);

    // ---- presence de LB
    StateStore::presenceSet(77, ['sessions' => 12, 'cpu' => 33.5], 60);
    $presence = StateStore::presenceAll([77, 78]);
    check("{$driver}: presence grava e lê", isset($presence[77]) && (int) $presence[77]['sessions'] === 12);
    check("{$driver}: presence ignora LB sem heartbeat", !isset($presence[78]));
    check("{$driver}: presence carimba lb_id", (int) ($presence[77]['lb_id'] ?? 0) === 77);

    StateStore::flushAll();
    check("{$driver}: flushAll limpa", StateStore::kvGet('smoke:arr') === null && StateStore::userCount($user) === 0);
}

echo "=== SMOKE StateStore + Contrato LB v1 ===\n";

// ---------------------------------------------------------------- driver sqlite
putenv('PROXY_MAGO_STATE_DRIVER=sqlite');
StateStore::reset();
battery('sqlite');

// ----------------------------------------------------------------- driver redis
putenv('PROXY_MAGO_STATE_DRIVER=redis');
StateStore::reset();

if (redisAvailable()) {
    battery('redis');

    // Degradação: Redis inalcançável NÃO pode quebrar o caminho quente.
    echo "\n[degradação] apontando para porta morta\n";
    putenv('PROXY_MAGO_REDIS_PORT=65530');
    StateStore::reset();
    $health = StateStore::health();
    check('degradação: driver efetivo cai para sqlite', $health['driver'] === 'sqlite');
    check('degradação: marca modo degradado', $health['degraded'] === true);
    check('degradação: motivo preenchido', $health['reason'] !== '');
    StateStore::kvSet('smoke:degradado', 'ainda-funciona');
    check('degradação: escrita continua funcionando', StateStore::kvGet('smoke:degradado') === 'ainda-funciona');
    StateStore::kvDel('smoke:degradado');
    putenv('PROXY_MAGO_REDIS_PORT');
} else {
    echo "\n[skip] sem Redis local: bateria do driver redis pulada\n";
    $health = StateStore::health();
    check('sem redis: cai para sqlite sem estourar', $health['driver'] === 'sqlite' && $health['degraded'] === true);
}

// -------------------------------------------------------------- contrato LB v1
putenv('PROXY_MAGO_STATE_DRIVER=sqlite');
StateStore::reset();

echo "\n[contrato] snapshot + eventos\n";

$snapshot = LbContract::snapshot(['id' => 999, 'label' => 'smoke-lb', 'public_ip' => '203.0.113.9', 'enabled' => 1, 'drain' => 0]);

check('contrato: identifica o contrato', ($snapshot['contract'] ?? '') === 'cdnvoods.lb');
check('contrato: versão presente', ($snapshot['contract_version'] ?? '') === LbContract::VERSION);
check('contrato: bloco de estado', isset($snapshot['state']['driver'], $snapshot['state']['namespace']));
check('contrato: senha do redis NÃO viaja', !array_key_exists('password', (array) $snapshot['state']['redis']));
check('contrato: bloco de runtime completo', isset(
    $snapshot['runtime']['sessions_enabled'],
    $snapshot['runtime']['enforce_ip_lock'],
    $snapshot['runtime']['enforce_connection_limit']
));
check('contrato: origens é lista', is_array($snapshot['origins']));
check('contrato: usuários é lista', is_array($snapshot['users']));
check('contrato: rotas do cérebro', ($snapshot['brain']['events_url'] ?? '') === '/lb-events.php');
check('contrato: json serializável', json_encode($snapshot) !== false);

check('contrato: major igual é compatível', LbContract::versionCompatible('1.7'));
check('contrato: major diferente é incompatível', !LbContract::versionCompatible('2.0'));

$res = LbContract::applyEvents(0, [
    ['type' => 'nao_existe'],
    'nem-objeto',
]);
check('contrato: rejeita evento inválido', $res['rejected'] === 2 && $res['accepted'] === 0, json_encode($res));

$res = LbContract::applyEvents(0, [[
    'type' => 'heartbeat',
    'cpu_pct' => 12.5,
    'sessions_active' => 3,
]]);
check('contrato: aceita heartbeat', $res['accepted'] === 1 && $res['rejected'] === 0, json_encode($res));

echo "\n=== RESULTADO: {$ok} ok / {$fail} falhas ===\n";
exit($fail === 0 ? 0 : 1);