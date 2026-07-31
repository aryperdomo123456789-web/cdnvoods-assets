<?php

/**
 * AppCode — "Código de App" multi-XUI.
 *
 * PROBLEMA QUE ESTE MÓDULO RESOLVE
 * --------------------------------
 * Um app (ex.: Assist+) tem UM DNS fixo compilado dentro dele
 * (ex.: assistservpd.phpd77.com). Esse DNS aponta para esta CDN.
 * Só que os assinantes vivem em VÁRIOS XUIs diferentes.
 *
 * A CDN precisa então descobrir, POR USERNAME, em qual XUI aquele assinante
 * existe, e a partir daí GRUDAR aquele username naquele XUI para sempre.
 * É essa "grudação" (sticky route) que impede o embaralhamento de usuários
 * e de listas de reprodução: um username nunca é servido por dois XUIs.
 *
 * REGRAS DE PESO (a CDN não pode ficar pesada)
 * --------------------------------------------
 *  - Descoberta (probe HTTP) SÓ acontece em rota textual (player_api/get.php).
 *    Segmento .ts jamais dispara probe.
 *  - Cache hit é UM SELECT por índice único. Nada mais.
 *  - Escrita de estatística no cache é throttled (1x por HIT_THROTTLE s).
 *  - Falha de descoberta entra em cache negativo, então usuário inexistente
 *    não varre os servidores a cada request.
 *  - Lock de descoberta por username evita estouro (10 players do mesmo
 *    usuário abrindo junto = 1 descoberta só).
 */
final class AppCode
{
    /** Cache negativo: quanto tempo um username "não encontrado" fica em silêncio. */
    private const NEGATIVE_TTL = 300;

    /** Lock de descoberta concorrente por username. */
    private const LOCK_TTL = 20;

    /** Só regrava contadores do cache a cada X segundos. */
    public const HIT_THROTTLE = 60;

    /** Portas testadas quando o servidor não declara porta explícita. */
    public const DEFAULT_PORTS = [80, 8080, 2082, 2086, 2095, 8000, 25461, 443, 8443, 2083, 2096];

    // ---------------------------------------------------------------- settings

    public static function enabled(): bool
    {
        return (int) SettingsRepository::get('app_code_enabled', 0) === 1;
    }

    public static function setEnabled(bool $on): void
    {
        SettingsRepository::set('app_code_enabled', $on ? 1 : 0);
    }

    /** Hostnames do app (o DNS que está compilado dentro do APK). */
    public static function hosts(): array
    {
        $raw = (string) SettingsRepository::get('app_code_hosts', '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw)) as $h) {
            $h = trim($h);
            if ($h !== '') { $out[$h] = true; }
        }
        return array_keys($out);
    }

    public static function setHosts(array $hosts): void
    {
        $clean = [];
        foreach ($hosts as $h) {
            $h = strtolower(trim($h));
            $h = preg_replace('#^https?://#', '', $h);
            $h = preg_replace('#[/:].*$#', '', (string) $h);
            if ($h !== '') { $clean[$h] = true; }
        }
        SettingsRepository::set('app_code_hosts', implode("\n", array_keys($clean)));
    }

    /** O host público atual pertence ao código de app? */
    public static function isAppHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') { return false; }
        // Memoizado por request: proxy.php chama isso no caminho quente.
        static $set = null;
        if ($set === null) { $set = array_flip(self::hosts()); }
        return isset($set[$host]);
    }

    /**
     * Fallback: quando nenhum XUI reconhece o usuário, servimos a origem
     * padrão do painel (comportamento antigo) em vez de derrubar o player.
     */
    public static function fallbackToDefault(): bool
    {
        return (int) SettingsRepository::get('app_code_fallback_default', 1) === 1;
    }

    public static function setFallbackToDefault(bool $on): void
    {
        SettingsRepository::set('app_code_fallback_default', $on ? 1 : 0);
    }

    // ---------------------------------------------------------------- servers

    /** @return array<int,array<string,mixed>> */
    public static function servers(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM app_servers';
        if ($onlyActive) { $sql .= ' WHERE active = 1'; }
        $sql .= ' ORDER BY priority ASC, id ASC';
        return Database::pdo()->query($sql)->fetchAll();
    }

    public static function server(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM app_servers WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        return $st->fetch() ?: null;
    }

    public static function saveServer(array $d): int
    {
        $now = date('c');
        $host = strtolower(trim((string) ($d['host'] ?? '')));
        $host = preg_replace('#^https?://#', '', $host);
        $port = (int) ($d['port'] ?? 0);
        if (str_contains($host, ':')) {
            [$host, $p] = explode(':', $host, 2);
            if ($port <= 0) { $port = (int) $p; }
        }
        $host = preg_replace('#[/?].*$#', '', (string) $host);

        $row = [
            ':name'        => trim((string) ($d['name'] ?? $host)),
            ':host'        => $host,
            ':port'        => ($port >= 1 && $port <= 65535) ? $port : 0,
            ':scheme'      => in_array($d['scheme'] ?? 'http', ['http', 'https'], true) ? $d['scheme'] : 'http',
            ':host_header' => strtolower(trim((string) ($d['host_header'] ?? ''))),
            ':extra_hosts' => strtolower(trim((string) ($d['extra_hosts'] ?? ''))),
            ':priority'    => (int) ($d['priority'] ?? 100),
            ':active'      => !empty($d['active']) ? 1 : 0,
            ':notes'       => trim((string) ($d['notes'] ?? '')),
            ':updated_at'  => $now,
        ];

        $id = (int) ($d['id'] ?? 0);
        $mesclar = false;
        // Dedupe: o mesmo XUI cadastrado duas vezes só dobra o custo de
        // descoberta e confunde a contagem de usuários por servidor.
        if ($id <= 0) {
            $dup = Database::pdo()->prepare(
                'SELECT id FROM app_servers WHERE host = :h AND port = :p LIMIT 1'
            );
            $dup->execute([':h' => $host, ':p' => $row[':port']]);
            $existente = (int) $dup->fetchColumn();
            if ($existente > 0) { $id = $existente; $mesclar = true; }
        }
        // Recadastro do mesmo destino não pode APAGAR o que já estava afinado
        // (host_header e extra_hosts são o que impede vazamento de origem).
        if ($mesclar && ($atual = self::server($id))) {
            foreach ([':host_header' => 'host_header', ':extra_hosts' => 'extra_hosts', ':notes' => 'notes'] as $bind => $col) {
                if ($row[$bind] === '' && (string) ($atual[$col] ?? '') !== '') {
                    $row[$bind] = (string) $atual[$col];
                }
            }
        }
        if ($id > 0 && self::server($id)) {
            $st = Database::pdo()->prepare(
                'UPDATE app_servers SET name=:name, host=:host, port=:port, scheme=:scheme,
                        host_header=:host_header, extra_hosts=:extra_hosts, priority=:priority,
                        active=:active, notes=:notes, updated_at=:updated_at
                  WHERE id=:id'
            );
            $st->execute($row + [':id' => $id]);
            return $id;
        }

        $st = Database::pdo()->prepare(
            'INSERT INTO app_servers (name, host, port, scheme, host_header, extra_hosts,
                                      priority, active, notes, created_at, updated_at)
             VALUES (:name,:host,:port,:scheme,:host_header,:extra_hosts,:priority,:active,:notes,:created_at,:updated_at)'
        );
        $st->execute($row + [':created_at' => $now]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** @return int quantidade de usuários que voltam para a fila de descoberta */
    public static function deleteServer(int $id): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM app_user_routes WHERE server_id = :id');
        $st->execute([':id' => $id]);
        $soltos = (int) $st->fetchColumn();
        $pdo->prepare('DELETE FROM app_servers WHERE id = :id')->execute([':id' => $id]);
        // Rotas grudadas nesse servidor precisam ser redescobertas, não herdadas.
        $pdo->prepare('DELETE FROM app_user_routes WHERE server_id = :id')->execute([':id' => $id]);
        return $soltos;
    }

    /** Converte a linha do app_server para o mesmo formato que o proxy consome. */
    public static function toOrigin(array $s, int $port, string $scheme): array
    {
        return [
            'id'          => -1 * (int) $s['id'],   // negativo: não colide com origins.id
            'app_server_id' => (int) $s['id'],
            'host'        => (string) $s['host'],
            'port'        => $port,
            'scheme'      => $scheme,
            'base_path'   => '',
            'auth_user'   => '',
            'auth_pass'   => '',
            'name'        => (string) $s['name'],
            'active'      => 1,
            'type'        => filter_var($s['host'], FILTER_VALIDATE_IP) ? 'a' : 'cname',
            'host_header' => (string) ($s['host_header'] ?? ''),
            'extra_hosts' => (string) ($s['extra_hosts'] ?? ''),
        ];
    }

    // ---------------------------------------------------------------- routes

    /** Rota grudada de um username (cache quente). */
    public static function route(string $username): ?array
    {
        if ($username === '') { return null; }
        $st = Database::pdo()->prepare(
            'SELECT * FROM app_user_routes WHERE username = :u LIMIT 1'
        );
        $st->execute([':u' => $username]);
        return $st->fetch() ?: null;
    }

    public static function pinRoute(string $username, array $server, int $port, string $scheme): void
    {
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO app_user_routes
                (username, server_id, scheme, host, port, status, hits, discovered_epoch, last_epoch, updated_at)
             VALUES (:u,:s,:sc,:h,:p,"ok",1,:e,:e,:ts)
             ON CONFLICT(username) DO UPDATE SET
                server_id = excluded.server_id,
                scheme    = excluded.scheme,
                host      = excluded.host,
                port      = excluded.port,
                status    = "ok",
                failures  = 0,
                hits      = app_user_routes.hits + 1,
                last_epoch= excluded.last_epoch,
                updated_at= excluded.updated_at'
        );
        $st->execute([
            ':u' => $username, ':s' => (int) $server['id'], ':sc' => $scheme,
            ':h' => (string) $server['host'], ':p' => $port, ':e' => $now, ':ts' => date('c'),
        ]);
    }

    /** Estatística barata: só grava se passou do throttle. */
    public static function touchRoute(string $username, int $lastEpoch): void
    {
        $now = time();
        if (($now - $lastEpoch) < self::HIT_THROTTLE) { return; }
        try {
            Database::pdo()->prepare(
                'UPDATE app_user_routes SET hits = hits + 1, last_epoch = :e WHERE username = :u'
            )->execute([':e' => $now, ':u' => $username]);
        } catch (Throwable $e) {
            // estatística nunca derruba stream
        }
    }

    public static function failRoute(string $username): void
    {
        try {
            Database::pdo()->prepare(
                'UPDATE app_user_routes SET failures = failures + 1, updated_at = :ts WHERE username = :u'
            )->execute([':ts' => date('c'), ':u' => $username]);
        } catch (Throwable $e) {
        }
    }

    public static function unpin(string $username): void
    {
        Database::pdo()->prepare('DELETE FROM app_user_routes WHERE username = :u')->execute([':u' => $username]);
    }

    // ------------------------------------------------------- negative / lock

    public static function isNegative(string $username): bool
    {
        $st = Database::pdo()->prepare('SELECT until_epoch FROM app_negative_cache WHERE username = :u');
        $st->execute([':u' => $username]);
        $until = (int) ($st->fetchColumn() ?: 0);
        return $until > time();
    }

    public static function markNegative(string $username): void
    {
        try {
            Database::pdo()->prepare(
                'INSERT INTO app_negative_cache (username, until_epoch) VALUES (:u, :e)
                 ON CONFLICT(username) DO UPDATE SET until_epoch = excluded.until_epoch'
            )->execute([':u' => $username, ':e' => time() + self::NEGATIVE_TTL]);
        } catch (Throwable $e) {
        }
    }

    public static function clearNegative(string $username): void
    {
        try {
            Database::pdo()->prepare('DELETE FROM app_negative_cache WHERE username = :u')
                ->execute([':u' => $username]);
        } catch (Throwable $e) {
        }
    }

    /** true = este processo ganhou o direito de descobrir agora. */
    public static function acquireLock(string $username): bool
    {
        $now = time();
        try {
            $pdo = Database::pdo();
            $pdo->prepare('DELETE FROM app_discovery_lock WHERE expires_epoch < :n')->execute([':n' => $now]);
            $st = $pdo->prepare('INSERT OR IGNORE INTO app_discovery_lock (username, expires_epoch) VALUES (:u, :e)');
            $st->execute([':u' => $username, ':e' => $now + self::LOCK_TTL]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return true;
        }
    }

    public static function releaseLock(string $username): void
    {
        try {
            Database::pdo()->prepare('DELETE FROM app_discovery_lock WHERE username = :u')
                ->execute([':u' => $username]);
        } catch (Throwable $e) {
        }
    }

    // ---------------------------------------------------------------- stats

    public static function stats(): array
    {
        return Cache::remember('appcode_stats', 5, static function () {
            $pdo = Database::pdo();
            $row = $pdo->query(
                'SELECT COUNT(*) total,
                        SUM(CASE WHEN status = "ok" THEN 1 ELSE 0 END) ok,
                        SUM(CASE WHEN failures > 0 THEN 1 ELSE 0 END) com_falha,
                        SUM(hits) hits
                   FROM app_user_routes'
            )->fetch() ?: [];
            $perServer = $pdo->query(
                'SELECT s.id, s.name, s.host, s.active,
                        COUNT(r.username) usuarios, COALESCE(SUM(r.hits),0) hits
                   FROM app_servers s
                   LEFT JOIN app_user_routes r ON r.server_id = s.id
                  GROUP BY s.id ORDER BY s.priority ASC, s.id ASC'
            )->fetchAll();
            $neg = (int) $pdo->query('SELECT COUNT(*) FROM app_negative_cache WHERE until_epoch > ' . time())->fetchColumn();
            return [
                'total'      => (int) ($row['total'] ?? 0),
                'ok'         => (int) ($row['ok'] ?? 0),
                'com_falha'  => (int) ($row['com_falha'] ?? 0),
                'hits'       => (int) ($row['hits'] ?? 0),
                'negativos'  => $neg,
                'servidores' => $perServer,
            ];
        });
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentRoutes(int $limit = 50, string $q = ''): array
    {
        $sql = 'SELECT r.*, s.name server_name, s.host server_host
                  FROM app_user_routes r
                  LEFT JOIN app_servers s ON s.id = r.server_id';
        $args = [];
        if ($q !== '') {
            $sql .= ' WHERE r.username LIKE :q';
            $args[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY r.last_epoch DESC LIMIT ' . max(1, min(500, $limit));
        $st = Database::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }
}
