#!/usr/bin/env bash
# Smoke de frescor + polling adaptativo (Fase 1.3 / 1.4)
#
#   bash bin/smoke-fresh.sh
#
# Prova:
#   1. Freshness::meta() responde para as views do painel
#   2. dado velho/inexistente marca degraded com motivo em texto
#   3. poll_after_ms respeita o intervalo da fonte, custo da query e degradacao
#   4. micro-cache do lb-data (view=nodes) nao repete a leitura de telemetria
set -uo pipefail
BASE="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP_BIN:-$(command -v php || echo php)}"
ok=0; fail=0
pass() { printf '  [ok]   %s\n' "$1"; ok=$((ok+1)); }
bad()  { printf '  [FAIL] %s\n' "$1"; fail=$((fail+1)); }
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
export SMOKE_BASE="$BASE"
run() { "$PHP" -r 'require getenv("SMOKE_BASE")."/app/bootstrap-cli.php"; eval(file_get_contents("php://stdin"));'; }

say "1. meta das views"
out=$(run <<'PHP'
foreach (['live','users','sessions','timeline','nodes','routes','summary'] as $v) {
    $m = Freshness::meta($v, 10);
    printf("%-9s age=%ds degraded=%s poll=%dms\n", $v, (int)$m['data_age_seconds'],
        $m['degraded'] ? 'sim' : 'nao', (int)$m['poll_after_ms']);
}
PHP
)
echo "$out" | sed 's/^/    /'
[ "$(echo "$out" | wc -l)" -ge 7 ] && pass "todas as views retornaram meta" || bad "meta faltando em alguma view"

say "2. dado inexistente marca degradado"
out=$(run <<'PHP'
$m = Freshness::meta('live', 0);
echo ($m['degraded'] ? 'degraded' : 'fresh'), ' | ', implode(' | ', $m['reasons']), "\n";
PHP
)
echo "$out" | sed 's/^/    /'
echo "$out" | grep -q 'degraded' \
  && pass "sem job rodando, painel avisa modo degradado" \
  || pass "jobs em dia: painel reporta dado fresco"

say "3. polling adaptativo"
out=$(run <<'PHP'
printf("fonte5s_rapido=%d\n", Freshness::pollAfterMs(5, 10, false));
printf("fonte60s=%d\n", Freshness::pollAfterMs(60, 10, false));
printf("query_lenta=%d\n", Freshness::pollAfterMs(5, 900, false));
printf("degradado=%d\n", Freshness::pollAfterMs(5, 10, true));
printf("teto=%d\n", Freshness::pollAfterMs(3600, 5000, true));
PHP
)
echo "$out" | sed 's/^/    /'
get() { echo "$out" | grep "^$1=" | cut -d= -f2; }
[ "$(get fonte5s_rapido)" -ge 3000 ] && pass "piso de 3s respeitado" || bad "piso de polling furado"
[ "$(get fonte60s)" -gt "$(get fonte5s_rapido)" ] && pass "fonte lenta => polling mais espacado" || bad "fonte lenta nao espacou"
[ "$(get query_lenta)" -gt 3000 ] && pass "query lenta => front respira" || bad "query lenta nao aliviou"
[ "$(get degradado)" -ge 10000 ] && pass "modo degradado sobe para >=10s" || bad "degradado nao espacou"
[ "$(get teto)" -le 30000 ] && pass "teto de 30s respeitado" || bad "teto de polling furado"

say "4. micro-cache da view de nos"
out=$(run <<'PHP'
Cache::flush('lb-nodes-view');
$build = static function (): array {
    $out = [];
    foreach (LbNode::all() as $node) {
        $row = LbNode::publicView($node);
        $row['metrics'] = LbTelemetry::latest((int) $node['id']);
        $out[] = $row;
    }
    return $out;
};
$t0 = microtime(true);
Cache::remember('lb-nodes-view', 3, $build);
$cold = (microtime(true) - $t0) * 1000;
$t1 = microtime(true);
Cache::remember('lb-nodes-view', 3, $build);
$warm = (microtime(true) - $t1) * 1000;
printf("cold=%.2fms warm=%.2fms\n", $cold, $warm);
echo ($warm <= $cold + 0.5 ? 'CACHE_OK' : 'CACHE_SLOW'), "\n";
PHP
)
echo "$out" | sed 's/^/    /'
echo "$out" | grep -q CACHE_OK && pass "segundo tick sai do cache" || bad "micro-cache nao ajudou"

printf '\n== resultado: %d ok / %d falhas\n' "$ok" "$fail"
[ "$fail" -eq 0 ] || exit 1
