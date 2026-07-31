<?php
/**
 * S2-P0-5 — ENSAIO DE CORTE da trilha quente: SQLite -> PostgreSQL.
 *
 * Objetivo: provar, FORA da janela de produção, que o dado quente atravessa
 * para o Postgres com paridade de linhas e sem perder coluna, e medir quanto
 * tempo a janela de corte real vai custar.
 *
 * Não toca no SQLite (leitura pura) e não muda o driver do painel: o destino
 * vem 100% de ambiente (PROXY_MAGO_DB_*).
 *
 * Uso:
 *   PROXY_MAGO_DB_DRIVER=pgsql PROXY_MAGO_DB_HOST=127.0.0.1 \
 *   PROXY_MAGO_DB_PORT=5432 PROXY_MAGO_DB_NAME=proxy_mago \
 *   PROXY_MAGO_DB_USER=proxy_mago PROXY_MAGO_DB_PASS=... \
 *   php bin/pg-cut.php --fresh
 *
 * Flags:
 *   --fresh      TRUNCATE nas tabelas quentes do destino antes de copiar
 *   --dry-run    só conta origem/destino e mostra o plano, sem escrever
 *   --tables=a,b limita o ensaio a algumas tabelas
 *   --chunk=500  linhas por lote
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Config.php';
require $root . '/app/Sql.php';

$argvFlags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $argvFlags[$m[1]] = $m[2] ?? '1';
    }
}
$fresh = isset($argvFlags['fresh']);
$dryRun = isset($argvFlags['dry-run']);
$allowDrift = isset($argvFlags['allow-drift']);
$chunk = max(50, (int) ($argvFlags['chunk'] ?? 500));
$only = isset($argvFlags['tables']) && $argvFlags['tables'] !== '1'
    ? array_filter(array_map('trim', explode(',', (string) $argvFlags['tables'])))
    : [];

$tables = $only !== [] ? array_values(array_intersect(Sql::HOT_TABLES, $only)) : Sql::HOT_TABLES;
if ($tables === []) {
    fwrite(STDERR, "[erro] nenhuma tabela quente selecionada.\n");
    exit(2);
}

$sqlitePath = getenv('PROXY_MAGO_CUT_SQLITE') ?: ($root . '/storage/app.sqlite');
if (!is_file($sqlitePath)) {
    fwrite(STDERR, "[erro] SQLite de origem nao encontrado: $sqlitePath\n");
    exit(2);
}

if (strtolower((string) Config::get('db_driver')) !== 'pgsql') {
    fwrite(STDERR, "[erro] destino nao e pgsql. Exporte PROXY_MAGO_DB_DRIVER=pgsql.\n");
    exit(2);
}

$src = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$src->exec('PRAGMA busy_timeout = 30000');

$dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
    (string) Config::get('db_host'),
    (int) Config::get('db_port'),
    (string) Config::get('db_name'),
    (string) Config::get('db_sslmode', 'prefer')
);
$dst = new PDO($dsn, (string) Config::get('db_user'), (string) Config::get('db_pass'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$dst->exec("SET TIME ZONE 'UTC'");

/** @return list<string> */
$sqliteColumns = static function (PDO $pdo, string $table): array {
    try {
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    return array_map(static fn(array $r): string => (string) $r['name'], $rows);
};

/** @return list<string> */
$pgColumns = static function (PDO $pdo, string $table): array {
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = :t
         ORDER BY ordinal_position'
    );
    $stmt->execute([':t' => $table]);
    return array_map(static fn(array $r): string => (string) $r['column_name'], $stmt->fetchAll());
};

$count = static function (PDO $pdo, string $table): int {
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
};

echo "== ensaio de corte: SQLite -> PostgreSQL\n";
echo "   origem : $sqlitePath\n";
echo '   destino: ' . Config::get('db_name') . '@' . Config::get('db_host') . ':' . Config::get('db_port') . "\n";
echo '   modo   : ' . ($dryRun ? 'dry-run' : ($fresh ? 'copia + truncate' : 'copia (append)')) . "\n\n";

$report = [];
$fail = 0;
$totalRows = 0;
$startAll = microtime(true);

foreach ($tables as $table) {
    $srcCols = $sqliteColumns($src, $table);
    $dstCols = $pgColumns($dst, $table);
    if ($dstCols === []) {
        echo "  [FAIL] $table -> ausente no destino (rode bin/pg-migrate.php)\n";
        $fail++;
        continue;
    }
    if ($srcCols === []) {
        echo "  [skip] $table -> ausente na origem\n";
        continue;
    }

    $cols = array_values(array_intersect($srcCols, $dstCols));
    $droppedSrc = array_values(array_diff($srcCols, $dstCols));
    $droppedDst = array_values(array_diff($dstCols, $srcCols));
    $srcRows = $count($src, $table);

    if ($droppedSrc !== []) {
        // Coluna que existe na origem e nao existe no destino = dado que o
        // corte REAL perderia em silencio. Isso e falha, nao aviso.
        printf(
            "  [%s] %-22s colunas ausentes no destino: %s\n",
            $allowDrift ? 'warn' : 'FAIL',
            $table,
            implode(',', $droppedSrc)
        );
        if (!$allowDrift) {
            $fail++;
            continue;
        }
    }

    if ($dryRun) {
        printf(
            "  [plan] %-22s linhas=%-8d colunas=%d\n",
            $table,
            $srcRows,
            count($cols)
        );
        $report[$table] = ['src' => $srcRows, 'dst' => $count($dst, $table), 'ms' => 0];
        continue;
    }

    $t0 = microtime(true);
    if ($fresh) {
        $dst->exec('TRUNCATE TABLE ' . $table);
    }

    $colList = implode(', ', $cols);
    $copied = 0;
    $placeholders = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';

    // Paginação: quando a tabela tem `id`, usa KEYSET (id > último). LIMIT/OFFSET
    // no SQLite relê as linhas puladas — em tabela de milhões de eventos isso
    // vira O(n²) e a janela de corte estoura. OFFSET só como último recurso.
    $keyset = in_array('id', $cols, true);
    $lastId = 0;
    $offset = 0;

    while (true) {
        if ($keyset) {
            $stmt = $src->prepare(
                'SELECT ' . $colList . ' FROM ' . $table . ' WHERE id > :last ORDER BY id LIMIT :lim'
            );
            $stmt->bindValue(':last', $lastId, PDO::PARAM_INT);
        } else {
            $stmt = $src->prepare('SELECT ' . $colList . ' FROM ' . $table . ' LIMIT :lim OFFSET :off');
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $chunk, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            break;
        }

        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $values[] = $placeholders;
            foreach ($cols as $col) {
                $params[] = $row[$col] ?? null;
            }
            if ($keyset) {
                $lastId = max($lastId, (int) ($row['id'] ?? 0));
            }
        }
        $insert = 'INSERT INTO ' . $table . ' (' . $colList . ') VALUES ' . implode(', ', $values)
            . ' ON CONFLICT DO NOTHING';
        $dst->beginTransaction();
        try {
            $dst->prepare($insert)->execute($params);
            $dst->commit();
        } catch (Throwable $e) {
            $dst->rollBack();
            echo "  [FAIL] $table -> " . $e->getMessage() . "\n";
            $fail++;
            break;
        }
        $copied += count($rows);
        $offset += $chunk;
    }

    // Sequência de id fica atrás quando a gente insere id explícito.
    if (in_array('id', $cols, true)) {
        try {
            $seq = (string) $dst->query("SELECT pg_get_serial_sequence('" . $table . "', 'id')")->fetchColumn();
            if ($seq !== '') {
                $dst->exec('SELECT setval(' . $dst->quote($seq) . ", COALESCE((SELECT MAX(id) FROM $table), 1))");
            }
        } catch (Throwable $e) {
            echo "  [warn] $table -> sequencia nao ajustada: " . $e->getMessage() . "\n";
        }
    }

    $dstRows = $count($dst, $table);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $totalRows += $copied;
    $report[$table] = ['src' => $srcRows, 'dst' => $dstRows, 'ms' => $ms];

    $parity = $fresh ? ($dstRows === $srcRows) : ($dstRows >= $srcRows);
    printf(
        "  [%s] %-22s origem=%-8d destino=%-8d %5dms\n",
        $parity ? ' ok ' : 'FAIL',
        $table,
        $srcRows,
        $dstRows,
        $ms
    );
    if (!$parity) {
        $fail++;
    }
    if ($droppedDst !== [] && $srcRows > 0) {
        printf("         (destino tem colunas extras: %s)\n", implode(',', $droppedDst));
    }
}

$elapsed = (int) round((microtime(true) - $startAll) * 1000);
printf(
    "\n== corte: %d tabelas | %d linhas copiadas | %dms | %d falhas\n",
    count($report),
    $totalRows,
    $elapsed,
    $fail
);
if (!$dryRun) {
    echo "   janela estimada de corte real (com este volume): ~" . max(1, (int) ceil($elapsed / 1000)) . "s\n";
}
exit($fail === 0 ? 0 : 1);
