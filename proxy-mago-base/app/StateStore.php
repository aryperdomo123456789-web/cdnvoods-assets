<?php

declare(strict_types=1);

/**
 * FASE 2 — camada de ESTADO VIVO, independente do SQLite.
 *
 * Problema que ela resolve: sessão ativa, heartbeat, contador por usuário,
 * presence de LB e cache de trava por IP são escritas de ALTA FREQUÊNCIA. Em
 * SQLite isso vira `database is locked` sob carga real (ver
 * docs/RUNTIME_LOCK_SQLITE_2026-07-31.md). Aqui esse estado passa a viver
 * atrás de uma interface com DOIS drivers:
 *
 *   - `sqlite` (padrão, comportamento de hoje, zero dependência nova)
 *   - `redis`  (destino oficial da Fase 2)
 *
 * Chave de corte: `state_driver` em settings (ou `PROXY_MAGO_STATE_DRIVER` no
 * ambiente, ou `state_driver` no config). Trocar de driver NÃO exige deploy.
 *
 * Regra inegociável do caminho quente: Redis fora do ar não derruba player.
 * Qualquer falha do Redis cai automaticamente para o driver `sqlite` e marca
 * MODO DEGRADADO explícito (`StateStore::health()`), igual ao padrão já usado
 * em app/Freshness.php.
 *
 * O LAYOUT DE CHAVES É CONTRATO: o motor Go do LB (lb-go/internal/state) lê e
 * escreve exatamente as mesmas chaves. Não renomear sem versionar o contrato
 * (docs/CONTRATO_LB_V1.md).
 */
final class StateStore
{
    public const DRIVERS = ['sqlite', 'redis'];

    /** Prefixo global — mesmo usado pelo motor Go. */
    public const NS = 'cdnv:';

    /** TTL do índice de sessões por usuário (o conjunto é auto-podado). */
    private const USER_SET_TTL = 86400;

    private static ?string $driver = null;
    private static ?RedisClient $redis = null;
    private static bool $redisDown = false;
    private static string $lastError = '';
    private static bool $schemaReady = false;

    // ---------------------------------------------------------------- driver

    /** Driver PEDIDO na configuração (pode não ser o efetivo). */
    public static function configured(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        $env = (string) (getenv('PROXY_MAGO_STATE_DRIVER') ?: '');
        $value = $env !== '' ? $env : (string) SettingsRepository::get(
            'state_driver',
            (string) Config::get('state_driver', 'sqlite')
        );
        $value = strtolower(trim($value));

        self::$driver = in_array($value, self::DRIVERS, true) ? $value : 'sqlite';
        return self::$driver;
    }

    /** Driver EFETIVO agora (cai para sqlite se o Redis estiver fora). */
    public static function driver(): string
    {
        if (self::configured() !== 'redis') {
            return 'sqlite';
        }
        return self::redis() !== null ? 'redis' : 'sqlite';
    }

    /** @return array{driver:string,configured:string,degraded:bool,reason:string} */
    public static function health(): array
    {
        $configured = self::configured();
        $effective = self::driver();

        return [
            'driver' => $effective,
            'configured' => $configured,
            'degraded' => $configured !== $effective,
            'reason' => $configured !== $effective ? (self::$lastError ?: 'redis_unavailable') : '',
        ];
    }

    /** Reset de estado interno — usado pelos smokes ao trocar de driver. */
    public static function reset(): void
    {
        if (self::$redis !== null) {
            self::$redis->close();
        }
        self::$driver = null;
        self::$redis = null;
        self::$redisDown = false;
        self::$lastError = '';
    }

    private static function redis(): ?RedisClient
    {
        if (self::$redisDown) {
            return null;
        }
        if (self::$redis !== null) {
            return self::$redis;
        }

        try {
            $client = new RedisClient(
                (string) self::conf('redis_host', '127.0.0.1'),
                (int) self::conf('redis_port', 6379),
                (string) self::conf('redis_pass', ''),
                (int) self::conf('redis_db', 0),
                (float) self::conf('redis_timeout', 1.0)
            );
            $pong = $client->command(['PING']);
            if (!is_string($pong) || strtoupper($pong) !== 'PONG') {
                throw new RedisClientException('PING sem PONG');
            }
            self::$redis = $client;
            return $client;
        } catch (Throwable $e) {
            self::$redisDown = true;
            self::$lastError = $e->getMessage();
            error_log('[statestore] redis fora, caindo para sqlite: ' . $e->getMessage());
            return null;
        }
    }

    private static function conf(string $key, mixed $default): mixed
    {
        $env = getenv('PROXY_MAGO_' . strtoupper($key));
        if ($env !== false && $env !== '') {
            return $env;
        }
        $fromSettings = SettingsRepository::get($key, null);
        if ($fromSettings !== null && $fromSettings !== '') {
            return $fromSettings;
        }
        return Config::get($key, $default);
    }

    /** Marca o Redis como fora e devolve null para o chamador cair no sqlite. */
    private static function demote(Throwable $e): null
    {
        self::$redisDown = true;
        self::$lastError = $e->getMessage();
        if (self::$redis !== null) {
            self::$redis->close();
            self::$redis = null;
        }
        error_log('[statestore] degradado para sqlite: ' . $e->getMessage());
        return null;
    }

    // ------------------------------------------------------------ chave/valor

    public static function kvSet(string $key, mixed $value, int $ttl = 0): bool
    {
        $raw = self::encode($value);
        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $args = ['SET', self::NS . $key, $raw];
                if ($ttl > 0) {
                    $args[] = 'EX';
                    $args[] = (string) $ttl;
                }
                $client->command($args);
                return true;
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        $exp = $ttl > 0 ? time() + $ttl : 0;

        return Database::write(static function (PDO $pdo) use ($key, $raw, $exp): void {
            $pdo->prepare(
                'INSERT INTO state_kv (k, v, exp, updated_epoch) VALUES (:k,:v,:e,:u)
                 ON CONFLICT(k) DO UPDATE SET v=excluded.v, exp=excluded.exp, updated_epoch=excluded.updated_epoch'
            )->execute([':k' => $key, ':v' => $raw, ':e' => $exp, ':u' => time()]);
        }, 'statestore.kvset');
    }

    public static function kvGet(string $key, mixed $default = null): mixed
    {
        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $raw = $client->command(['GET', self::NS . $key]);
                return $raw === null ? $default : self::decode((string) $raw);
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        $stmt = Database::pdo()->prepare(
            'SELECT v FROM state_kv WHERE k = :k AND (exp = 0 OR exp > :now) LIMIT 1'
        );
        $stmt->execute([':k' => $key, ':now' => time()]);
        $row = $stmt->fetch();

        return $row === false ? $default : self::decode((string) $row['v']);
    }

    public static function kvDel(string $key): bool
    {
        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $client->command(['DEL', self::NS . $key]);
                return true;
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        return Database::write(static function (PDO $pdo) use ($key): void {
            $pdo->prepare('DELETE FROM state_kv WHERE k = :k')->execute([':k' => $key]);
        }, 'statestore.kvdel');
    }

    /** Contador atômico (limite de conexão, erros por janela, rate limit). */
    public static function incr(string $key, int $by = 1, int $ttl = 0): int
    {
        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $value = (int) $client->command(['INCRBY', self::NS . $key, (string) $by]);
                if ($ttl > 0 && $value === $by) {
                    $client->command(['EXPIRE', self::NS . $key, (string) $ttl]);
                }
                return $value;
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        $exp = $ttl > 0 ? time() + $ttl : 0;
        $value = 0;

        Database::write(static function (PDO $pdo) use ($key, $by, $exp, &$value): void {
            $pdo->prepare('DELETE FROM state_kv WHERE k = :k AND exp <> 0 AND exp <= :now')
                ->execute([':k' => $key, ':now' => time()]);
            $pdo->prepare(
                'INSERT INTO state_kv (k, v, exp, updated_epoch) VALUES (:k,:v,:e,:u)
                 ON CONFLICT(k) DO UPDATE SET
                    v = CAST((CAST(state_kv.v AS INTEGER) + :b) AS TEXT),
                    updated_epoch = excluded.updated_epoch'
            )->execute([':k' => $key, ':v' => (string) $by, ':e' => $exp, ':u' => time(), ':b' => $by]);

            $stmt = $pdo->prepare('SELECT v FROM state_kv WHERE k = :k LIMIT 1');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch();
            $value = $row === false ? 0 : (int) $row['v'];
        }, 'statestore.incr');

        return $value;
    }

    // ------------------------------------------------------------- sessão viva

    /**
     * Abre/renova a sessão viva e indexa por usuário.
     *
     * @param array<string,mixed> $fields
     */
    public static function sessionTouch(string $sessionKey, string $identity, array $fields, int $ttl): bool
    {
        if ($sessionKey === '' || $identity === '') {
            return false;
        }
        $ttl = max(1, $ttl);
        $fields['session_key'] = $sessionKey;
        $fields['identity'] = $identity;
        $fields['last_seen_epoch'] = (int) ($fields['last_seen_epoch'] ?? time());

        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $client->pipeline([
                    ['SET', self::NS . 'sess:' . $sessionKey, self::encode($fields), 'EX', (string) $ttl],
                    ['SADD', self::NS . 'user:' . $identity, $sessionKey],
                    ['EXPIRE', self::NS . 'user:' . $identity, (string) self::USER_SET_TTL],
                ]);
                return true;
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        $exp = time() + $ttl;
        $raw = self::encode($fields);

        return Database::write(static function (PDO $pdo) use ($sessionKey, $identity, $raw, $exp): void {
            $pdo->prepare(
                'INSERT INTO state_kv (k, v, exp, updated_epoch) VALUES (:k,:v,:e,:u)
                 ON CONFLICT(k) DO UPDATE SET v=excluded.v, exp=excluded.exp, updated_epoch=excluded.updated_epoch'
            )->execute([':k' => 'sess:' . $sessionKey, ':v' => $raw, ':e' => $exp, ':u' => time()]);

            $pdo->prepare(
                'INSERT INTO state_members (ns, member, exp) VALUES (:ns,:m,:e)
                 ON CONFLICT(ns, member) DO UPDATE SET exp=excluded.exp'
            )->execute([
                ':ns' => 'user:' . $identity,
                ':m' => $sessionKey,
                ':e' => time() + self::USER_SET_TTL,
            ]);
        }, 'statestore.sesstouch');
    }

    /** @return array<string,mixed>|null */
    public static function sessionGet(string $sessionKey): ?array
    {
        $value = self::kvGet('sess:' . $sessionKey);
        return is_array($value) ? $value : null;
    }

    public static function sessionClose(string $sessionKey, string $identity = '', string $reason = 'closed'): bool
    {
        if ($sessionKey === '') {
            return false;
        }

        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $cmds = [['DEL', self::NS . 'sess:' . $sessionKey]];
                if ($identity !== '') {
                    $cmds[] = ['SREM', self::NS . 'user:' . $identity, $sessionKey];
                }
                $client->pipeline($cmds);
                return true;
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        return Database::write(static function (PDO $pdo) use ($sessionKey, $identity): void {
            $pdo->prepare('DELETE FROM state_kv WHERE k = :k')->execute([':k' => 'sess:' . $sessionKey]);
            if ($identity !== '') {
                $pdo->prepare('DELETE FROM state_members WHERE ns = :ns AND member = :m')
                    ->execute([':ns' => 'user:' . $identity, ':m' => $sessionKey]);
            }
        }, 'statestore.sessclose');
    }

    /**
     * Sessões VIVAS do usuário. O índice é auto-podado: membro cuja sessão
     * expirou sai do conjunto na hora da leitura (sem job de limpeza).
     *
     * @return string[]
     */
    public static function userSessions(string $identity): array
    {
        if ($identity === '') {
            return [];
        }

        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $members = $client->command(['SMEMBERS', self::NS . 'user:' . $identity]);
                if (!is_array($members) || $members === []) {
                    return [];
                }
                $checks = [];
                foreach ($members as $m) {
                    $checks[] = ['EXISTS', self::NS . 'sess:' . (string) $m];
                }
                $exists = $client->pipeline($checks);

                $live = [];
                $dead = [];
                foreach ($members as $i => $m) {
                    if ((int) ($exists[$i] ?? 0) === 1) {
                        $live[] = (string) $m;
                    } else {
                        $dead[] = (string) $m;
                    }
                }
                if ($dead !== []) {
                    $client->command(array_merge(['SREM', self::NS . 'user:' . $identity], $dead));
                }
                sort($live);
                return $live;
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        $now = time();
        $stmt = Database::pdo()->prepare(
            'SELECT m.member AS member
               FROM state_members m
               JOIN state_kv kv ON kv.k = \'sess:\' || m.member
              WHERE m.ns = :ns
                AND (m.exp = 0 OR m.exp > :now)
                AND (kv.exp = 0 OR kv.exp > :now2)
              ORDER BY m.member ASC'
        );
        $stmt->execute([':ns' => 'user:' . $identity, ':now' => $now, ':now2' => $now]);

        $live = [];
        foreach ($stmt->fetchAll() as $row) {
            $live[] = (string) $row['member'];
        }

        Database::write(static function (PDO $pdo) use ($identity, $now): void {
            $pdo->prepare(
                'DELETE FROM state_members
                  WHERE ns = :ns
                    AND NOT EXISTS (
                        SELECT 1 FROM state_kv kv
                         WHERE kv.k = \'sess:\' || state_members.member
                           AND (kv.exp = 0 OR kv.exp > :now)
                    )'
            )->execute([':ns' => 'user:' . $identity, ':now' => $now]);
        }, 'statestore.prune', 2);

        return $live;
    }

    /** Conexões vivas do usuário — base do enforcement de limite. */
    public static function userCount(string $identity): int
    {
        return count(self::userSessions($identity));
    }

    // ------------------------------------------------------------- presence LB

    /** @param array<string,mixed> $payload */
    public static function presenceSet(int $lbId, array $payload, int $ttl = 45): bool
    {
        $payload['lb_id'] = $lbId;
        $payload['epoch'] = time();
        return self::kvSet('lb:' . $lbId, $payload, $ttl);
    }

    /** @return array<int,array<string,mixed>> */
    public static function presenceAll(array $lbIds): array
    {
        $out = [];
        foreach ($lbIds as $id) {
            $value = self::kvGet('lb:' . (int) $id);
            if (is_array($value)) {
                $out[(int) $id] = $value;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ util

    /** Limpa TODO o namespace de estado vivo. Só para smoke/manutenção. */
    public static function flushAll(): void
    {
        $client = self::driver() === 'redis' ? self::redis() : null;

        if ($client !== null) {
            try {
                $cursor = '0';
                do {
                    $res = $client->command(['SCAN', $cursor, 'MATCH', self::NS . '*', 'COUNT', '500']);
                    $cursor = (string) ($res[0] ?? '0');
                    $keys = is_array($res[1] ?? null) ? $res[1] : [];
                    if ($keys !== []) {
                        $client->command(array_merge(['DEL'], array_map('strval', $keys)));
                    }
                } while ($cursor !== '0');
            } catch (Throwable $e) {
                self::demote($e);
            }
        }

        self::ensureSchema();
        Database::write(static function (PDO $pdo): void {
            $pdo->exec('DELETE FROM state_kv');
            $pdo->exec('DELETE FROM state_members');
        }, 'statestore.flush', 2);
    }

    private static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(string $raw): mixed
    {
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /**
     * O driver sqlite guarda o estado vivo em duas tabelas próprias, criadas
     * sob demanda para não exigir janela de migração só por causa da Fase 2.
     */
    private static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $pdo = Database::pdo();
        $autoinc = Database::isPgsql() ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        unset($autoinc); // schema é sem id: PK natural nas duas pontas

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS state_kv (
                k TEXT PRIMARY KEY,
                v TEXT NOT NULL DEFAULT \'\',
                exp INTEGER NOT NULL DEFAULT 0,
                updated_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_state_kv_exp ON state_kv (exp)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS state_members (
                ns TEXT NOT NULL,
                member TEXT NOT NULL,
                exp INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (ns, member)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_state_members_exp ON state_members (exp)');

        self::$schemaReady = true;
    }
}