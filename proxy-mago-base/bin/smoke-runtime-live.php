<?php
/**
 * Smoke da consolidação ao vivo (Sprint 2 pós-P0).
 *
 * Prova:
 *   - request "em voo" preso (sem record()) para de contar após IN_FLIGHT_MAX
 *   - o sweep libera esse in_flight e reporta quantos soltou
 *   - rollup FRESCO manda no KPI (mesmo marcando zero) => sem oscilação
 *   - rollup VELHO cai para recontagem e marca modo degradado
 *   - o resumo lê over_limit/usuários do rollup leve
 */
require __DIR__ . '/../app/bootstrap-cli.php';

/**
 * Toda escrita deste smoke passa por DbLockDiag::guard(): se o SQLite travar,
 * o smoke diz QUAL tabela, QUAL operação e QUAIS fluxos concorrentes estavam
 * ativos, em vez de cuspir "SQLSTATE[HY000]: General error: 5 database is
 * locked" sem contexto. Execução serial é obrigatória (bin/smoke-all.sh).
 */
function hot(callable $fn, string $table, string $op)
{
    return DbLockDiag::guard($fn, $table, $op, 'smoke-runtime-live');
}

$ok = 0; $fail = 0;
function check(string $label, bool $cond): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  [ok]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label\n"; }
}

putenv('CDN_LAB_COUNT_LOOPBACK=0');
$user = 'smoke_live_user';
$pdo = Database::pdo();
hot(static fn() => $pdo->prepare('DELETE FROM cdn_sessions WHERE username = :u')->execute([':u' => $user]), 'cdn_sessions', 'delete');
hot(static fn() => $pdo->exec('DELETE FROM cdn_metrics'), 'cdn_metrics', 'delete');
Cache::flush();

$_SERVER['HTTP_USER_AGENT'] = 'SmokeTV/1.0';
$key = hot(static fn() => CdnSession::touch(RequestContext::build('smoke.local', '203.0.113.77', "/live/$user/pass/9001.ts", [])), 'cdn_sessions', 'touch');
check('sessão aberta', $key !== '');

$active = static function () use ($pdo): int {
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM cdn_sessions WHERE ' . CdnSession::activeWhereSql(time()) . '
           AND ' . CdnSession::publicClientWhereSql()
    )->fetchColumn();
};

echo "\n== 1. in_flight recente ainda conta\n";
$r = $pdo->query("SELECT active_requests FROM cdn_sessions WHERE session_key = '" . $key . "'")->fetch();
check('request em voo registrado', (int) $r['active_requests'] > 0);
check('sessão viva agora', $active() === 1);

echo "\n== 2. in_flight preso deixa de contar após o teto\n";
hot(static fn() => $pdo->prepare(
    'UPDATE cdn_sessions SET last_seen_epoch = last_seen_epoch - :s WHERE session_key = :k'
)->execute([':s' => CdnSession::IN_FLIGHT_MAX + 60, ':k' => $key]), 'cdn_sessions', 'update.last_seen');
check('sessão fantasma não conta mais', $active() === 0);

echo "\n== 3. sweep solta o in_flight preso\n";
$stats = ['processed' => 0, 'details' => []];
hot(static fn() => CdnSession::sweep($stats), 'cdn_sessions', 'sweep');
check('sweep reportou in_flight liberado', (int) ($stats['details']['in_flight_released'] ?? 0) >= 1);
$r = $pdo->query("SELECT active_requests, status, close_reason FROM cdn_sessions WHERE session_key = '" . $key . "'")->fetch();
check('active_requests zerado', $r === false || (int) $r['active_requests'] === 0);
check('sessão encerrada por idle', $r === false || (string) $r['status'] === 'closed');

echo "\n== 4. rollup fresco manda no KPI (zero é zero, sem piscar)\n";
$now = time();
$ins = $pdo->prepare('INSERT INTO cdn_metrics (metric, value, ts_epoch) VALUES (:m,:v,:t)');
foreach (['connections_active' => 0, 'users_active' => 0, 'fetch_active' => 0, 'direct_active' => 0] as $m => $v) {
    hot(static fn() => $ins->execute([':m' => $m, ':v' => $v, ':t' => $now]), 'cdn_metrics', 'insert');
}
// Reproduz produção: outro job grava métrica MAIS NOVA um segundo depois.
// Isso derrubava a leitura da idade e devolvia -1 no painel/smoke.
hot(static fn() => $ins->execute([':m' => 'requests_5m', ':v' => 42, ':t' => $now + 1]), 'cdn_metrics', 'insert');
Cache::flush();
$k1 = RestreamRuntime::kpisFresh();
check('rollup fresco reconhecido', $k1['rollup_stale'] === false);
check('idade do rollup exposta', (int) $k1['rollup_age_s'] >= 0 && (int) $k1['rollup_age_s'] < 10);
check('zero fresco não vira recontagem', (int) $k1['connections_now'] === 0);

echo "\n== 5. rollup velho => modo degradado com recontagem\n";
hot(static fn() => $pdo->exec('UPDATE cdn_metrics SET ts_epoch = ts_epoch - ' . (RestreamRuntime::ROLLUP_MAX_AGE + 60)), 'cdn_metrics', 'update.ts');
Cache::flush();
$k2 = RestreamRuntime::kpisFresh();
check('rollup velho marcado como stale', $k2['rollup_stale'] === true);
check('idade do rollup acima do teto', (int) $k2['rollup_age_s'] > RestreamRuntime::ROLLUP_MAX_AGE);

echo "\n== 6. resumo lê números caros do rollup leve\n";
hot(static fn() => $ins->execute([':m' => 'users_runtime_active', ':v' => 7, ':t' => time()]), 'cdn_metrics', 'insert');
hot(static fn() => $ins->execute([':m' => 'over_limit_now', ':v' => 3, ':t' => time()]), 'cdn_metrics', 'insert');
Cache::flush();
$s = RestreamRuntime::summaryFresh();
check('over_limit vem do rollup', (int) $s['over_limit'] === 3);
check('active_users respeita o rollup', (int) $s['active_users'] >= 7);

hot(static fn() => $pdo->prepare('DELETE FROM cdn_sessions WHERE username = :u')->execute([':u' => $user]), 'cdn_sessions', 'cleanup');
hot(static fn() => $pdo->exec('DELETE FROM cdn_metrics'), 'cdn_metrics', 'cleanup');
Cache::flush();

echo "\n== 7. trilha quente sem lock\n";
check('nenhum \'database is locked\' durante o smoke', DbLockDiag::hadLock() === false);
if (DbLockDiag::hadLock()) {
    echo DbLockDiag::report(), "\n";
}

echo "\n== resultado: $ok ok / $fail falhas\n";
exit($fail === 0 ? 0 : 1);
