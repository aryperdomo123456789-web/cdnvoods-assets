<?php

final class XuiAdmin
{
    private static ?PDO $pdo = null;

    public static function available(): bool
    {
        return in_array('mysql', PDO::getAvailableDrivers(), true);
    }

    public static function configured(): bool
    {
        $cfg = XuiSyncConfig::get();
        return trim((string) ($cfg['host'] ?? '')) !== ''
            && trim((string) ($cfg['username'] ?? '')) !== '';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!self::available()) {
            throw new RuntimeException('driver pdo_mysql ausente');
        }
        $cfg = XuiSyncConfig::get();
        $host = trim((string) ($cfg['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('host do XUI não configurado');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            max(1, (int) ($cfg['port'] ?? 3306)),
            (string) ($cfg['database_name'] ?? 'xtream_iptvpro')
        );
        self::$pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => max(1, (int) ($cfg['connect_timeout_seconds'] ?? 3)),
        ]);
        return self::$pdo;
    }

    public static function ping(): array
    {
        $start = microtime(true);
        try {
            self::pdo()->query('SELECT 1');
            return ['ok' => true, 'ms' => (int) round((microtime(true) - $start) * 1000), 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => (int) round((microtime(true) - $start) * 1000), 'error' => $e->getMessage()];
        }
    }

    public static function bouquets(): array
    {
        $rows = self::pdo()->query(
            'SELECT id, bouquet_name, bouquet_order FROM bouquets ORDER BY bouquet_order ASC, id ASC'
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['bouquet_name'] = trim((string) ($row['bouquet_name'] ?? '')) ?: ('Bouquet #' . $row['id']);
        }
        unset($row);
        return $rows;
    }

    public static function recentLines(int $limit = 100): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT id, username, exp_date, enabled, admin_enabled, bouquet, allowed_outputs,
                    max_connections, is_restreamer, is_trial, created_at, package_id, last_ip, last_activity
               FROM `lines`
              ORDER BY id DESC
              LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['bouquet_count'] = count(self::parseJsonInts((string) ($row['bouquet'] ?? '[]')));
            $row['exp_date_label'] = self::formatEpoch((int) ($row['exp_date'] ?? 0));
            $row['created_at_label'] = self::formatEpoch((int) ($row['created_at'] ?? 0));
            $row['last_activity_label'] = self::formatEpoch((int) ($row['last_activity'] ?? 0));
        }
        unset($row);
        return $rows;
    }

    public static function findLine(int $id): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT id, member_id, username, password, last_ip, exp_date, admin_enabled, enabled,
                    admin_notes, reseller_notes, bouquet, allowed_outputs, max_connections,
                    is_restreamer, is_trial, allowed_ips, allowed_ua, created_at, pair_id,
                    force_server_id, package_id, contact, last_activity, updated
               FROM `lines`
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['bouquet_ids'] = self::parseJsonInts((string) ($row['bouquet'] ?? '[]'));
        $row['allowed_output_ids'] = self::parseJsonInts((string) ($row['allowed_outputs'] ?? '[]'));
        $row['exp_date_input'] = self::formatDateInput((int) ($row['exp_date'] ?? 0));
        $row['created_at_label'] = self::formatEpoch((int) ($row['created_at'] ?? 0));
        $row['last_activity_label'] = self::formatEpoch((int) ($row['last_activity'] ?? 0));
        return $row;
    }

    public static function createLine(array $data): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        $password = trim((string) ($data['password'] ?? ''));
        if ($username === '' || $password === '') {
            throw new RuntimeException('username e senha são obrigatórios');
        }
        $bouquets = array_values(array_unique(array_map('intval', $data['bouquets'] ?? [])));
        if ($bouquets === []) {
            throw new RuntimeException('selecione pelo menos um bouquet');
        }
        $maxConnections = max(1, (int) ($data['max_connections'] ?? 1));
        $expDate = self::parseExpiration((string) ($data['exp_date'] ?? ''));
        $allowedOutputs = self::normalizeOutputs($data['allowed_outputs'] ?? [1, 2, 3]);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $adminEnabled = !empty($data['admin_enabled']) ? 1 : 0;
        $isTrial = !empty($data['is_trial']) ? 1 : 0;
        $isRestreamer = !empty($data['is_restreamer']) ? 1 : 0;
        $allowedIps = trim((string) ($data['allowed_ips'] ?? ''));
        $allowedUa = trim((string) ($data['allowed_ua'] ?? ''));
        $notes = trim((string) ($data['admin_notes'] ?? ''));
        $memberId = max(0, (int) ($data['member_id'] ?? 0));
        $forceServerId = max(0, (int) ($data['force_server_id'] ?? 0));
        $now = time();

        $pdo = self::pdo();
        $check = $pdo->prepare('SELECT id FROM `lines` WHERE username = :u LIMIT 1');
        $check->execute([':u' => $username]);
        if ($check->fetchColumn()) {
            throw new RuntimeException('já existe um usuário com esse username no XUI');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO `lines`
                (member_id, username, password, exp_date, admin_enabled, enabled, admin_notes,
                 bouquet, allowed_outputs, max_connections, is_restreamer, is_trial, is_mag, is_e2,
                 is_stalker, is_isplock, allowed_ips, allowed_ua, created_at, pair_id,
                 force_server_id, as_number, isp_desc, forced_country, bypass_ua, play_token,
                 last_expiration_video, package_id, access_token, contact, last_activity, last_activity_array)
             VALUES
                (:member_id, :username, :password, :exp_date, :admin_enabled, :enabled, :admin_notes,
                 :bouquet, :allowed_outputs, :max_connections, :is_restreamer, :is_trial, 0, 0,
                 0, 0, :allowed_ips, :allowed_ua, :created_at, 0,
                 :force_server_id, "", "", "", 0, "",
                 0, NULL, "", "", 0, "")'
        );
        $stmt->execute([
            ':member_id' => $memberId,
            ':username' => $username,
            ':password' => $password,
            ':exp_date' => $expDate > 0 ? $expDate : null,
            ':admin_enabled' => $adminEnabled,
            ':enabled' => $enabled,
            ':admin_notes' => $notes,
            ':bouquet' => json_encode($bouquets, JSON_UNESCAPED_SLASHES),
            ':allowed_outputs' => json_encode($allowedOutputs, JSON_UNESCAPED_SLASHES),
            ':max_connections' => $maxConnections,
            ':is_restreamer' => $isRestreamer,
            ':is_trial' => $isTrial,
            ':allowed_ips' => $allowedIps,
            ':allowed_ua' => $allowedUa,
            ':created_at' => $now,
            ':force_server_id' => $forceServerId,
        ]);

        $id = (int) $pdo->lastInsertId();
        Audit::log(
            'xui_line_created',
            sprintf('line_id=%d username=%s max=%d bouquets=%d exp=%s', $id, $username, $maxConnections, count($bouquets), $expDate ?: 0),
            '-',
            'panel'
        );
        return [
            'id' => $id,
            'username' => $username,
            'max_connections' => $maxConnections,
            'bouquet_count' => count($bouquets),
            'exp_date' => $expDate,
        ];
    }

    public static function updateLine(int $id, array $data): array
    {
        $current = self::findLine($id);
        if (!$current) {
            throw new RuntimeException('usuário do XUI não encontrado');
        }
        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            throw new RuntimeException('username é obrigatório');
        }
        $password = trim((string) ($data['password'] ?? ''));
        if ($password === '') {
            $password = (string) ($current['password'] ?? '');
        }
        $bouquets = array_values(array_unique(array_map('intval', $data['bouquets'] ?? [])));
        if ($bouquets === []) {
            throw new RuntimeException('selecione pelo menos um bouquet');
        }
        $maxConnections = max(1, (int) ($data['max_connections'] ?? 1));
        $expDate = self::parseExpiration((string) ($data['exp_date'] ?? ''));
        $allowedOutputs = self::normalizeOutputs($data['allowed_outputs'] ?? [1, 2, 3]);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $adminEnabled = !empty($data['admin_enabled']) ? 1 : 0;
        $isTrial = !empty($data['is_trial']) ? 1 : 0;
        $isRestreamer = !empty($data['is_restreamer']) ? 1 : 0;
        $allowedIps = trim((string) ($data['allowed_ips'] ?? ''));
        $allowedUa = trim((string) ($data['allowed_ua'] ?? ''));
        $notes = trim((string) ($data['admin_notes'] ?? ''));
        $memberId = max(0, (int) ($data['member_id'] ?? 0));
        $forceServerId = max(0, (int) ($data['force_server_id'] ?? 0));

        $check = self::pdo()->prepare('SELECT id FROM `lines` WHERE username = :u AND id <> :id LIMIT 1');
        $check->execute([':u' => $username, ':id' => $id]);
        if ($check->fetchColumn()) {
            throw new RuntimeException('já existe outro usuário com esse username no XUI');
        }

        $stmt = self::pdo()->prepare(
            'UPDATE `lines`
                SET member_id = :member_id,
                    username = :username,
                    password = :password,
                    exp_date = :exp_date,
                    admin_enabled = :admin_enabled,
                    enabled = :enabled,
                    admin_notes = :admin_notes,
                    bouquet = :bouquet,
                    allowed_outputs = :allowed_outputs,
                    max_connections = :max_connections,
                    is_restreamer = :is_restreamer,
                    is_trial = :is_trial,
                    allowed_ips = :allowed_ips,
                    allowed_ua = :allowed_ua,
                    force_server_id = :force_server_id
              WHERE id = :id'
        );
        $stmt->execute([
            ':member_id' => $memberId,
            ':username' => $username,
            ':password' => $password,
            ':exp_date' => $expDate > 0 ? $expDate : null,
            ':admin_enabled' => $adminEnabled,
            ':enabled' => $enabled,
            ':admin_notes' => $notes,
            ':bouquet' => json_encode($bouquets, JSON_UNESCAPED_SLASHES),
            ':allowed_outputs' => json_encode($allowedOutputs, JSON_UNESCAPED_SLASHES),
            ':max_connections' => $maxConnections,
            ':is_restreamer' => $isRestreamer,
            ':is_trial' => $isTrial,
            ':allowed_ips' => $allowedIps,
            ':allowed_ua' => $allowedUa,
            ':force_server_id' => $forceServerId,
            ':id' => $id,
        ]);
        Audit::log(
            'xui_line_updated',
            sprintf('line_id=%d username=%s max=%d enabled=%d admin_enabled=%d bouquets=%d', $id, $username, $maxConnections, $enabled, $adminEnabled, count($bouquets)),
            '-',
            'panel'
        );
        return ['id' => $id, 'username' => $username];
    }

    public static function setLineEnabled(int $id, bool $enabled): void
    {
        $line = self::findLine($id);
        if (!$line) {
            throw new RuntimeException('usuário do XUI não encontrado');
        }
        $stmt = self::pdo()->prepare('UPDATE `lines` SET enabled = :en, admin_enabled = :ad WHERE id = :id');
        $value = $enabled ? 1 : 0;
        $stmt->execute([':en' => $value, ':ad' => $value, ':id' => $id]);
        Audit::log(
            $enabled ? 'xui_line_enabled' : 'xui_line_disabled',
            sprintf('line_id=%d username=%s', $id, (string) $line['username']),
            '-',
            'panel'
        );
    }

    public static function deleteLine(int $id): void
    {
        $line = self::findLine($id);
        if (!$line) {
            throw new RuntimeException('usuário do XUI não encontrado');
        }
        $stmt = self::pdo()->prepare('DELETE FROM `lines` WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        Audit::log(
            'xui_line_deleted',
            sprintf('line_id=%d username=%s', $id, (string) $line['username']),
            '-',
            'panel'
        );
    }

    public static function summary(): array
    {
        $pdo = self::pdo();
        return [
            'lines_total' => (int) $pdo->query('SELECT COUNT(*) FROM `lines`')->fetchColumn(),
            'lines_enabled' => (int) $pdo->query('SELECT COUNT(*) FROM `lines` WHERE enabled = 1')->fetchColumn(),
            'bouquets_total' => (int) $pdo->query('SELECT COUNT(*) FROM bouquets')->fetchColumn(),
        ];
    }

    private static function parseExpiration(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $ts = strtotime($value . ' 23:59:59');
        if ($ts === false) {
            throw new RuntimeException('data de expiração inválida');
        }
        return $ts;
    }

    private static function normalizeOutputs($raw): array
    {
        $list = is_array($raw) ? $raw : [$raw];
        $out = [];
        foreach ($list as $value) {
            $n = (int) $value;
            if (in_array($n, [1, 2, 3], true)) {
                $out[] = $n;
            }
        }
        $out = array_values(array_unique($out));
        return $out === [] ? [1, 2, 3] : $out;
    }

    private static function parseJsonInts(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map('intval', $decoded));
    }

    private static function formatEpoch(int $epoch): string
    {
        return $epoch > 0 ? date('Y-m-d H:i:s', $epoch) : 'ilimitado';
    }

    private static function formatDateInput(int $epoch): string
    {
        return $epoch > 0 ? date('Y-m-d', $epoch) : '';
    }
}
