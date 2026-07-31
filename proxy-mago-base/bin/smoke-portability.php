<?php
/**
 * Smoke S2-P0-4 — portabilidade da TRILHA QUENTE (SQLite -> PostgreSQL).
 *
 * Não migra nada. Prova, ANTES da Fase 3, que:
 *   - nenhum SQL de app/ usa dialeto exclusivo do SQLite fora de Database.php
 *   - toda tabela quente (Sql::HOT_TABLES) existe também no caminho pgsql
 *   - a DDL pgsql não usa `DEFAULT ""` (identificador no Postgres) nem AUTOINCREMENT
 *   - Sql::upsert() funciona de verdade no backend atual (idempotente)
 */
require __DIR__ . '/../app/bootstrap-cli.php';

$ok = 0; $fail = 0;
function check(string $label, bool $cond, string $extra = ''): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [ok]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label" . ($extra !== '' ? " -> $extra" : '') . "\n"; }
}

$root = dirname(__DIR__);

// 1) Dialeto SQLite solto em app/ (Database.php pode: ele é o tradutor).
$forbidden = [
    'INSERT OR REPLACE' => '/INSERT\s+OR\s+REPLACE/i',
    'INSERT OR IGNORE'  => '/INSERT\s+OR\s+IGNORE/i',
    'strftime()'        => '/\bstrftime\s*\(/i',
    'julianday()'       => '/\bjulianday\s*\(/i',
    'PRAGMA sem guarda' => '/PRAGMA/i',
];
$offenders = [];
foreach (glob($root . '/app/*.php') as $file) {
    $base = basename($file);
    // Database.php e Sql.php SÃO o tradutor de dialeto: podem citar os dois.
    if ($base === 'Database.php' || $base === 'Sql.php') {
        continue;
    }
    // Comentário citando o problema não é o problema: só código conta.
    $src = '';
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $src .= is_array($token) ? $token[1] : $token;
    }
    foreach ($forbidden as $label => $re) {
        if (!preg_match($re, $src)) {
            continue;
        }
        if ($label === 'PRAGMA sem guarda') {
            // PRAGMA é aceitável quando explicitamente protegido por driver.
            foreach (preg_split('/\R/', $src) ?: [] as $i => $line) {
                if (!preg_match('/PRAGMA/i', $line)) {
                    continue;
                }
                $window = implode("\n", array_slice(preg_split('/\R/', $src) ?: [], max(0, $i - 3), 4));
                if (!str_contains($window, 'isSqlite')) {
                    $offenders[] = $base . ': ' . $label;
                }
            }
            continue;
        }
        $offenders[] = $base . ': ' . $label;
    }
}
check('nenhum dialeto SQLite solto em app/', $offenders === [], implode(' | ', $offenders));

// 2) Paridade: toda tabela quente tem DDL no caminho pgsql.
$dbSrc = (string) file_get_contents($root . '/app/Database.php');
$start = strpos($dbSrc, 'function migratePgsqlHot');
check('caminho pgsql existe em Database.php', $start !== false);
$pgBlock = '';
if ($start !== false) {
    // Fim do bloco: próxima declaração de função no arquivo.
    $next = strpos($dbSrc, "\n    private static function ", $start + 10);
    $pgBlock = substr($dbSrc, $start, ($next === false ? strlen($dbSrc) : $next) - $start);
    // Comentário do próprio bloco cita as armadilhas; só DDL conta.
    $pgBlock = preg_replace('#^\s*//.*$#m', '', $pgBlock) ?? $pgBlock;
}
$missing = [];
foreach (Sql::HOT_TABLES as $table) {
    if (!str_contains($pgBlock, 'CREATE TABLE IF NOT EXISTS ' . $table . ' ')) {
        $missing[] = $table;
    }
}
check('paridade pgsql de todas as tabelas quentes', $missing === [], implode(', ', $missing));

// 3) DDL pgsql sem armadilha de dialeto.
check('pgsql sem DEFAULT "" (aspas duplas)', !preg_match('/DEFAULT\s+""/', $pgBlock));
check('pgsql sem AUTOINCREMENT', !preg_match('/AUTOINCREMENT/i', $pgBlock));
check('pgsql usa BIGSERIAL para chave', str_contains($pgBlock, 'BIGSERIAL PRIMARY KEY'));

// 4) Sql::upsert() gera SQL portável.
$sql = Sql::upsert('t', ['a', 'b', 'c'], ['a']);
check(
    'upsert usa ON CONFLICT + excluded',
    str_contains($sql, 'ON CONFLICT(a) DO UPDATE SET') && str_contains($sql, 'b = excluded.b')
);
check('upsert sem coluna de update vira DO NOTHING', str_contains(Sql::upsert('t', ['a'], ['a']), 'DO NOTHING'));

// 5) Prova funcional no backend atual: upsert é idempotente de verdade.
$pdo = Database::pdo();
$user = 'smoke_port_user';
Database::run('DELETE FROM user_limit_state WHERE username = :u', [':u' => $user], 'smoke_port_clean');
$stmt = Sql::upsert('user_limit_state', ['username', 'over_limit_since_epoch', 'updated_epoch'], ['username']);
$writes = 0;
foreach ([100, 200, 300] as $epoch) {
    $writes += Database::run($stmt, [
        ':username' => $user,
        ':over_limit_since_epoch' => $epoch,
        ':updated_epoch' => $epoch,
    ], 'smoke_port_upsert') ? 1 : 0;
}
$row = $pdo->prepare('SELECT COUNT(*) c, MAX(updated_epoch) m FROM user_limit_state WHERE username = :u');
$row->execute([':u' => $user]);
$got = $row->fetch() ?: ['c' => 0, 'm' => 0];
check('3 escritas aceitas sem erro', $writes === 3, "writes=$writes");
check('upsert mantém 1 linha só', (int) $got['c'] === 1, 'rows=' . (int) $got['c']);
check('upsert atualizou o valor', (int) $got['m'] === 300, 'valor=' . (int) $got['m']);
Database::run('DELETE FROM user_limit_state WHERE username = :u', [':u' => $user], 'smoke_port_clean2');

// 6) Tabelas quentes realmente existem no backend atual.
$absent = [];
foreach (Sql::HOT_TABLES as $table) {
    try {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
    } catch (Throwable $e) {
        $absent[] = $table;
    }
}
check('todas as tabelas quentes existem no backend atual', $absent === [], implode(', ', $absent));

printf("\n== portabilidade: %d ok / %d falhas\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);