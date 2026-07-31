<?php

/**
 * Conector READ-ONLY ao MySQL do XUI.
 *
 * Regras duras:
 *  - NUNCA é usado no caminho crítico do stream (só por jobs de sync);
 *  - só executa SELECT (query() rejeita qualquer outro verbo);
 *  - timeouts curtos e no máximo uma conexão por processo;
 *  - qualquer indisponibilidade vira exceção tratada pelo job — o painel
 *    continua servindo o último snapshot local.
 */
final class XuiReadOnly
{
    private static ?PDO $pdo = null;

    public static function available(): bool
    {
        return in_array('mysql', PDO::getAvailableDrivers(), true);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!self::available()) {
            throw new RuntimeException('driver pdo_mysql ausente: instale php8.1-mysql na VPS');
        }
        $c = XuiSyncConfig::get();
        $host = trim((string) ($c['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('xui_sync_config sem host configurado');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            (int) ($c['port'] ?? 3306),
            (string) ($c['database_name'] ?? 'xtream_iptvpro')
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => max(1, (int) ($c['connect_timeout_seconds'] ?? 3)),
        ];
        if (defined('PDO::MYSQL_ATTR_READ_DEFAULT_GROUP')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }
        if ((int) ($c['use_tls'] ?? 0) === 1 && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        $pdo = new PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), $options);
        // Sessão read-only explícita: mesmo com credencial errada, não escreve.
        try {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('SET SESSION MAX_EXECUTION_TIME=' . (max(1, (int) ($c['read_timeout_seconds'] ?? 5)) * 1000));
        } catch (Throwable $e) {
            // MySQL antigo pode não suportar; o guard de SELECT abaixo continua valendo.
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    /** Executa apenas SELECT. Qualquer outro verbo é recusado. */
    public static function select(string $sql, array $params = []): array
    {
        if (!preg_match('/^\s*select\s/i', $sql)) {
            throw new RuntimeException('XuiReadOnly aceita somente SELECT');
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * SELECT em streaming: entrega linha a linha sem materializar tudo em RAM.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public static function each(string $sql, array $params = []): Generator
    {
        if (!preg_match('/^\s*select\s/i', $sql)) {
            throw new RuntimeException('XuiReadOnly aceita somente SELECT');
        }
        $pdo = self::pdo();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
            $stmt->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /** Ping barato para o painel de status. */
    public static function ping(): array
    {
        $start = microtime(true);
        try {
            self::select('SELECT 1 AS ok');
            return ['ok' => true, 'ms' => (int) round((microtime(true) - $start) * 1000), 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'ms' => (int) round((microtime(true) - $start) * 1000), 'error' => $e->getMessage()];
        }
    }

    /** Detecta se uma tabela existe (schemas de XUI variam entre versões). */
    public static function hasTable(string $table): bool
    {
        try {
            self::select('SELECT 1 FROM `' . preg_replace('/[^a-z0-9_]/i', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
