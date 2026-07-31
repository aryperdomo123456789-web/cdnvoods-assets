<?php

/**
 * Inventário dos LBs (músculos) mantido pelo cérebro.
 */
final class LbNode
{
    public const PROFILES = [
        'small'  => ['workers' => 2, 'connections' => 4096,  'fpm_children' => 12, 'fpm_start' => 4,  'fpm_min' => 2, 'fpm_max' => 6],
        'medium' => ['workers' => 3, 'connections' => 8192,  'fpm_children' => 28, 'fpm_start' => 8,  'fpm_min' => 4, 'fpm_max' => 12],
        'large'  => ['workers' => 0, 'connections' => 16384, 'fpm_children' => 60, 'fpm_start' => 16, 'fpm_min' => 8, 'fpm_max' => 24],
    ];

    public static function all(): array
    {
        return Database::pdo()->query('SELECT * FROM lb_nodes ORDER BY enabled DESC, label ASC')->fetchAll() ?: [];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM lb_nodes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByIp(string $ip): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM lb_nodes WHERE public_ip = :ip');
        $stmt->execute([':ip' => $ip]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findByToken(string $token): ?array
    {
        if (strlen($token) < 20) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM lb_nodes WHERE agent_token = :t');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Cria ou atualiza. Senha vazia em edição mantém a senha atual. */
    public static function save(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $ip = trim((string) ($data['public_ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('IP público do LB inválido.');
        }
        $port = (int) ($data['ssh_port'] ?? 22);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Porta SSH inválida.');
        }
        $user = trim((string) ($data['ssh_user'] ?? 'root'));
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $user)) {
            throw new InvalidArgumentException('Usuário SSH inválido.');
        }
        $label = trim((string) ($data['label'] ?? '')) ?: ('LB ' . $ip);

        $dup = self::findByIp($ip);
        if ($dup && (int) $dup['id'] !== $id) {
            throw new InvalidArgumentException('Já existe um LB cadastrado com esse IP.');
        }

        $password = (string) ($data['ssh_password'] ?? '');
        $enc = $password !== '' ? LbCrypto::encrypt($password) : '';
        $now = date('c');
        $pdo = Database::pdo();

        if ($id > 0) {
            $current = self::find($id);
            if (!$current) {
                throw new InvalidArgumentException('LB não encontrado.');
            }
            if ($enc === '') {
                $enc = (string) $current['ssh_password_enc'];
            }
            $stmt = $pdo->prepare(
                'UPDATE lb_nodes SET label=:l, public_ip=:ip, ssh_host=:h, ssh_port=:p, ssh_user=:u,
                 ssh_password_enc=:pw, declared_bandwidth_mbps=:bw, weight=:w, enabled=:en, drain_mode=:dr,
                 max_users_soft=:mus, max_users_hard=:muh, max_mbps_soft=:mms, max_mbps_hard=:mmh,
                 auto_install=:ai, updated_at=:up WHERE id=:id'
            );
            $stmt->execute(self::params($label, $ip, $port, $user, $enc, $data, $now) + [':id' => $id]);
            Audit::log('lb_update', sprintf('LB #%d %s atualizado', $id, $ip));
            return $id;
        }

        if ($enc === '') {
            throw new InvalidArgumentException('Informe a senha root do LB para cadastrar.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO lb_nodes (label, public_ip, ssh_host, ssh_port, ssh_user, ssh_password_enc,
             declared_bandwidth_mbps, weight, enabled, drain_mode, max_users_soft, max_users_hard,
             max_mbps_soft, max_mbps_hard, auto_install, agent_token, created_at, updated_at)
             VALUES (:l,:ip,:h,:p,:u,:pw,:bw,:w,:en,:dr,:mus,:muh,:mms,:mmh,:ai,:tok,:cr,:up)'
        );
        $stmt->execute(self::params($label, $ip, $port, $user, $enc, $data, $now) + [
            ':tok' => bin2hex(random_bytes(24)),
            ':cr' => $now,
        ]);
        $id = (int) $pdo->lastInsertId();
        Audit::log('lb_create', sprintf('LB #%d %s cadastrado', $id, $ip));
        return $id;
    }

    private static function params(string $label, string $ip, int $port, string $user, string $enc, array $d, string $now): array
    {
        return [
            ':l' => $label,
            ':ip' => $ip,
            ':h' => trim((string) ($d['ssh_host'] ?? '')) ?: $ip,
            ':p' => $port,
            ':u' => $user,
            ':pw' => $enc,
            ':bw' => max(0, (int) ($d['declared_bandwidth_mbps'] ?? 10000)),
            ':w' => max(1, min(1000, (int) ($d['weight'] ?? 100))),
            ':en' => !empty($d['enabled']) ? 1 : 0,
            ':dr' => !empty($d['drain_mode']) ? 1 : 0,
            ':mus' => max(0, (int) ($d['max_users_soft'] ?? 0)),
            ':muh' => max(0, (int) ($d['max_users_hard'] ?? 0)),
            ':mms' => max(0, (int) ($d['max_mbps_soft'] ?? 0)),
            ':mmh' => max(0, (int) ($d['max_mbps_hard'] ?? 0)),
            ':ai' => !empty($d['auto_install']) ? 1 : 0,
            ':up' => $now,
        ];
    }

    public static function delete(int $id): void
    {
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM lb_nodes WHERE id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM lb_installs WHERE lb_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM lb_metrics WHERE lb_id = :id')->execute([':id' => $id]);
        $pdo->prepare('UPDATE lb_user_routes SET lb_id = 0, mode = "auto" WHERE lb_id = :id')->execute([':id' => $id]);
        Audit::log('lb_delete', 'LB #' . $id . ' removido');
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        Database::pdo()->prepare('UPDATE lb_nodes SET enabled = :e, updated_at = :u WHERE id = :id')
            ->execute([':e' => $enabled ? 1 : 0, ':u' => date('c'), ':id' => $id]);
        Audit::log('lb_toggle', sprintf('LB #%d %s', $id, $enabled ? 'ativado' : 'desativado'));
    }

    public static function setDrain(int $id, bool $drain): void
    {
        Database::pdo()->prepare('UPDATE lb_nodes SET drain_mode = :d, updated_at = :u WHERE id = :id')
            ->execute([':d' => $drain ? 1 : 0, ':u' => date('c'), ':id' => $id]);
    }

    public static function update(int $id, array $fields): void
    {
        if (!$fields) {
            return;
        }
        $allowed = [
            'os_name', 'os_version', 'cpu_cores', 'ram_mb', 'disk_total_gb', 'disk_free_gb', 'profile',
            'measured_bandwidth_mbps', 'health_status', 'health_message', 'install_status', 'install_step',
            'install_run_id', 'last_seen_epoch', 'last_probe_epoch',
            'auth_mode', 'key_installed', 'key_fingerprint', 'key_promoted_at', 'password_bootstrap_done',
        ];
        $sets = [];
        $params = [':id' => $id, ':u' => date('c')];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = $k . ' = :' . $k;
            $params[':' . $k] = $v;
        }
        if (!$sets) {
            return;
        }
        self::dbRetry(static function () use ($id, $sets, $params): void {
            Database::pdo()->prepare('UPDATE lb_nodes SET ' . implode(', ', $sets) . ', updated_at = :u WHERE id = :id')
                ->execute($params);
        });
    }

    /** Perfil automático a partir do hardware detectado. */
    public static function profileFor(int $cores, int $ramMb): string
    {
        if ($cores <= 2 && $ramMb <= 4096) {
            return 'small';
        }
        if ($cores <= 4 && $ramMb <= 8192) {
            return 'medium';
        }
        return 'large';
    }

    public static function tuning(string $profile, int $cores): array
    {
        $p = self::PROFILES[$profile] ?? self::PROFILES['medium'];
        if ((int) $p['workers'] === 0) {
            $p['workers'] = max(1, $cores);
        }
        return $p;
    }

    /** Visão pública (sem segredo) para o painel/JSON. */
    public static function publicView(array $node): array
    {
        unset($node['ssh_password_enc'], $node['agent_token']);
        return $node;
    }

    /** Marca o nó como promovido para autenticação por chave. */
    public static function promoteToKey(int $id, string $fingerprint): void
    {
        self::update($id, [
            'auth_mode' => 'key',
            'key_installed' => 1,
            'key_fingerprint' => $fingerprint,
            'key_promoted_at' => date('c'),
            'password_bootstrap_done' => 1,
        ]);
        Audit::log('lb_key_promote', sprintf('LB #%d passou a usar chave Ed25519 (%s)', $id, $fingerprint));
    }

    /** Descarta a senha root guardada (a chave já é suficiente). */
    public static function forgetPassword(int $id): void
    {
        self::dbRetry(static function () use ($id): void {
            Database::pdo()->prepare('UPDATE lb_nodes SET ssh_password_enc = "", updated_at = :u WHERE id = :id')
                ->execute([':u' => date('c'), ':id' => $id]);
        });
        Audit::log('lb_password_discard', 'Senha root do LB #' . $id . ' descartada após promoção para chave');
    }

    private static function dbRetry(callable $fn, int $attempts = 8, int $sleepUs = 250000): void
    {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $fn();
                return;
            } catch (Throwable $e) {
                $last = $e;
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'database is locked') && !str_contains($msg, 'database table is locked')) {
                    throw $e;
                }
                usleep($sleepUs);
            }
        }
        if ($last) {
            throw $last;
        }
    }
}
