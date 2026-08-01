<?php

declare(strict_types=1);

/**
 * ENSAIO / CORTE do estado vivo para Redis (Fase 2).
 *
 * O corte é uma troca de chave em settings (`state_driver`), mas trocar sem
 * provar paridade é como reiniciar o Nginx sem `nginx -t`. Este script faz o
 * ensaio completo antes de mudar nada:
 *
 *   php bin/redis-cut.php              # só ensaio (dry-run), não muda settings
 *   php bin/redis-cut.php --apply      # ensaio + corte definitivo
 *   php bin/redis-cut.php --rollback   # volta o estado vivo para sqlite
 *
 * Coordenadas vêm de settings/config, ou do ambiente para ensaiar sem tocar em
 * produção: PROXY_MAGO_REDIS_HOST / _PORT / _PASS / _DB.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$rollback = in_array('--rollback', $args, true);

function line(string $msg): void
{
    echo $msg . "\n";
}

if ($rollback) {
    SettingsRepository::set('state_driver', 'sqlite');
    StateStore::reset();
    Audit::log('redis_cut', 'rollback: state_driver=sqlite');
    line('[ok] estado vivo voltou para sqlite (driver efetivo: ' . StateStore::driver() . ')');
    exit(0);
}

$host = (string) (getenv('PROXY_MAGO_REDIS_HOST') ?: SettingsRepository::get('redis_host', (string) Config::get('redis_host', '127.0.0.1')));
$port = (int) (getenv('PROXY_MAGO_REDIS_PORT') ?: SettingsRepository::get('redis_port', (string) Config::get('redis_port', 6379)));
$pass = (string) (getenv('PROXY_MAGO_REDIS_PASS') ?: SettingsRepository::get('redis_pass', (string) Config::get('redis_pass', '')));
$db = (int) (getenv('PROXY_MAGO_REDIS_DB') ?: SettingsRepository::get('redis_db', (string) Config::get('redis_db', 0)));

line('== ensaio de corte do estado vivo ==');
line('redis: ' . $host . ':' . $port . ' db=' . $db . ' senha=' . ($pass !== '' ? 'sim' : 'não'));

$fail = 0;
$step = static function (string $name, callable $fn) use (&$fail): void {
    try {
        $detail = (string) $fn();
        line('  ok   ' . $name . ($detail !== '' ? ' -> ' . $detail : ''));
    } catch (Throwable $e) {
        $fail++;
        line('  FAIL ' . $name . ' -> ' . $e->getMessage());
    }
};

$client = new RedisClient($host, $port, $pass, $db, 1.0, false);

$step('PING responde', static function () use ($client): string {
    $r = $client->command(['PING']);
    if ($r !== 'PONG') {
        throw new RuntimeException('resposta inesperada: ' . var_export($r, true));
    }
    return 'PONG';
});

$step('escrita/leitura/expiração', static function () use ($client): string {
    $key = StateStore::NS . 'cut:' . bin2hex(random_bytes(4));
    $client->command(['SETEX', $key, '5', 'valor']);
    $got = $client->command(['GET', $key]);
    $ttl = (int) $client->command(['TTL', $key]);
    $client->command(['DEL', $key]);
    if ($got !== 'valor' || $ttl < 1) {
        throw new RuntimeException('got=' . var_export($got, true) . ' ttl=' . $ttl);
    }
    return 'ttl=' . $ttl . 's';
});

$step('pipeline (1 round-trip)', static function () use ($client): string {
    $key = StateStore::NS . 'cut:pipe:' . bin2hex(random_bytes(4));
    $res = $client->pipeline([
        ['INCR', $key], ['INCR', $key], ['EXPIRE', $key, '5'], ['GET', $key], ['DEL', $key],
    ]);
    if ((string) $res[3] !== '2') {
        throw new RuntimeException('contador divergente: ' . json_encode($res));
    }
    return 'contador=2';
});

$step('política de memória não descarta estado vivo', static function () use ($client): string {
    $conf = $client->command(['CONFIG', 'GET', 'maxmemory-policy']);
    $policy = is_array($conf) && isset($conf[1]) ? (string) $conf[1] : 'desconhecida';
    if (str_starts_with($policy, 'allkeys')) {
        throw new RuntimeException('maxmemory-policy=' . $policy . ' apagaria sessão sob pressão; use volatile-lru ou noeviction');
    }
    return $policy;
});

$step('paridade de contadores via StateStore', static function (): string {
    $identity = 'cut_' . bin2hex(random_bytes(3));
    $out = [];
    foreach (StateStore::DRIVERS as $driver) {
        SettingsRepository::set('state_driver', $driver);
        StateStore::reset();
        $key = 'cut:' . $driver . ':' . $identity;
        StateStore::sessionTouch($key, $identity, ['kind' => 'live'], 30);
        $out[$driver] = StateStore::userCount($identity);
        StateStore::sessionClose($key, $identity, 'cut_test');
        $out[$driver . '_after'] = StateStore::userCount($identity);
    }
    if ($out['sqlite'] !== $out['redis'] || $out['sqlite_after'] !== $out['redis_after']) {
        throw new RuntimeException('divergência: ' . json_encode($out));
    }
    return 'aberta=' . $out['sqlite'] . ' fechada=' . $out['sqlite_after'];
});

$client->close();

// O ensaio mexeu em state_driver para comparar; devolve o valor original agora.
SettingsRepository::set('state_driver', 'sqlite');
StateStore::reset();

if ($fail > 0) {
    line("\n[FAIL] " . $fail . ' verificação(ões) falharam — corte NÃO aplicado. Estado vivo segue em sqlite.');
    exit(1);
}

if (!$apply) {
    line("\n[ok] ensaio limpo. Nada foi alterado. Para cortar de verdade: php bin/redis-cut.php --apply");
    exit(0);
}

SettingsRepository::set('redis_host', $host);
SettingsRepository::set('redis_port', $port);
SettingsRepository::set('redis_db', $db);
if ($pass !== '') {
    SettingsRepository::set('redis_pass', $pass);
}
SettingsRepository::set('state_driver', 'redis');
StateStore::reset();

$health = StateStore::health();
if ($health['degraded']) {
    SettingsRepository::set('state_driver', 'sqlite');
    StateStore::reset();
    line("\n[FAIL] Redis degradou logo após o corte (" . $health['reason'] . '); voltei para sqlite.');
    exit(1);
}

Audit::log('redis_cut', 'apply: state_driver=redis host=' . $host . ':' . $port . ' db=' . $db);
line("\n[ok] CORTE APLICADO. Estado vivo agora é " . $health['driver'] . '.');
line('     Rollback imediato: php bin/redis-cut.php --rollback');
exit(0);