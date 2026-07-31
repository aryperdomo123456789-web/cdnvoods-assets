#!/usr/bin/env bash
# Smoke do modulo LB — cerebro (main) + musculos (LB-0N)
#
#   bash bin/smoke-lb.sh [username]
#
# Prova, sem tocar em trafego real de cliente:
#   1. cadastro/consulta de nos e score
#   2. atribuicao forcada de usuario para um LB
#   3. queda do LB (offline) => fallback para o cerebro
#   4. volta do LB => usuario roteado de novo
#   5. supersede de VOD (troca de filme nao infla conexao)
set -uo pipefail

BASE="$(cd "$(dirname "$0")/.." && pwd)"
# Sem php no PATH (ambiente de dev/nix) o smoke caia com "command not found"
# e reportava falha falsa. Agora ha fallback explicito.
if [ -n "${PHP_BIN:-}" ]; then PHP=("$PHP_BIN");
elif command -v php >/dev/null 2>&1; then PHP=(php);
else PHP=(nix run nixpkgs#php82 --); fi
USERNAME="${1:-smoke_lb_user}"
ok=0; fail=0
pass() { printf '  [ok]   %s\n' "$1"; ok=$((ok+1)); }
bad()  { printf '  [FAIL] %s\n' "$1"; fail=$((fail+1)); }
say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }

run() { "${PHP[@]}" -r 'require getenv("SMOKE_BASE")."/app/bootstrap-cli.php"; eval(file_get_contents("php://stdin"));'; }
export SMOKE_BASE="$BASE"

say "1. nos cadastrados e score"
out=$(run <<'PHP'
$nodes = LbNode::all();
echo count($nodes), "\n";
foreach ($nodes as $n) {
    printf("node=%d status=%s score=%.2f\n", (int)$n['id'], (string)($n['health_status'] ?? '-'), LbRouter::score($n));
}
PHP
)
echo "$out" | sed 's/^/    /'
count=$(echo "$out" | head -1)
[ -n "$count" ] && pass "consulta de nos respondeu ($count nos)" || bad "consulta de nos falhou"

say "2. decisao de rota para $USERNAME"
run <<PHP | sed 's/^/    /'
\$d = LbRouter::decide('$USERNAME', 'smoke');
echo 'mode=', \$d['mode'] ?? '-', ' lb_id=', (int)(\$d['lb_id'] ?? 0), ' reason=', \$d['reason'] ?? '-', "\n";
PHP
[ $? -eq 0 ] && pass "decide() executou sem erro" || bad "decide() quebrou"

say "3. queda de LB => fallback pro cerebro"
out=$(run <<PHP
\$nodes = LbNode::all();
\$node = null;
foreach (\$nodes as \$n) { if ((int)\$n['id'] > 0) { \$node = \$n; break; } }
if (!\$node) { echo "SKIP sem LB cadastrado\n"; exit; }
\$id = (int) \$node['id'];
\$prevEnabled = (int) (\$node['enabled'] ?? 1);
LbRouter::assign('$USERNAME', 'forced', \$id, 'smoke_forced');
LbNode::setEnabled(\$id, false); // musculo caiu
\$d = LbRouter::decide('$USERNAME', 'smoke_offline');
echo 'offline_decide_lb=', (int)(\$d['lb_id'] ?? 0), ' mode=', \$d['mode'] ?? '-', "\n";
LbNode::setEnabled(\$id, \$prevEnabled === 1);
\$d2 = LbRouter::decide('$USERNAME', 'smoke_online');
echo 'online_decide_lb=', (int)(\$d2['lb_id'] ?? 0), "\n";
LbRouter::remove('$USERNAME');
PHP
)
echo "$out" | sed 's/^/    /'
if echo "$out" | grep -q 'SKIP'; then
  pass "sem LB cadastrado — fallback nao aplicavel (cerebro entrega tudo)"
elif echo "$out" | grep -q 'offline_decide_lb=0'; then
  pass "LB offline => trafego volta pro cerebro"
else
  bad "LB offline continuou recebendo rota"
fi

say "4. supersede de VOD (troca de filme nao infla conexao)"
out=$(run <<'PHP'
$mk = function (int $sid) {
    $_SERVER['HTTP_USER_AGENT'] = 'SmokeLB/1.0';
    return RequestContext::build('smoke.local', '203.0.113.77', "/movie/smokeuser/smokepass/$sid.mp4", []);
};
$k1 = CdnSession::touch($mk(1001));
CdnSession::record($k1, 200, 1024);
$k2 = CdnSession::touch($mk(1002));
CdnSession::record($k2, 200, 1024);
$st = Database::pdo()->prepare(
    "SELECT status, close_reason FROM cdn_sessions WHERE session_key = :k"
);
$st->execute([':k' => $k1]);
$r = $st->fetch() ?: ['status' => '?', 'close_reason' => '?'];
echo 'antiga_status=', $r['status'], ' motivo=', $r['close_reason'], "\n";
$st2 = Database::pdo()->prepare(
    "SELECT COUNT(*) FROM cdn_sessions WHERE username = 'smokeuser' AND status = 'active'"
);
$st2->execute();
echo 'ativas=', (int) $st2->fetchColumn(), "\n";
Database::run("DELETE FROM cdn_sessions WHERE username = 'smokeuser'", [], 'smoke.cleanup');
PHP
)
echo "$out" | sed 's/^/    /'
echo "$out" | grep -q 'motivo=superseded' && pass "sessao anterior marcada superseded" || bad "supersede nao aplicou"
echo "$out" | grep -q 'ativas=1' && pass "contador ficou em 1 conexao" || bad "contador inflou na troca de filme"

printf '\n\033[1mresultado: %d ok / %d falhas\033[0m\n' "$ok" "$fail"
[ "$fail" -eq 0 ] || exit 1
