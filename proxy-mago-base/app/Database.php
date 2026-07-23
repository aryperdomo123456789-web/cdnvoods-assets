<?php

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = Config::get('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        self::migrate(self::$pdo);
        return self::$pdo;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        foreach ($stmt->fetchAll() as $row) {
            if (strcasecmp((string) $row['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                client_ip TEXT NOT NULL,
                user_agent TEXT NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );

        // Fase 1: origens protegidas (XUI etc.). Credenciais ficam APENAS aqui.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS origins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                host TEXT NOT NULL,
                port INTEGER NOT NULL DEFAULT 80,
                scheme TEXT NOT NULL DEFAULT "http",
                base_path TEXT NOT NULL DEFAULT "",
                auth_user TEXT NOT NULL DEFAULT "",
                auth_pass TEXT NOT NULL DEFAULT "",
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        // Migração idempotente: tipo de apontamento (A = IP direto, CNAME = hostname)
        // e host_header opcional para quando o XUI só responde a um vhost específico.
        if (!self::hasColumn($pdo, 'origins', 'type')) {
            $pdo->exec('ALTER TABLE origins ADD COLUMN type TEXT NOT NULL DEFAULT "a"');
        }
        if (!self::hasColumn($pdo, 'origins', 'host_header')) {
            $pdo->exec('ALTER TABLE origins ADD COLUMN host_header TEXT NOT NULL DEFAULT ""');
        }

        // Aliases publicos (main + CNAMEs que resolvem via Cloudflare para a VPS).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS aliases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hostname TEXT NOT NULL UNIQUE,
                origin_id INTEGER NOT NULL,
                is_primary INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (origin_id) REFERENCES origins(id) ON DELETE CASCADE
            )'
        );

        // Tokens efemeros que os players carregam.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT NOT NULL UNIQUE,
                alias_id INTEGER NOT NULL,
                allowed_ip TEXT NOT NULL DEFAULT "",
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_used_at TEXT NOT NULL DEFAULT "",
                hits INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (alias_id) REFERENCES aliases(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tokens_expires ON tokens(expires_at)');

        // Log de acesso do proxy (nunca guarda IP da origem nem credenciais).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS access_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts TEXT NOT NULL,
                client_ip TEXT NOT NULL,
                host TEXT NOT NULL,
                path TEXT NOT NULL,
                status INTEGER NOT NULL,
                bytes INTEGER NOT NULL DEFAULT 0,
                token_id INTEGER,
                origin_id INTEGER,
                reason TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_access_ts ON access_log(ts)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_access_ip ON access_log(client_ip)');

        // Janela deslizante de rate limit por IP.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS rate_limit (
                client_ip TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (client_ip, window_start)
            )'
        );
    }
}
