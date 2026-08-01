<?php

final class Database
{
    /**
     * Versão MANUAL do schema.
     *
     * Antes isso era derivado do mtime/tamanho deste arquivo. Na prática,
     * qualquer ajuste de comentário ou código disparava a rotina completa de
     * migração em produção, concorrendo com requests ao vivo e causando
     * `database is locked`. O schema agora só muda quando esta constante muda.
     */
    private const SCHEMA_VERSION = 20260736;
    private static ?PDO $pdo = null;
    private static bool $migrated = false;
    private static int $lockRetries = 0;
    /** @var array<string,bool> */
    private static array $columnCache = [];

    public static function driver(): string
    {
        $driver = strtolower(trim((string) Config::get('db_driver', 'sqlite')));
        return in_array($driver, ['sqlite', 'pgsql'], true) ? $driver : 'sqlite';
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    public static function isPgsql(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::$pdo = new PDO(self::dsn(), self::dbUser(), self::dbPass(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::configureConnection(self::$pdo);

        // Performance: rodar as ~150 instruções DDL em TODA requisição custa caro
        // (painel e, pior, cada request de stream). A versão do schema é derivada
        // do próprio arquivo (mtime+size), então um deploy novo migra sozinho.
        self::ensureSchema(self::$pdo);
        return self::$pdo;
    }

    private static function dsn(): string
    {
        if (self::isPgsql()) {
            $host = (string) Config::get('db_host', '127.0.0.1');
            $port = (int) Config::get('db_port', 5432);
            $name = (string) Config::get('db_name', 'proxy_mago');
            $sslmode = (string) Config::get('db_sslmode', 'prefer');
            return sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $name, $sslmode);
        }

        $path = (string) Config::get('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return 'sqlite:' . $path;
    }

    private static function dbUser(): ?string
    {
        return self::isPgsql() ? (string) Config::get('db_user', '') : null;
    }

    private static function dbPass(): ?string
    {
        return self::isPgsql() ? (string) Config::get('db_pass', '') : null;
    }

    private static function configureConnection(PDO $pdo): void
    {
        if (self::isPgsql()) {
            $pdo->exec("SET TIME ZONE 'UTC'");
            return;
        }

        // O timeout precisa existir ANTES de qualquer PRAGMA que possa disputar
        // lock. Antes, cada processo CLI/FPM tentava reafirmar journal_mode=WAL
        // sem espera e podia morrer em configureConnection(), fora da blindagem
        // de Database::write(). Em banco já promovido para WAL, consultar o modo
        // é suficiente e não tenta adquirir o lock exclusivo de mudança de modo.
        $pdo->exec('PRAGMA busy_timeout = 30000');
        $journalMode = strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn());
        if ($journalMode !== 'wal') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        // Fase 0 — estabilização do cérebro.
        //
        // O SQLite é UM arquivo servindo, ao mesmo tempo: tráfego ao vivo
        // (cdn_sessions + proxy_request_events), 15 jobs internos, telemetria
        // de LB e polling do painel. Sem folga de espera qualquer escritor
        // levanta "database is locked" e o painel/stream perde trilha.
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA cache_size = -20000');       // ~20MB de page cache
        $pdo->exec('PRAGMA wal_autocheckpoint = 512');  // checkpoint mais curto = WAL menor
        $pdo->exec('PRAGMA mmap_size = 134217728');     // 128MB de leitura por mmap
    }

    /**
     * Executa uma ESCRITA com retry e backoff quando o SQLite está travado.
     *
     * Só o caminho de escrita precisa disso: leitura em WAL não bloqueia. O
     * callable recebe o PDO e pode fazer 1..N statements; ele PRECISA ser
     * idempotente (UPSERT/UPDATE), porque pode ser repetido.
     *
     * Nunca lança para o chamador do stream: devolve false e loga.
     *
     * @param callable(PDO):void $fn
     */
    public static function write(callable $fn, string $tag = 'write', int $attempts = 5): bool
    {
        $pdo = self::pdo();
        $delayUs = 25000; // 25ms, 50ms, 100ms, 200ms, 400ms
        for ($i = 1; $i <= max(1, $attempts); $i++) {
            try {
                $fn($pdo);
                if ($i > 1) {
                    self::$lockRetries++;
                }
                return true;
            } catch (Throwable $e) {
                $msg = strtolower($e->getMessage());
                $locked = str_contains($msg, 'locked') || str_contains($msg, 'busy');
                if ($locked && class_exists('DbLockDiag')) {
                    // Instrumentação: sem isso o log só diz "database is locked"
                    // e não diz qual tabela/operação nem quem escrevia junto.
                    DbLockDiag::note('(via tag)', 'write', $tag, $e->getMessage(), $i, $i >= $attempts);
                }
                if (!$locked || $i >= $attempts) {
                    error_log('[db:' . $tag . '] ' . $e->getMessage());
                    return false;
                }
                usleep($delayUs + random_int(0, 15000));
                $delayUs = min($delayUs * 2, 400000);
            }
        }
        return false;
    }

    /** Atalho: 1 statement preparado com retry. */
    public static function run(string $sql, array $params = [], string $tag = 'run'): bool
    {
        return self::write(static function (PDO $pdo) use ($sql, $params): void {
            $pdo->prepare($sql)->execute($params);
        }, $tag);
    }

    /** Quantas escritas precisaram de retry neste processo (observabilidade). */
    public static function lockRetries(): int
    {
        return self::$lockRetries;
    }

    /** Diagnóstico do arquivo SQLite para o painel de auditoria. */
    public static function healthSnapshot(): array
    {
        $pdo = self::pdo();
        if (self::isPgsql()) {
            return [
                'driver' => 'pgsql',
                'journal_mode' => 'mvcc',
                'busy_timeout_ms' => 0,
                'db_bytes' => 0,
                'wal_bytes' => 0,
                'lock_retries' => self::$lockRetries,
                'schema_version' => self::schemaVersion(),
            ];
        }
        $path = (string) Config::get('db_path');
        $wal = $path . '-wal';
        return [
            'driver' => 'sqlite',
            'journal_mode' => (string) $pdo->query('PRAGMA journal_mode')->fetchColumn(),
            'busy_timeout_ms' => (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn(),
            'db_bytes' => (int) @filesize($path),
            'wal_bytes' => (int) @filesize($wal),
            'lock_retries' => self::$lockRetries,
            'schema_version' => (int) $pdo->query('PRAGMA user_version')->fetchColumn(),
        ];
    }

    /** Versão estável do schema: só muda quando há migração real. */
    private static function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    private static function ensureSchema(PDO $pdo): void
    {
        if (self::isPgsql()) {
            self::migratePgsqlHot($pdo);
            self::$migrated = true;
            return;
        }
        if (self::$migrated) {
            return;
        }
        try {
            $want = self::schemaVersion();
            $have = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            if ($have === $want) {
                self::$migrated = true;
                return;
            }
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'locked') || str_contains($msg, 'busy')) {
                error_log('[db:schema] leitura da versão adiada por lock: ' . $e->getMessage());
                return;
            }
            throw $e;
        }

        $lock = self::acquireSchemaLock();
        if (!is_resource($lock)) {
            // Em produção o mais seguro é não martelar DDL no caminho quente.
            // Se outro processo estiver migrando, este request segue usando o
            // schema atual e o próximo ciclo reavalia.
            error_log('[db:schema] migração adiada: lock de schema indisponível');
            return;
        }
        try {
            $have = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            if ($have !== $want) {
                self::migrate($pdo);
                $pdo->exec('PRAGMA user_version = ' . $want);
            }
            self::$migrated = true;
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'locked') || str_contains($msg, 'busy')) {
                error_log('[db:schema] migração adiada por lock: ' . $e->getMessage());
                return;
            }
            throw $e;
        } finally {
            self::releaseSchemaLock($lock);
        }
    }

    /** Força a migração (deploy/CLI), ignorando o cache de versão. */
    public static function migrateNow(): void
    {
        $pdo = self::pdo();
        if (self::isPgsql()) {
            self::migratePgsqlHot($pdo);
            self::$migrated = true;
            return;
        }
        $lock = self::acquireSchemaLock(true);
        if (!is_resource($lock)) {
            throw new RuntimeException('Não foi possível adquirir o lock de migração do schema.');
        }
        try {
            self::migrate($pdo);
            $pdo->exec('PRAGMA user_version = ' . self::schemaVersion());
            self::$migrated = true;
        } finally {
            self::releaseSchemaLock($lock);
        }
    }

    /** @return resource|false */
    private static function acquireSchemaLock(bool $blocking = false)
    {
        $dir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/schema-migrate.lock';
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }
        @chmod($path, 0664);
        $mode = LOCK_EX | ($blocking ? 0 : LOCK_NB);
        if (!@flock($fh, $mode)) {
            @fclose($fh);
            return false;
        }
        return $fh;
    }

    /** @param resource $fh */
    private static function releaseSchemaLock($fh): void
    {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        if (self::isPgsql()) {
            $stmt = $pdo->prepare(
                'SELECT 1
                   FROM information_schema.columns
                  WHERE table_schema = current_schema()
                    AND table_name = :table
                    AND column_name = :column
                  LIMIT 1'
            );
            $stmt->execute([
                ':table' => strtolower($table),
                ':column' => strtolower($column),
            ]);
            return (bool) $stmt->fetchColumn();
        }

        $stmt = $pdo->query("PRAGMA table_info(" . $table . ")");
        foreach ($stmt->fetchAll() as $row) {
            if (strcasecmp((string) $row['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function tableHasColumn(string $table, string $column): bool
    {
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }
        return self::$columnCache[$key] = self::hasColumn(self::pdo(), $table, $column);
    }

    /**
     * A tabela existe no driver ativo? Usado pelas trilhas que rodam antes de
     * a migração nova ter passado (deploy em duas etapas na VPS real).
     */
    public static function tableExists(string $table): bool
    {
        $key = '#table#' . strtolower($table);
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }
        $pdo = self::pdo();
        try {
            if (self::isPgsql()) {
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM information_schema.tables
                      WHERE table_schema = current_schema() AND table_name = :t LIMIT 1'
                );
                $stmt->execute([':t' => strtolower($table)]);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t LIMIT 1"
                );
                $stmt->execute([':t' => $table]);
            }
            return self::$columnCache[$key] = (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return self::$columnCache[$key] = false;
        }
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $decl): void
    {
        if (self::hasColumn($pdo, $table, $column)) {
            return;
        }
        try {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $decl);
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column name')) {
                throw $e;
            }
        }
    }

    public static function insertIgnoreSql(string $table, array $columns): string
    {
        $cols = implode(', ', $columns);
        $vals = implode(',', array_map(static fn(string $c): string => ':' . $c, $columns));
        if (self::isPgsql()) {
            return 'INSERT INTO ' . $table . ' (' . $cols . ') VALUES (' . $vals . ') ON CONFLICT DO NOTHING';
        }
        return 'INSERT OR IGNORE INTO ' . $table . ' (' . $cols . ') VALUES (' . $vals . ')';
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
        // Hosts adicionais que pertencem à origem (main alternativo, CDN interna,
        // subdomínios do XUI). Tudo aqui é sanitizado do corpo das respostas.
        if (!self::hasColumn($pdo, 'origins', 'extra_hosts')) {
            $pdo->exec('ALTER TABLE origins ADD COLUMN extra_hosts TEXT NOT NULL DEFAULT ""');
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

        // Fluxo XUI clássico: o assinante chega com username/password na query.
        // require_token = 0 (padrão) libera esse fluxo; 1 mantém o modo token.
        if (!self::hasColumn($pdo, 'aliases', 'require_token')) {
            $pdo->exec('ALTER TABLE aliases ADD COLUMN require_token INTEGER NOT NULL DEFAULT 0');
        }

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

        self::migrateRestream($pdo);
    }

    /**
     * Fase Restreamento: espelho read-only do XUI + rastreabilidade total do
     * proxy + auditoria de jobs internos. Tudo idempotente (CREATE IF NOT EXISTS).
     */
    private static function migrateRestream(PDO $pdo): void
    {
        // Conexão read-only com o MySQL do XUI (uma linha só, id = 1).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS xui_sync_config (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                host TEXT NOT NULL DEFAULT "",
                port INTEGER NOT NULL DEFAULT 3306,
                database_name TEXT NOT NULL DEFAULT "xtream_iptvpro",
                username TEXT NOT NULL DEFAULT "",
                password TEXT NOT NULL DEFAULT "",
                api_url TEXT NOT NULL DEFAULT "",
                api_token TEXT NOT NULL DEFAULT "",
                use_tls INTEGER NOT NULL DEFAULT 0,
                sync_enabled INTEGER NOT NULL DEFAULT 0,
                sync_interval_seconds INTEGER NOT NULL DEFAULT 5,
                users_interval_seconds INTEGER NOT NULL DEFAULT 60,
                streams_interval_seconds INTEGER NOT NULL DEFAULT 300,
                connect_timeout_seconds INTEGER NOT NULL DEFAULT 3,
                read_timeout_seconds INTEGER NOT NULL DEFAULT 5,
                last_sync_at TEXT NOT NULL DEFAULT "",
                last_sync_status TEXT NOT NULL DEFAULT "never",
                last_sync_error TEXT NOT NULL DEFAULT "",
                updated_at TEXT NOT NULL DEFAULT ""
            )'
        );
        foreach ([
            'api_url' => 'TEXT NOT NULL DEFAULT ""',
            'api_token' => 'TEXT NOT NULL DEFAULT ""',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'xui_sync_config', $col, $decl);
        }

        // Espelho mínimo de users. NUNCA guardamos a senha em claro: só máscara
        // + fingerprint sha256(username:password) para casar com o request.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS xui_users_cache (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                password_masked TEXT NOT NULL DEFAULT "",
                credential_fingerprint TEXT NOT NULL DEFAULT "",
                max_connections INTEGER NOT NULL DEFAULT 0,
                enabled INTEGER NOT NULL DEFAULT 1,
                exp_date TEXT NOT NULL DEFAULT "",
                is_trial INTEGER NOT NULL DEFAULT 0,
                is_restreamer INTEGER NOT NULL DEFAULT 0,
                allowed_ips TEXT NOT NULL DEFAULT "",
                allowed_ua TEXT NOT NULL DEFAULT "",
                synced_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_users_username ON xui_users_cache(username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_users_fp ON xui_users_cache(credential_fingerprint)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cdn_user_ip_lock (
                username TEXT PRIMARY KEY,
                allowed_ips TEXT NOT NULL DEFAULT "",
                notes TEXT NOT NULL DEFAULT "",
                updated_at TEXT NOT NULL DEFAULT "",
                updated_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cuil_updated ON cdn_user_ip_lock(updated_epoch)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS xui_streams_cache (
                stream_id INTEGER PRIMARY KEY,
                type TEXT NOT NULL DEFAULT "",
                stream_display_name TEXT NOT NULL DEFAULT "",
                category_id TEXT NOT NULL DEFAULT "",
                target_container TEXT NOT NULL DEFAULT "",
                synced_at TEXT NOT NULL DEFAULT ""
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS xui_activity_now_cache (
                activity_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL DEFAULT 0,
                stream_id INTEGER NOT NULL DEFAULT 0,
                server_id INTEGER NOT NULL DEFAULT 0,
                user_agent TEXT NOT NULL DEFAULT "",
                user_ip TEXT NOT NULL DEFAULT "",
                container TEXT NOT NULL DEFAULT "",
                date_start INTEGER NOT NULL DEFAULT 0,
                date_end INTEGER NOT NULL DEFAULT 0,
                hls_last_read INTEGER NOT NULL DEFAULT 0,
                hls_end INTEGER NOT NULL DEFAULT 0,
                synced_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_act_user ON xui_activity_now_cache(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_act_ip ON xui_activity_now_cache(user_ip)');

        // Log estruturado por request público do proxy (sem senha em claro).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS proxy_request_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL,
                ts TEXT NOT NULL,
                ts_epoch INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                client_ip TEXT NOT NULL DEFAULT "",
                public_host TEXT NOT NULL DEFAULT "",
                method TEXT NOT NULL DEFAULT "GET",
                path TEXT NOT NULL DEFAULT "",
                query_masked TEXT NOT NULL DEFAULT "",
                route_kind TEXT NOT NULL DEFAULT "other",
                username TEXT NOT NULL DEFAULT "",
                credential_fingerprint TEXT NOT NULL DEFAULT "",
                stream_id INTEGER,
                token_id INTEGER,
                origin_id INTEGER,
                status INTEGER NOT NULL DEFAULT 0,
                bytes INTEGER NOT NULL DEFAULT 0,
                user_agent TEXT NOT NULL DEFAULT "",
                referer TEXT NOT NULL DEFAULT "",
                reason TEXT NOT NULL DEFAULT "",
                match_confidence TEXT NOT NULL DEFAULT "pending",
                match_reason TEXT NOT NULL DEFAULT "",
                inconsistency TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pre_reqid ON proxy_request_events(request_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_ts ON proxy_request_events(ts_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_user ON proxy_request_events(username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_ip ON proxy_request_events(client_ip)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_host ON proxy_request_events(public_host)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_kind ON proxy_request_events(route_kind)');

        // Vínculo request público <-> sessão ativa do XUI.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS proxy_session_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL,
                activity_id INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                stream_id INTEGER NOT NULL DEFAULT 0,
                matched_by TEXT NOT NULL DEFAULT "",
                confidence TEXT NOT NULL DEFAULT "low",
                matched_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_psl_req ON proxy_session_links(request_id, activity_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_psl_user ON proxy_session_links(user_id)');

        // Leitura rápida do painel ao vivo (consolidada por job).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS proxy_user_runtime (
                username TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL DEFAULT 0,
                public_host_last_seen TEXT NOT NULL DEFAULT "",
                client_ip_last_seen TEXT NOT NULL DEFAULT "",
                user_agent_last_seen TEXT NOT NULL DEFAULT "",
                active_connections_now INTEGER NOT NULL DEFAULT 0,
                max_connections INTEGER NOT NULL DEFAULT 0,
                last_activity_at TEXT NOT NULL DEFAULT "",
                last_activity_epoch INTEGER NOT NULL DEFAULT 0,
                last_route_kind TEXT NOT NULL DEFAULT "",
                last_stream_id INTEGER NOT NULL DEFAULT 0,
                last_stream_name TEXT NOT NULL DEFAULT "",
                requests_5m INTEGER NOT NULL DEFAULT 0,
                bytes_5m INTEGER NOT NULL DEFAULT 0,
                health_status TEXT NOT NULL DEFAULT "ok",
                updated_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pur_last ON proxy_user_runtime(last_activity_epoch)');

        foreach ([
            'cdn_connections_now' => 'INTEGER NOT NULL DEFAULT 0',
            'xui_connections_now' => 'INTEGER NOT NULL DEFAULT 0',
            'divergence' => 'INTEGER NOT NULL DEFAULT 0',
            'count_source' => 'TEXT NOT NULL DEFAULT "cdn_local"',
            'direct_sessions_now' => 'INTEGER NOT NULL DEFAULT 0',
            'uptime_start_epoch' => 'INTEGER NOT NULL DEFAULT 0',
            'last_lb_label' => 'TEXT NOT NULL DEFAULT "main"',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'proxy_user_runtime', $col, $decl);
        }

        // Auditoria de jobs internos: uma linha por execução.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS job_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_name TEXT NOT NULL,
                run_id TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT "",
                trigger_source TEXT NOT NULL DEFAULT "cron",
                started_at TEXT NOT NULL,
                started_epoch INTEGER NOT NULL DEFAULT 0,
                finished_at TEXT NOT NULL DEFAULT "",
                duration_ms INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "running",
                processed INTEGER NOT NULL DEFAULT 0,
                failed INTEGER NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT "",
                details TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobruns_name ON job_runs(job_name, started_epoch)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_jobruns_runid ON job_runs(run_id)');

        // Estado atual por job (última execução, próxima esperada).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS job_state (
                job_name TEXT PRIMARY KEY,
                purpose TEXT NOT NULL DEFAULT "",
                interval_seconds INTEGER NOT NULL DEFAULT 60,
                last_run_at TEXT NOT NULL DEFAULT "",
                last_run_epoch INTEGER NOT NULL DEFAULT 0,
                last_status TEXT NOT NULL DEFAULT "never",
                last_duration_ms INTEGER NOT NULL DEFAULT 0,
                last_processed INTEGER NOT NULL DEFAULT 0,
                last_failed INTEGER NOT NULL DEFAULT 0,
                last_error TEXT NOT NULL DEFAULT "",
                next_run_epoch INTEGER NOT NULL DEFAULT 0,
                total_runs INTEGER NOT NULL DEFAULT 0,
                total_failures INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT ""
            )'
        );

        self::migrateIntelligence($pdo);
    }

    private static function migratePgsqlHot(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS xui_sync_config (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                host TEXT NOT NULL DEFAULT '',
                port INTEGER NOT NULL DEFAULT 3306,
                database_name TEXT NOT NULL DEFAULT 'xtream_iptvpro',
                username TEXT NOT NULL DEFAULT '',
                password TEXT NOT NULL DEFAULT '',
                api_url TEXT NOT NULL DEFAULT '',
                api_token TEXT NOT NULL DEFAULT '',
                use_tls INTEGER NOT NULL DEFAULT 0,
                sync_enabled INTEGER NOT NULL DEFAULT 0,
                sync_interval_seconds INTEGER NOT NULL DEFAULT 5,
                users_interval_seconds INTEGER NOT NULL DEFAULT 60,
                streams_interval_seconds INTEGER NOT NULL DEFAULT 300,
                connect_timeout_seconds INTEGER NOT NULL DEFAULT 3,
                read_timeout_seconds INTEGER NOT NULL DEFAULT 5,
                last_sync_at TEXT NOT NULL DEFAULT '',
                last_sync_status TEXT NOT NULL DEFAULT 'never',
                last_sync_error TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL DEFAULT ''
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS xui_users_cache (
                user_id BIGINT PRIMARY KEY,
                username TEXT NOT NULL,
                password_masked TEXT NOT NULL DEFAULT '',
                credential_fingerprint TEXT NOT NULL DEFAULT '',
                max_connections INTEGER NOT NULL DEFAULT 0,
                enabled INTEGER NOT NULL DEFAULT 1,
                exp_date TEXT NOT NULL DEFAULT '',
                is_trial INTEGER NOT NULL DEFAULT 0,
                is_restreamer INTEGER NOT NULL DEFAULT 0,
                allowed_ips TEXT NOT NULL DEFAULT '',
                allowed_ua TEXT NOT NULL DEFAULT '',
                synced_at TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_users_username ON xui_users_cache(username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_users_fp ON xui_users_cache(credential_fingerprint)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cdn_user_ip_lock (
                username TEXT PRIMARY KEY,
                allowed_ips TEXT NOT NULL DEFAULT '',
                notes TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL DEFAULT '',
                updated_epoch BIGINT NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cuil_updated ON cdn_user_ip_lock(updated_epoch)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS xui_streams_cache (
                stream_id BIGINT PRIMARY KEY,
                type TEXT NOT NULL DEFAULT '',
                stream_display_name TEXT NOT NULL DEFAULT '',
                category_id TEXT NOT NULL DEFAULT '',
                target_container TEXT NOT NULL DEFAULT '',
                synced_at TEXT NOT NULL DEFAULT ''
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS xui_activity_now_cache (
                activity_id BIGINT PRIMARY KEY,
                user_id BIGINT NOT NULL DEFAULT 0,
                stream_id BIGINT NOT NULL DEFAULT 0,
                server_id BIGINT NOT NULL DEFAULT 0,
                user_agent TEXT NOT NULL DEFAULT '',
                user_ip TEXT NOT NULL DEFAULT '',
                container TEXT NOT NULL DEFAULT '',
                date_start BIGINT NOT NULL DEFAULT 0,
                date_end BIGINT NOT NULL DEFAULT 0,
                hls_last_read BIGINT NOT NULL DEFAULT 0,
                hls_end BIGINT NOT NULL DEFAULT 0,
                synced_at TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_act_user ON xui_activity_now_cache(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xui_act_ip ON xui_activity_now_cache(user_ip)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS proxy_request_events (
                id BIGSERIAL PRIMARY KEY,
                request_id TEXT NOT NULL,
                ts TEXT NOT NULL,
                ts_epoch BIGINT NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                client_ip TEXT NOT NULL DEFAULT \'\',
                public_host TEXT NOT NULL DEFAULT \'\',
                method TEXT NOT NULL DEFAULT \'GET\',
                path TEXT NOT NULL DEFAULT \'\',
                query_masked TEXT NOT NULL DEFAULT \'\',
                route_kind TEXT NOT NULL DEFAULT \'other\',
                username TEXT NOT NULL DEFAULT \'\',
                credential_fingerprint TEXT NOT NULL DEFAULT \'\',
                stream_id BIGINT NULL,
                token_id BIGINT NULL,
                origin_id BIGINT NULL,
                status INTEGER NOT NULL DEFAULT 0,
                bytes BIGINT NOT NULL DEFAULT 0,
                user_agent TEXT NOT NULL DEFAULT \'\',
                referer TEXT NOT NULL DEFAULT \'\',
                reason TEXT NOT NULL DEFAULT \'\',
                match_confidence TEXT NOT NULL DEFAULT \'pending\',
                match_reason TEXT NOT NULL DEFAULT \'\',
                inconsistency TEXT NOT NULL DEFAULT \'\',
                session_key TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pre_reqid ON proxy_request_events(request_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_ts ON proxy_request_events(ts_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_user ON proxy_request_events(username)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_ip ON proxy_request_events(client_ip)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_host ON proxy_request_events(public_host)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_kind ON proxy_request_events(route_kind)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_session ON proxy_request_events(session_key)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS proxy_user_runtime (
                username TEXT PRIMARY KEY,
                user_id BIGINT NOT NULL DEFAULT 0,
                public_host_last_seen TEXT NOT NULL DEFAULT \'\',
                client_ip_last_seen TEXT NOT NULL DEFAULT \'\',
                user_agent_last_seen TEXT NOT NULL DEFAULT \'\',
                active_connections_now INTEGER NOT NULL DEFAULT 0,
                max_connections INTEGER NOT NULL DEFAULT 0,
                last_activity_at TEXT NOT NULL DEFAULT \'\',
                last_activity_epoch BIGINT NOT NULL DEFAULT 0,
                last_route_kind TEXT NOT NULL DEFAULT \'\',
                last_stream_id BIGINT NOT NULL DEFAULT 0,
                last_stream_name TEXT NOT NULL DEFAULT \'\',
                requests_5m BIGINT NOT NULL DEFAULT 0,
                bytes_5m BIGINT NOT NULL DEFAULT 0,
                health_status TEXT NOT NULL DEFAULT \'ok\',
                updated_at TEXT NOT NULL DEFAULT \'\',
                cdn_connections_now INTEGER NOT NULL DEFAULT 0,
                xui_connections_now INTEGER NOT NULL DEFAULT 0,
                divergence INTEGER NOT NULL DEFAULT 0,
                count_source TEXT NOT NULL DEFAULT \'cdn_local\',
                direct_sessions_now INTEGER NOT NULL DEFAULT 0,
                uptime_start_epoch BIGINT NOT NULL DEFAULT 0,
                last_lb_label TEXT NOT NULL DEFAULT \'main\'
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pur_last ON proxy_user_runtime(last_activity_epoch)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS proxy_session_links (
                id BIGSERIAL PRIMARY KEY,
                request_id TEXT NOT NULL,
                activity_id BIGINT NOT NULL DEFAULT 0,
                user_id BIGINT NOT NULL DEFAULT 0,
                stream_id BIGINT NOT NULL DEFAULT 0,
                matched_by TEXT NOT NULL DEFAULT \'\',
                confidence TEXT NOT NULL DEFAULT \'low\',
                matched_at TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_psl_req ON proxy_session_links(request_id, activity_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_psl_user ON proxy_session_links(user_id)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cdn_sessions (
                session_key TEXT PRIMARY KEY,
                username TEXT NOT NULL DEFAULT \'\',
                credential_fingerprint TEXT NOT NULL DEFAULT \'\',
                client_ip TEXT NOT NULL DEFAULT \'\',
                user_agent TEXT NOT NULL DEFAULT \'\',
                public_host TEXT NOT NULL DEFAULT \'\',
                session_kind TEXT NOT NULL DEFAULT \'other\',
                last_route_kind TEXT NOT NULL DEFAULT \'\',
                stream_id BIGINT NOT NULL DEFAULT 0,
                started_at TEXT NOT NULL DEFAULT \'\',
                started_epoch BIGINT NOT NULL DEFAULT 0,
                uptime_start_epoch BIGINT NOT NULL DEFAULT 0,
                last_seen_at TEXT NOT NULL DEFAULT \'\',
                last_seen_epoch BIGINT NOT NULL DEFAULT 0,
                idle_timeout INTEGER NOT NULL DEFAULT 60,
                ended_epoch BIGINT NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT \'active\',
                close_reason TEXT NOT NULL DEFAULT \'\',
                requests BIGINT NOT NULL DEFAULT 0,
                bytes BIGINT NOT NULL DEFAULT 0,
                errors BIGINT NOT NULL DEFAULT 0,
                active_requests INTEGER NOT NULL DEFAULT 0,
                last_open_epoch BIGINT NOT NULL DEFAULT 0,
                last_close_epoch BIGINT NOT NULL DEFAULT 0,
                direct_source INTEGER NOT NULL DEFAULT 0,
                direct_host TEXT NOT NULL DEFAULT \'\',
                xui_activity_id BIGINT NOT NULL DEFAULT 0,
                match_confidence TEXT NOT NULL DEFAULT \'pending\',
                match_reason TEXT NOT NULL DEFAULT \'\',
                last_request_id TEXT NOT NULL DEFAULT \'\',
                direct_mode TEXT NOT NULL DEFAULT \'\',
                direct_host_db TEXT NOT NULL DEFAULT \'\',
                direct_host_runtime TEXT NOT NULL DEFAULT \'\',
                direct_host_effective TEXT NOT NULL DEFAULT \'\',
                direct_first_epoch BIGINT NOT NULL DEFAULT 0,
                direct_last_epoch BIGINT NOT NULL DEFAULT 0,
                direct_failures INTEGER NOT NULL DEFAULT 0,
                direct_blocked INTEGER NOT NULL DEFAULT 0,
                lb_id BIGINT NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_user ON cdn_sessions(username, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_seen ON cdn_sessions(last_seen_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_status ON cdn_sessions(status, last_seen_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_direct ON cdn_sessions(direct_source, status)');

        // S2-P0-4 — paridade da TRILHA QUENTE.
        //
        // Antes daqui o espelho Postgres só tinha 4 tabelas; métricas,
        // divergência, limite, hops, timeline e jobs ficavam de fora e a
        // migração morreria no primeiro rollup. Nada de `DEFAULT ""`
        // (no Postgres aspas duplas são IDENTIFICADOR) nem AUTOINCREMENT.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cdn_metrics (
                id BIGSERIAL PRIMARY KEY,
                metric TEXT NOT NULL,
                value BIGINT NOT NULL DEFAULT 0,
                ts_epoch BIGINT NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_metrics_key ON cdn_metrics(metric, ts_epoch)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cdn_divergences (
                id BIGSERIAL PRIMARY KEY,
                username TEXT NOT NULL DEFAULT '',
                kind TEXT NOT NULL DEFAULT 'count_mismatch',
                severity TEXT NOT NULL DEFAULT 'warn',
                cdn_count INTEGER NOT NULL DEFAULT 0,
                xui_count INTEGER NOT NULL DEFAULT 0,
                max_connections INTEGER NOT NULL DEFAULT 0,
                probable_cause TEXT NOT NULL DEFAULT '',
                detail TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'open',
                opened_at TEXT NOT NULL DEFAULT '',
                opened_epoch BIGINT NOT NULL DEFAULT 0,
                last_seen_epoch BIGINT NOT NULL DEFAULT 0,
                occurrences BIGINT NOT NULL DEFAULT 1,
                closed_epoch BIGINT NOT NULL DEFAULT 0,
                stream_id BIGINT NOT NULL DEFAULT 0,
                scope TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_div_seen ON cdn_divergences(last_seen_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_div_user ON cdn_divergences(username, status)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_limit_state (
                username TEXT PRIMARY KEY,
                over_limit_since_epoch BIGINT NOT NULL DEFAULT 0,
                updated_epoch BIGINT NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_userlimit_updated ON user_limit_state(updated_epoch)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS direct_source_hops (
                id BIGSERIAL PRIMARY KEY,
                request_id TEXT NOT NULL,
                session_key TEXT NOT NULL DEFAULT '',
                username TEXT NOT NULL DEFAULT '',
                hop_no INTEGER NOT NULL DEFAULT 0,
                from_host TEXT NOT NULL DEFAULT '',
                to_host TEXT NOT NULL DEFAULT '',
                off_origin INTEGER NOT NULL DEFAULT 0,
                outcome TEXT NOT NULL DEFAULT 'followed',
                status INTEGER NOT NULL DEFAULT 0,
                ts TEXT NOT NULL DEFAULT '',
                ts_epoch BIGINT NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_req ON direct_source_hops(request_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_ts ON direct_source_hops(ts_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_user ON direct_source_hops(username)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cdn_audit_timeline (
                session_key TEXT PRIMARY KEY,
                username TEXT NOT NULL DEFAULT '',
                credential_fingerprint TEXT NOT NULL DEFAULT '',
                client_ip TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT '',
                public_host TEXT NOT NULL DEFAULT '',
                session_kind TEXT NOT NULL DEFAULT '',
                stream_id BIGINT NOT NULL DEFAULT 0,
                origin_id BIGINT NOT NULL DEFAULT 0,
                lb_id BIGINT NOT NULL DEFAULT 0,
                lb_target TEXT NOT NULL DEFAULT 'main',
                lb_reason TEXT NOT NULL DEFAULT '',
                direct_source INTEGER NOT NULL DEFAULT 0,
                direct_host TEXT NOT NULL DEFAULT '',
                first_request_id TEXT NOT NULL DEFAULT '',
                last_request_id TEXT NOT NULL DEFAULT '',
                last_path TEXT NOT NULL DEFAULT '',
                last_status INTEGER NOT NULL DEFAULT 0,
                last_reason TEXT NOT NULL DEFAULT '',
                inconsistency TEXT NOT NULL DEFAULT '',
                requests BIGINT NOT NULL DEFAULT 0,
                errors BIGINT NOT NULL DEFAULT 0,
                bytes BIGINT NOT NULL DEFAULT 0,
                hops BIGINT NOT NULL DEFAULT 0,
                started_epoch BIGINT NOT NULL DEFAULT 0,
                last_epoch BIGINT NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_last ON cdn_audit_timeline(last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_user ON cdn_audit_timeline(username, last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_ip ON cdn_audit_timeline(client_ip, last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_host ON cdn_audit_timeline(public_host, last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_problem ON cdn_audit_timeline(inconsistency, last_epoch)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS job_runs (
                id BIGSERIAL PRIMARY KEY,
                job_name TEXT NOT NULL,
                run_id TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT '',
                trigger_source TEXT NOT NULL DEFAULT 'cron',
                started_at TEXT NOT NULL,
                started_epoch BIGINT NOT NULL DEFAULT 0,
                finished_at TEXT NOT NULL DEFAULT '',
                duration_ms BIGINT NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'running',
                processed BIGINT NOT NULL DEFAULT 0,
                failed BIGINT NOT NULL DEFAULT 0,
                error TEXT NOT NULL DEFAULT '',
                details TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobruns_name ON job_runs(job_name, started_epoch)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_jobruns_runid ON job_runs(run_id)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS job_state (
                job_name TEXT PRIMARY KEY,
                purpose TEXT NOT NULL DEFAULT '',
                interval_seconds INTEGER NOT NULL DEFAULT 60,
                last_run_at TEXT NOT NULL DEFAULT '',
                last_run_epoch BIGINT NOT NULL DEFAULT 0,
                last_status TEXT NOT NULL DEFAULT 'never',
                last_duration_ms BIGINT NOT NULL DEFAULT 0,
                last_processed BIGINT NOT NULL DEFAULT 0,
                last_failed BIGINT NOT NULL DEFAULT 0,
                last_error TEXT NOT NULL DEFAULT '',
                next_run_epoch BIGINT NOT NULL DEFAULT 0,
                total_runs BIGINT NOT NULL DEFAULT 0,
                total_failures BIGINT NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT '',
                running INTEGER NOT NULL DEFAULT 0,
                running_since_epoch BIGINT NOT NULL DEFAULT 0,
                consecutive_failures INTEGER NOT NULL DEFAULT 0,
                disabled_until_epoch BIGINT NOT NULL DEFAULT 0,
                profile TEXT NOT NULL DEFAULT 'fast'
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS job_step_history (
                id BIGSERIAL PRIMARY KEY,
                run_id TEXT NOT NULL,
                job_name TEXT NOT NULL,
                seq INTEGER NOT NULL DEFAULT 0,
                step TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'ok',
                message TEXT NOT NULL DEFAULT '',
                duration_ms BIGINT NOT NULL DEFAULT 0,
                ts_epoch BIGINT NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jsh_run ON job_step_history(run_id, seq)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jsh_job ON job_step_history(job_name, ts_epoch)');

        // S2-P0-5 — drift de colunas encontrado no ENSAIO DE CORTE.
        //
        // No SQLite estas colunas nasceram por ALTER TABLE ao longo das fases
        // (rastreio de direct source, jobs por etapa, roteamento por LB). O
        // espelho pgsql só tinha o CREATE TABLE original, então um corte real
        // teria perdido justamente a trilha de rastreabilidade. Declaração com
        // aspas SIMPLES: no Postgres, DEFAULT "" é identificador, não string.
        $drift = [
            'xui_streams_cache' => [
                'direct_source' => 'INTEGER NOT NULL DEFAULT 0',
                'direct_proxy' => 'INTEGER NOT NULL DEFAULT 0',
                'stream_source_raw' => "TEXT NOT NULL DEFAULT ''",
                'direct_host_detected' => "TEXT NOT NULL DEFAULT ''",
                'direct_hosts_json' => "TEXT NOT NULL DEFAULT '[]'",
                'urls_count' => 'INTEGER NOT NULL DEFAULT 0',
                'source_mode' => "TEXT NOT NULL DEFAULT 'unknown'",
                'parse_status' => "TEXT NOT NULL DEFAULT 'pending'",
                'parse_error' => "TEXT NOT NULL DEFAULT ''",
                'enriched_epoch' => 'BIGINT NOT NULL DEFAULT 0',
            ],
            'proxy_request_events' => [
                'direct_host' => "TEXT NOT NULL DEFAULT ''",
                'hops' => 'INTEGER NOT NULL DEFAULT 0',
                'direct_mode' => "TEXT NOT NULL DEFAULT ''",
                'direct_host_db' => "TEXT NOT NULL DEFAULT ''",
                'lb_id' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'direct_source_hops' => [
                'stream_id' => 'INTEGER NOT NULL DEFAULT 0',
                'public_host' => "TEXT NOT NULL DEFAULT ''",
                'client_ip' => "TEXT NOT NULL DEFAULT ''",
                'player' => "TEXT NOT NULL DEFAULT ''",
                'route_kind' => "TEXT NOT NULL DEFAULT ''",
                'final_host' => "TEXT NOT NULL DEFAULT ''",
                'direct_mode' => "TEXT NOT NULL DEFAULT 'runtime'",
                'host_from_db' => "TEXT NOT NULL DEFAULT ''",
                'error' => "TEXT NOT NULL DEFAULT ''",
            ],
            'job_runs' => [
                'last_step' => "TEXT NOT NULL DEFAULT ''",
                'steps_done' => 'INTEGER NOT NULL DEFAULT 0',
                'lock_retries' => 'INTEGER NOT NULL DEFAULT 0',
                'host' => "TEXT NOT NULL DEFAULT ''",
            ],
            'job_state' => [
                'current_step' => "TEXT NOT NULL DEFAULT ''",
                'last_run_id' => "TEXT NOT NULL DEFAULT ''",
                'circuit_open_until' => 'BIGINT NOT NULL DEFAULT 0',
                'circuit_reason' => "TEXT NOT NULL DEFAULT ''",
                'skipped_runs' => 'INTEGER NOT NULL DEFAULT 0',
                'max_duration_ms' => 'BIGINT NOT NULL DEFAULT 0',
            ],
        ];
        foreach ($drift as $table => $columns) {
            foreach ($columns as $column => $decl) {
                self::addColumnIfMissing($pdo, $table, $column, $decl);
            }
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS direct_stream_state (
                stream_id BIGINT PRIMARY KEY,
                stream_name TEXT NOT NULL DEFAULT '',
                stream_type TEXT NOT NULL DEFAULT '',
                direct_flag_db INTEGER NOT NULL DEFAULT 0,
                direct_proxy INTEGER NOT NULL DEFAULT 0,
                direct_host_from_db TEXT NOT NULL DEFAULT '',
                direct_host_runtime TEXT NOT NULL DEFAULT '',
                direct_host_effective TEXT NOT NULL DEFAULT '',
                direct_origin_mode TEXT NOT NULL DEFAULT 'none',
                direct_consistency TEXT NOT NULL DEFAULT 'unknown',
                parse_status TEXT NOT NULL DEFAULT 'pending',
                urls_count INTEGER NOT NULL DEFAULT 0,
                runtime_hits INTEGER NOT NULL DEFAULT 0,
                runtime_failures INTEGER NOT NULL DEFAULT 0,
                runtime_last_epoch BIGINT NOT NULL DEFAULT 0,
                db_synced_epoch BIGINT NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT '',
                updated_epoch BIGINT NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_mode ON direct_stream_state(direct_origin_mode)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_cons ON direct_stream_state(direct_consistency)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_host ON direct_stream_state(direct_host_effective)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_mode_cons ON direct_stream_state(direct_origin_mode, direct_consistency)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS lb_route_history (
                id BIGSERIAL PRIMARY KEY,
                username TEXT NOT NULL,
                from_lb_id BIGINT NOT NULL DEFAULT 0,
                to_lb_id BIGINT NOT NULL DEFAULT 0,
                mode TEXT NOT NULL DEFAULT '',
                reason TEXT NOT NULL DEFAULT '',
                score DOUBLE PRECISION NOT NULL DEFAULT 0,
                trigger_source TEXT NOT NULL DEFAULT '',
                ts_epoch BIGINT NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lbrh_user ON lb_route_history(username, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lbrh_ts ON lb_route_history(ts_epoch)');
    }

    /**
     * Fase CDN Inteligente: sessão lógica local, contador próprio de conexões,
     * rastreio profundo de direct source, divergências e KPIs.
     */
    private static function migrateIntelligence(PDO $pdo): void
    {
        // Sessão LÓGICA da própria CDN (não é request, é conexão).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cdn_sessions (
                session_key TEXT PRIMARY KEY,
                username TEXT NOT NULL DEFAULT "",
                credential_fingerprint TEXT NOT NULL DEFAULT "",
                client_ip TEXT NOT NULL DEFAULT "",
                user_agent TEXT NOT NULL DEFAULT "",
                public_host TEXT NOT NULL DEFAULT "",
                session_kind TEXT NOT NULL DEFAULT "other",
                last_route_kind TEXT NOT NULL DEFAULT "",
                stream_id INTEGER NOT NULL DEFAULT 0,
                started_at TEXT NOT NULL DEFAULT "",
                started_epoch INTEGER NOT NULL DEFAULT 0,
                uptime_start_epoch INTEGER NOT NULL DEFAULT 0,
                last_seen_at TEXT NOT NULL DEFAULT "",
                last_seen_epoch INTEGER NOT NULL DEFAULT 0,
                idle_timeout INTEGER NOT NULL DEFAULT 60,
                ended_epoch INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "active",
                close_reason TEXT NOT NULL DEFAULT "",
                requests INTEGER NOT NULL DEFAULT 0,
                bytes INTEGER NOT NULL DEFAULT 0,
                errors INTEGER NOT NULL DEFAULT 0,
                active_requests INTEGER NOT NULL DEFAULT 0,
                last_open_epoch INTEGER NOT NULL DEFAULT 0,
                last_close_epoch INTEGER NOT NULL DEFAULT 0,
                direct_source INTEGER NOT NULL DEFAULT 0,
                direct_host TEXT NOT NULL DEFAULT "",
                xui_activity_id INTEGER NOT NULL DEFAULT 0,
                match_confidence TEXT NOT NULL DEFAULT "pending",
                match_reason TEXT NOT NULL DEFAULT "",
                last_request_id TEXT NOT NULL DEFAULT ""
            )'
        );
        foreach ([
            'active_requests' => 'INTEGER NOT NULL DEFAULT 0',
            'last_open_epoch' => 'INTEGER NOT NULL DEFAULT 0',
            'last_close_epoch' => 'INTEGER NOT NULL DEFAULT 0',
            'uptime_start_epoch' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'cdn_sessions', $col, $decl);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_user ON cdn_sessions(username, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_seen ON cdn_sessions(last_seen_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_status ON cdn_sessions(status, last_seen_epoch)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_limit_state (
                username TEXT PRIMARY KEY,
                over_limit_since_epoch INTEGER NOT NULL DEFAULT 0,
                updated_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_userlimit_updated ON user_limit_state(updated_epoch)');

        // Cada hop seguido pelo proxy num consumo "direct source".
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS direct_source_hops (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL,
                session_key TEXT NOT NULL DEFAULT "",
                username TEXT NOT NULL DEFAULT "",
                hop_no INTEGER NOT NULL DEFAULT 0,
                from_host TEXT NOT NULL DEFAULT "",
                to_host TEXT NOT NULL DEFAULT "",
                off_origin INTEGER NOT NULL DEFAULT 0,
                outcome TEXT NOT NULL DEFAULT "followed",
                status INTEGER NOT NULL DEFAULT 0,
                ts TEXT NOT NULL DEFAULT "",
                ts_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_req ON direct_source_hops(request_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_ts ON direct_source_hops(ts_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_user ON direct_source_hops(username)');

        // Divergência aberta entre o contador da CDN e o do XUI (ou limite).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cdn_divergences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL DEFAULT "",
                kind TEXT NOT NULL DEFAULT "count_mismatch",
                severity TEXT NOT NULL DEFAULT "warn",
                cdn_count INTEGER NOT NULL DEFAULT 0,
                xui_count INTEGER NOT NULL DEFAULT 0,
                max_connections INTEGER NOT NULL DEFAULT 0,
                probable_cause TEXT NOT NULL DEFAULT "",
                detail TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "open",
                opened_at TEXT NOT NULL DEFAULT "",
                opened_epoch INTEGER NOT NULL DEFAULT 0,
                last_seen_epoch INTEGER NOT NULL DEFAULT 0,
                occurrences INTEGER NOT NULL DEFAULT 1,
                closed_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        // O índice único antigo (username,kind,status) quebra quando já existem
        // divergências múltiplas por stream/escopo. A fase direct source passa a
        // usar idx_div_open_scope e esse formato antigo não deve mais ser criado.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_div_seen ON cdn_divergences(last_seen_epoch)');

        // Série curta de KPIs (picos, saúde) gravada pelo job metrics_rollup.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cdn_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                metric TEXT NOT NULL,
                value INTEGER NOT NULL DEFAULT 0,
                ts_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_metrics_key ON cdn_metrics(metric, ts_epoch)');

        foreach ([
            'session_key' => 'TEXT NOT NULL DEFAULT ""',
            'direct_host' => 'TEXT NOT NULL DEFAULT ""',
            'hops' => 'INTEGER NOT NULL DEFAULT 0',
            'match_reason' => 'TEXT NOT NULL DEFAULT ""',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'proxy_request_events', $col, $decl);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pre_session ON proxy_request_events(session_key)');

        self::migrateDirectSource($pdo);
    }

    /**
     * Fase LB (cérebro + músculos).
     *
     * O cérebro (VPS 45.140.192.237) mantém o inventário dos LBs, o log de
     * instalação remota, a telemetria e a rota por usuário do XUI. Nenhuma
     * tabela existente é alterada — o upgrade é puramente aditivo.
     */
    private static function migrateLb(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lb_nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                label TEXT NOT NULL DEFAULT "",
                public_ip TEXT NOT NULL DEFAULT "",
                ssh_host TEXT NOT NULL DEFAULT "",
                ssh_port INTEGER NOT NULL DEFAULT 22,
                ssh_user TEXT NOT NULL DEFAULT "root",
                ssh_password_enc TEXT NOT NULL DEFAULT "",
                auth_mode TEXT NOT NULL DEFAULT "password",
                key_installed INTEGER NOT NULL DEFAULT 0,
                key_fingerprint TEXT NOT NULL DEFAULT "",
                key_promoted_at TEXT NOT NULL DEFAULT "",
                password_bootstrap_done INTEGER NOT NULL DEFAULT 0,
                auto_install INTEGER NOT NULL DEFAULT 1,
                os_name TEXT NOT NULL DEFAULT "",
                os_version TEXT NOT NULL DEFAULT "",
                cpu_cores INTEGER NOT NULL DEFAULT 0,
                ram_mb INTEGER NOT NULL DEFAULT 0,
                disk_total_gb INTEGER NOT NULL DEFAULT 0,
                disk_free_gb INTEGER NOT NULL DEFAULT 0,
                profile TEXT NOT NULL DEFAULT "",
                declared_bandwidth_mbps INTEGER NOT NULL DEFAULT 0,
                measured_bandwidth_mbps INTEGER NOT NULL DEFAULT 0,
                health_status TEXT NOT NULL DEFAULT "unknown",
                health_message TEXT NOT NULL DEFAULT "",
                install_status TEXT NOT NULL DEFAULT "pending",
                install_step TEXT NOT NULL DEFAULT "",
                install_run_id TEXT NOT NULL DEFAULT "",
                agent_token TEXT NOT NULL DEFAULT "",
                enabled INTEGER NOT NULL DEFAULT 1,
                drain_mode INTEGER NOT NULL DEFAULT 0,
                weight INTEGER NOT NULL DEFAULT 100,
                max_users_soft INTEGER NOT NULL DEFAULT 0,
                max_users_hard INTEGER NOT NULL DEFAULT 0,
                max_mbps_soft INTEGER NOT NULL DEFAULT 0,
                max_mbps_hard INTEGER NOT NULL DEFAULT 0,
                last_seen_epoch INTEGER NOT NULL DEFAULT 0,
                last_probe_epoch INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT "",
                updated_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_lb_ip ON lb_nodes(public_ip)');
        self::addColumnIfMissing($pdo, 'lb_nodes', 'auth_mode', 'TEXT NOT NULL DEFAULT "password"');
        self::addColumnIfMissing($pdo, 'lb_nodes', 'key_installed', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'lb_nodes', 'key_fingerprint', 'TEXT NOT NULL DEFAULT ""');
        self::addColumnIfMissing($pdo, 'lb_nodes', 'key_promoted_at', 'TEXT NOT NULL DEFAULT ""');
        self::addColumnIfMissing($pdo, 'lb_nodes', 'password_bootstrap_done', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'lb_nodes', 'auto_install', 'INTEGER NOT NULL DEFAULT 1');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lb_installs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lb_id INTEGER NOT NULL,
                run_id TEXT NOT NULL,
                seq INTEGER NOT NULL DEFAULT 0,
                step TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "running",
                message TEXT NOT NULL DEFAULT "",
                ts_epoch INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lbi_run ON lb_installs(lb_id, run_id, seq)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lb_user_routes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                lb_id INTEGER NOT NULL DEFAULT 0,
                mode TEXT NOT NULL DEFAULT "auto",
                reason TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL DEFAULT "",
                updated_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_lbroute_user ON lb_user_routes(username)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lb_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lb_id INTEGER NOT NULL,
                ts_epoch INTEGER NOT NULL DEFAULT 0,
                cpu_pct REAL NOT NULL DEFAULT 0,
                ram_used_mb INTEGER NOT NULL DEFAULT 0,
                ram_free_mb INTEGER NOT NULL DEFAULT 0,
                disk_free_gb INTEGER NOT NULL DEFAULT 0,
                rx_mbps REAL NOT NULL DEFAULT 0,
                tx_mbps REAL NOT NULL DEFAULT 0,
                sessions_active INTEGER NOT NULL DEFAULT 0,
                users_active INTEGER NOT NULL DEFAULT 0,
                errors_5m INTEGER NOT NULL DEFAULT 0,
                source TEXT NOT NULL DEFAULT "probe"
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lbm_node ON lb_metrics(lb_id, ts_epoch)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lb_sync_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lb_id INTEGER NOT NULL,
                event_type TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "",
                payload_json TEXT NOT NULL DEFAULT "{}",
                created_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lbse_node ON lb_sync_events(lb_id, id)');

        // Rastreabilidade: todo request e sessão sabem por qual músculo passou.
        self::addColumnIfMissing($pdo, 'proxy_request_events', 'lb_id', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'cdn_sessions', 'lb_id', 'INTEGER NOT NULL DEFAULT 0');
    }

    /**
     * Fase DIRECT SOURCE PERFEITO (VPS 45.140.192.237 — /opt/proxy-mago/proxy-mago-base).
     *
     * O XUI real deste projeto (banco `xui` em 38.190.176.170) marca boa parte
     * do conteúdo como `streams.direct_source = 1` e já guarda a URL externa em
     * `streams.stream_source`. Ou seja: existe direct source que NUNCA gera
     * redirect em runtime. A CDN precisa das duas verdades — DB e runtime — e
     * de uma verdade efetiva consolidada, que é a que o painel mostra.
     */
    private static function migrateDirectSource(PDO $pdo): void
    {
        // 1) Espelho de streams enriquecido com a verdade do DB do XUI.
        foreach ([
            'direct_source' => 'INTEGER NOT NULL DEFAULT 0',
            'direct_proxy' => 'INTEGER NOT NULL DEFAULT 0',
            'stream_source_raw' => 'TEXT NOT NULL DEFAULT ""',
            'direct_host_detected' => 'TEXT NOT NULL DEFAULT ""',
            'direct_hosts_json' => 'TEXT NOT NULL DEFAULT "[]"',
            'urls_count' => 'INTEGER NOT NULL DEFAULT 0',
            'source_mode' => 'TEXT NOT NULL DEFAULT "unknown"',
            'parse_status' => 'TEXT NOT NULL DEFAULT "pending"',
            'parse_error' => 'TEXT NOT NULL DEFAULT ""',
            'enriched_epoch' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'xui_streams_cache', $col, $decl);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xsc_direct ON xui_streams_cache(direct_source)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xsc_host ON xui_streams_cache(direct_host_detected)');
        // Cobre o resumo direct source sem tocar na tabela (322k+ linhas).
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xsc_direct_parse ON xui_streams_cache(direct_source, parse_status)');

        // 2) Verdade consolidada por stream: DB + runtime + efetivo.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS direct_stream_state (
                stream_id INTEGER PRIMARY KEY,
                stream_name TEXT NOT NULL DEFAULT "",
                stream_type TEXT NOT NULL DEFAULT "",
                direct_flag_db INTEGER NOT NULL DEFAULT 0,
                direct_proxy INTEGER NOT NULL DEFAULT 0,
                direct_host_from_db TEXT NOT NULL DEFAULT "",
                direct_host_runtime TEXT NOT NULL DEFAULT "",
                direct_host_effective TEXT NOT NULL DEFAULT "",
                direct_origin_mode TEXT NOT NULL DEFAULT "none",
                direct_consistency TEXT NOT NULL DEFAULT "unknown",
                parse_status TEXT NOT NULL DEFAULT "pending",
                urls_count INTEGER NOT NULL DEFAULT 0,
                runtime_hits INTEGER NOT NULL DEFAULT 0,
                runtime_failures INTEGER NOT NULL DEFAULT 0,
                runtime_last_epoch INTEGER NOT NULL DEFAULT 0,
                db_synced_epoch INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT "",
                updated_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_mode ON direct_stream_state(direct_origin_mode)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_cons ON direct_stream_state(direct_consistency)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_host ON direct_stream_state(direct_host_effective)');
        // Índice de cobertura do resumo do painel (modo + consistência):
        // evita varrer as ~484k linhas de catálogo a cada leitura.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dss_mode_cons ON direct_stream_state(direct_origin_mode, direct_consistency)');

        // 3) Hops com contexto operacional completo (quem, de onde, por qual player).
        foreach ([
            'stream_id' => 'INTEGER NOT NULL DEFAULT 0',
            'public_host' => 'TEXT NOT NULL DEFAULT ""',
            'client_ip' => 'TEXT NOT NULL DEFAULT ""',
            'player' => 'TEXT NOT NULL DEFAULT ""',
            'route_kind' => 'TEXT NOT NULL DEFAULT ""',
            'final_host' => 'TEXT NOT NULL DEFAULT ""',
            'direct_mode' => 'TEXT NOT NULL DEFAULT "runtime"',
            'host_from_db' => 'TEXT NOT NULL DEFAULT ""',
            'error' => 'TEXT NOT NULL DEFAULT ""',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'direct_source_hops', $col, $decl);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_final ON direct_source_hops(final_host, ts_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dsh_stream ON direct_source_hops(stream_id, ts_epoch)');

        // 4) Sessões locais sabem se são direct, por qual modo e desde quando.
        foreach ([
            'direct_mode' => 'TEXT NOT NULL DEFAULT ""',
            'direct_host_db' => 'TEXT NOT NULL DEFAULT ""',
            'direct_host_runtime' => 'TEXT NOT NULL DEFAULT ""',
            'direct_host_effective' => 'TEXT NOT NULL DEFAULT ""',
            'direct_first_epoch' => 'INTEGER NOT NULL DEFAULT 0',
            'direct_last_epoch' => 'INTEGER NOT NULL DEFAULT 0',
            'direct_failures' => 'INTEGER NOT NULL DEFAULT 0',
            'direct_blocked' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'cdn_sessions', $col, $decl);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cdnsess_direct ON cdn_sessions(direct_source, status)');

        // 5) Eventos por request também guardam o modo do direct.
        foreach ([
            'direct_mode' => 'TEXT NOT NULL DEFAULT ""',
            'direct_host_db' => 'TEXT NOT NULL DEFAULT ""',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'proxy_request_events', $col, $decl);
        }

        // 6) Rollup por host final (5 em 5 minutos) — KPI de direct por host.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS direct_host_rollup (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                host TEXT NOT NULL,
                bucket_epoch INTEGER NOT NULL,
                direct_mode TEXT NOT NULL DEFAULT "runtime",
                hits INTEGER NOT NULL DEFAULT 0,
                failures INTEGER NOT NULL DEFAULT 0,
                users INTEGER NOT NULL DEFAULT 0,
                streams INTEGER NOT NULL DEFAULT 0,
                updated_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_dhr_key ON direct_host_rollup(host, bucket_epoch, direct_mode)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_dhr_bucket ON direct_host_rollup(bucket_epoch)');

        // 7) Divergências ganham escopo (usuário OU stream) sem perder o histórico.
        self::addColumnIfMissing($pdo, 'cdn_divergences', 'scope', 'TEXT NOT NULL DEFAULT ""');
        self::addColumnIfMissing($pdo, 'cdn_divergences', 'stream_id', 'INTEGER NOT NULL DEFAULT 0');
        // Em produção real desta VPS, múltiplos jobs e estados parcialmente
        // migrados já causaram conflitos espúrios com índice único em SQLite.
        // A deduplicação passa a ser responsabilidade do código (Divergence::raise),
        // e mantemos só um índice de busca para não travar o runtime.
        $pdo->exec('DROP INDEX IF EXISTS idx_div_open');
        $pdo->exec('DROP INDEX IF EXISTS idx_div_open_scope');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_div_scope ON cdn_divergences(username, kind, scope, status)');

        self::migrateLb($pdo);
        self::migrateAppCode($pdo);
        self::migrateTraceability($pdo);
    }

    /**
     * Fase RASTREABILIDADE TOTAL (VPS 45.140.192.237 — /opt/proxy-mago/proxy-mago-base).
     *
     *  - job_state/job_runs ganham passo atual, falhas consecutivas, lock de
     *    execução e disjuntor (circuit breaker);
     *  - job_step_history: cada etapa interna de um job é auditável;
     *  - cdn_audit_timeline: UMA linha por sessão lógica com a trilha completa
     *    (quem, de onde, por qual host público, por qual LB, direct source,
     *     divergência, bytes, erros) — é a "linha do tempo" do painel;
     *  - lb_route_history: toda troca de músculo por usuário fica registrada.
     */
    private static function migrateTraceability(PDO $pdo): void
    {
        foreach ([
            'current_step' => 'TEXT NOT NULL DEFAULT ""',
            'consecutive_failures' => 'INTEGER NOT NULL DEFAULT 0',
            'last_run_id' => 'TEXT NOT NULL DEFAULT ""',
            'running' => 'INTEGER NOT NULL DEFAULT 0',
            'running_since_epoch' => 'INTEGER NOT NULL DEFAULT 0',
            'circuit_open_until' => 'INTEGER NOT NULL DEFAULT 0',
            'circuit_reason' => 'TEXT NOT NULL DEFAULT ""',
            'skipped_runs' => 'INTEGER NOT NULL DEFAULT 0',
            'max_duration_ms' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'job_state', $col, $decl);
        }
        foreach ([
            'last_step' => 'TEXT NOT NULL DEFAULT ""',
            'steps_done' => 'INTEGER NOT NULL DEFAULT 0',
            'lock_retries' => 'INTEGER NOT NULL DEFAULT 0',
            'host' => 'TEXT NOT NULL DEFAULT ""',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'job_runs', $col, $decl);
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS job_step_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                job_name TEXT NOT NULL,
                seq INTEGER NOT NULL DEFAULT 0,
                step TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "ok",
                message TEXT NOT NULL DEFAULT "",
                duration_ms INTEGER NOT NULL DEFAULT 0,
                ts_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jsh_run ON job_step_history(run_id, seq)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jsh_job ON job_step_history(job_name, ts_epoch)');

        // Trilha única e consolidada por sessão lógica (o "quem fez o quê").
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cdn_audit_timeline (
                session_key TEXT PRIMARY KEY,
                username TEXT NOT NULL DEFAULT "",
                credential_fingerprint TEXT NOT NULL DEFAULT "",
                client_ip TEXT NOT NULL DEFAULT "",
                user_agent TEXT NOT NULL DEFAULT "",
                public_host TEXT NOT NULL DEFAULT "",
                session_kind TEXT NOT NULL DEFAULT "",
                stream_id INTEGER NOT NULL DEFAULT 0,
                origin_id INTEGER NOT NULL DEFAULT 0,
                lb_id INTEGER NOT NULL DEFAULT 0,
                lb_target TEXT NOT NULL DEFAULT "main",
                lb_reason TEXT NOT NULL DEFAULT "",
                direct_source INTEGER NOT NULL DEFAULT 0,
                direct_host TEXT NOT NULL DEFAULT "",
                first_request_id TEXT NOT NULL DEFAULT "",
                last_request_id TEXT NOT NULL DEFAULT "",
                last_path TEXT NOT NULL DEFAULT "",
                last_status INTEGER NOT NULL DEFAULT 0,
                last_reason TEXT NOT NULL DEFAULT "",
                inconsistency TEXT NOT NULL DEFAULT "",
                requests INTEGER NOT NULL DEFAULT 0,
                errors INTEGER NOT NULL DEFAULT 0,
                bytes INTEGER NOT NULL DEFAULT 0,
                hops INTEGER NOT NULL DEFAULT 0,
                started_epoch INTEGER NOT NULL DEFAULT 0,
                last_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_last ON cdn_audit_timeline(last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_user ON cdn_audit_timeline(username, last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_ip ON cdn_audit_timeline(client_ip, last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_host ON cdn_audit_timeline(public_host, last_epoch)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tl_problem ON cdn_audit_timeline(inconsistency, last_epoch)');

        // Roteamento por usuário: histórico + snapshot da decisão.
        foreach ([
            'last_lb_id' => 'INTEGER NOT NULL DEFAULT 0',
            'score_snapshot' => 'REAL NOT NULL DEFAULT 0',
            'fallback_used' => 'INTEGER NOT NULL DEFAULT 0',
            'changed_epoch' => 'INTEGER NOT NULL DEFAULT 0',
            'changes' => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $decl) {
            self::addColumnIfMissing($pdo, 'lb_user_routes', $col, $decl);
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lb_route_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                from_lb_id INTEGER NOT NULL DEFAULT 0,
                to_lb_id INTEGER NOT NULL DEFAULT 0,
                mode TEXT NOT NULL DEFAULT "",
                reason TEXT NOT NULL DEFAULT "",
                score REAL NOT NULL DEFAULT 0,
                trigger_source TEXT NOT NULL DEFAULT "",
                ts_epoch INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lbrh_user ON lb_route_history(username, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lbrh_ts ON lb_route_history(ts_epoch)');
    }

    /**
     * Legado experimental de multi-XUI.
     *
     * O projeto atual opera em modo single-XUI. Mantemos estas tabelas apenas
     * por compatibilidade com bases antigas, sem fazer delas parte da
     * arquitetura ativa de produção.
     */
    private static function migrateAppCode(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_servers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL DEFAULT "",
                host TEXT NOT NULL,
                port INTEGER NOT NULL DEFAULT 0,
                scheme TEXT NOT NULL DEFAULT "http",
                host_header TEXT NOT NULL DEFAULT "",
                extra_hosts TEXT NOT NULL DEFAULT "",
                priority INTEGER NOT NULL DEFAULT 100,
                active INTEGER NOT NULL DEFAULT 1,
                notes TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL DEFAULT "",
                updated_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_app_servers_active ON app_servers(active, priority)');

        // Rota grudada: a garantia anti-embaralhamento.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_user_routes (
                username TEXT PRIMARY KEY,
                server_id INTEGER NOT NULL,
                scheme TEXT NOT NULL DEFAULT "http",
                host TEXT NOT NULL DEFAULT "",
                port INTEGER NOT NULL DEFAULT 80,
                status TEXT NOT NULL DEFAULT "ok",
                hits INTEGER NOT NULL DEFAULT 0,
                failures INTEGER NOT NULL DEFAULT 0,
                discovered_epoch INTEGER NOT NULL DEFAULT 0,
                last_epoch INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aur_server ON app_user_routes(server_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aur_last ON app_user_routes(last_epoch DESC)');

        // Cache negativo: usuário inexistente não varre os XUIs a cada request.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_negative_cache (
                username TEXT PRIMARY KEY,
                until_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_anc_until ON app_negative_cache(until_epoch)');

        // Lock de descoberta: evita 10 players do mesmo user varrerem juntos.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_discovery_lock (
                username TEXT PRIMARY KEY,
                expires_epoch INTEGER NOT NULL DEFAULT 0
            )'
        );
    }
}
