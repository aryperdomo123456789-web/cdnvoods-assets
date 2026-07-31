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
$pdo->prepare('DELETE FROM cdn_sessions WHERE username = :u')->execute([':u' => $user]);
$pdo->exec('DELETE FROM cdn_metrics');
Cache::flush();

$_SERVER['HTTP_USER_AGENT'] = 'SmokeTV/1.0';
$key = CdnSession::touch(RequestContext::build('smoke.local', '203.0.113.77', "/live/$user/pass/9001.ts", []));
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
$pdo->prepare(
    'UPDATE cdn_sessions SET last_seen_epoch = last_seen_epoch - :s WHERE session_key = :k'
)->execute([':s' => CdnSession::IN_FLIGHT_MAX + 60, ':k' => $key]);
check('sessão fantasma não conta mais', $active() === 0);

echo "\n== 3. sweep solta o in_flight preso\n";
$stats = ['processed' => 0, 'details' => []];
CdnSession::sweep($stats);
check('sweep reportou in_flight liberado', (int) ($stats['details']['in_flight_released'] ?? 0) >= 1);
$r = $pdo->query("SELECT active_requests, status, close_reason FROM cdn_sessions WHERE session_key = '" . $key . "'")->fetch();
check('active_requests zerado', $r === false || (int) $r['active_requests'] === 0);
check('sessão encerrada por idle', $r === false || (string) $r['status'] === 'closed');

echo "\n== 4. rollup fresco manda no KPI (zero é zero, sem piscar)\n";
$now = time();
$ins = $pdo->prepare('INSERT INTO cdn_metrics (metric, value, ts_epoch) VALUES (:m,:v,:t)');
foreach (['connections_active' => 0, 'users_active' => 0, 'fetch_active' => 0, 'direct_active' => 0] as $m => $v) {
    $ins->execute([':m' => $m, ':v' => $v, ':t' => $now]);
}
// Reproduz produção: outro job grava métrica MAIS NOVA um segundo depois.
// Isso derrubava a leitura da idade e devolvia -1 no painel/smoke.
$ins->execute([':m' => 'requests_5m', ':v' => 42, ':t' => $now + 1]);
Cache::flush();
$k1 = RestreamRuntime::kpisFresh();
check('rollup fresco reconhecido', $k1['rollup_stale'] === false);
check('idade do rollup exposta', (int) $k1['rollup_age_s'] >= 0 && (int) $k1['rollup_age_s'] < 10);
check('zero fresco não vira recontagem', (int) $k1['connections_now'] === 0);

echo "\n== 5. rollup velho => modo degradado com recontagem\n";
$pdo->exec('UPDATE cdn_metrics SET ts_epoch = ts_epoch - ' . (RestreamRuntime::ROLLUP_MAX_AGE + 60));
Cache::flush();
$k2 = RestreamRuntime::kpisFresh();
check('rollup velho marcado como stale', $k2['rollup_stale'] === true);
check('idade do rollup acima do teto', (int) $k2['rollup_age_s'] > RestreamRuntime::ROLLUP_MAX_AGE);

echo "\n== 6. resumo lê números caros do rollup leve\n";
$ins->execute([':m' => 'users_runtime_active', ':v' => 7, ':t' => time()]);
$ins->execute([':m' => 'over_limit_now', ':v' => 3, ':t' => time()]);
Cache::flush();
$s = RestreamRuntime::summaryFresh();
check('over_limit vem do rollup', (int) $s['over_limit'] === 3);
check('active_users respeita o rollup', (int) $s['active_users'] >= 7);

$pdo->prepare('DELETE FROM cdn_sessions WHERE username = :u')->execute([':u' => $user]);
$pdo->exec('DELETE FROM cdn_metrics');
Cache::flush();

echo "\n== resultado: $ok ok / $fail falhas\n";
exit($fail === 0 ? 0 : 1);
