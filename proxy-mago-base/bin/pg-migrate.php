<?php
/**
 * S2-P0-5 — aplica o schema da TRILHA QUENTE no PostgreSQL alvo.
 *
 * Não copia dado. Só garante que as tabelas de Sql::HOT_TABLES existem no
 * destino, usando exatamente a mesma DDL que o painel usaria em produção
 * (Database::ensureSchema -> migratePgsqlHot). Rode sempre com o driver
 * apontado por ambiente:
 *
 *   PROXY_MAGO_DB_DRIVER=pgsql PROXY_MAGO_DB_HOST=... php bin/pg-migrate.php
 */
require __DIR__ . '/../app/bootstrap-cli.php';

if (!Database::isPgsql()) {
    fwrite(STDERR, "[erro] driver atual e '" . Database::driver() . "'. Exporte PROXY_MAGO_DB_DRIVER=pgsql.\n");
    exit(2);
}

$pdo = Database::pdo();
$missing = [];
foreach (Sql::HOT_TABLES as $table) {
    try {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        echo "  [ok]   $table\n";
    } catch (Throwable $e) {
        $missing[] = $table;
        echo "  [FAIL] $table -> " . $e->getMessage() . "\n";
    }
}
printf("\n== schema pgsql: %d/%d tabelas quentes\n", count(Sql::HOT_TABLES) - count($missing), count(Sql::HOT_TABLES));
exit($missing === [] ? 0 : 1);
