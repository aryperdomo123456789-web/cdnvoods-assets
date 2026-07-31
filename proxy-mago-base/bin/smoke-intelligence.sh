#!/usr/bin/env bash
# Smoke test da CDN inteligente — VPS 45.140.192.237 / /opt/proxy-mago/proxy-mago-base
#
#   bash bin/smoke-intelligence.sh voods.suafontee.com usuario senha
#
# Valida: agrupamento de sessões locais, contador independente do XUI,
# rastreio de direct source e abertura de divergências.
set -uo pipefail

HOST="${1:-}"; USER="${2:-}"; PASS="${3:-}"
BASE="$(cd "$(dirname "$0")/.." && pwd)"
PHP="$(command -v php || echo php)"
ok=0; fail=0

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
pass() { printf '  [ok]   %s\n' "$1"; ok=$((ok+1)); }
bad()  { printf '  [FAIL] %s\n' "$1"; fail=$((fail+1)); }

if [ -z "$HOST" ] || [ -z "$USER" ] || [ -z "$PASS" ]; then
  echo "uso: bash bin/smoke-intelligence.sh <dominio-publico> <username> <password>"; exit 2
fi

q() { "$PHP" -r '
require "'"$BASE"'/app/bootstrap-cli.php";
$st = Database::pdo()->query($argv[1]);
echo (string) $st->fetchColumn();
' -- "$1"; }

say "1. playlist (deve virar 1 sessão lógica, não N requests)"
curl -s -o /dev/null -H "User-Agent: SmokeIntel/1.0" "http://$HOST/get.php?username=$USER&password=$PASS&type=m3u_plus" \
  && pass "get.php respondeu" || bad "get.php falhou"

say "2. burst HLS (10 segmentos = 1 conexão)"
for i in $(seq 1 10); do
  curl -s -o /dev/null -r 0-1024 -H "User-Agent: SmokeIntel/1.0" \
    "http://$HOST/live/$USER/$PASS/1.m3u8" &
done
wait
"$PHP" "$BASE/bin/jobs-run.php" --job=consolidate_runtime >/dev/null 2>&1
CONN=$(q "SELECT COUNT(*) FROM cdn_sessions WHERE username='$USER' AND state='active' AND session_kind IN ('hls','live')")
[ "${CONN:-0}" -le 2 ] && pass "burst agrupado em $CONN sessão(ões)" || bad "burst inflou para $CONN sessões"

say "3. contador local independente do XUI"
SRC=$(q "SELECT count_source FROM proxy_user_runtime WHERE username='$USER'")
CDN=$(q "SELECT cdn_connections_now FROM proxy_user_runtime WHERE username='$USER'")
XUI=$(q "SELECT xui_connections_now FROM proxy_user_runtime WHERE username='$USER'")
printf '  cdn=%s xui=%s fonte=%s\n' "${CDN:-0}" "${XUI:-0}" "${SRC:-?}"
[ -n "${SRC:-}" ] && pass "contador local ativo (fonte $SRC)" || bad "runtime sem fonte de contagem"

say "4. direct source rastreado por dentro (runtime)"
HOPS=$(q "SELECT COUNT(*) FROM direct_source_hops WHERE username='$USER'")
if [ "${HOPS:-0}" -gt 0 ]; then
  FINAL=$(q "SELECT final_host FROM direct_source_hops WHERE username='$USER' ORDER BY id DESC LIMIT 1")
  MODE=$(q "SELECT direct_mode FROM direct_source_hops WHERE username='$USER' ORDER BY id DESC LIMIT 1")
  pass "$HOPS hop(s) registrados, host final: ${FINAL:-?} (modo ${MODE:-?})"
else
  printf '  [skip] nenhum redirect direct source neste teste\n'
fi

say "4b. direct source cadastrado no DB do XUI (streams.direct_source = 1)"
"$PHP" "$BASE/bin/jobs-run.php" --job=direct_enrich --force >/dev/null 2>&1
DBDIRECT=$(q "SELECT COUNT(*) FROM xui_streams_cache WHERE direct_source = 1")
PARSED=$(q "SELECT COUNT(*) FROM xui_streams_cache WHERE direct_source = 1 AND parse_status = 'ok'")
BADPARSE=$(q "SELECT COUNT(*) FROM xui_streams_cache WHERE direct_source = 1 AND parse_status IN ('bad_json','unsupported','no_host')")
printf '  streams direct no XUI=%s com host=%s parse ruim=%s\n' "${DBDIRECT:-0}" "${PARSED:-0}" "${BADPARSE:-0}"
if [ "${DBDIRECT:-0}" -eq 0 ]; then
  printf '  [skip] espelho de streams vazio — rode xui_sync_streams com o sync ligado\n'
elif [ "${PARSED:-0}" -gt 0 ]; then
  pass "parse de stream_source extraiu host em $PARSED stream(s)"
else
  bad "nenhum host extraído de stream_source (verifique parse_error em xui_streams_cache)"
fi

say "4c. consolidação DB + runtime (host efetivo)"
"$PHP" "$BASE/bin/jobs-run.php" --job=direct_consolidate --force >/dev/null 2>&1
STATES=$(q "SELECT COUNT(*) FROM direct_stream_state")
MISMATCH=$(q "SELECT COUNT(*) FROM direct_stream_state WHERE direct_consistency='mismatch'")
DBONLY=$(q "SELECT COUNT(*) FROM direct_stream_state WHERE direct_origin_mode='db_only'")
RTONLY=$(q "SELECT COUNT(*) FROM direct_stream_state WHERE direct_origin_mode='runtime_only'")
BOTH=$(q "SELECT COUNT(*) FROM direct_stream_state WHERE direct_origin_mode='db_runtime'")
NOHOST=$(q "SELECT COUNT(*) FROM direct_stream_state WHERE direct_consistency='host_missing'")
printf '  catálogo=%s db_only=%s runtime_only=%s db_runtime=%s mismatch=%s sem host=%s\n' \
  "${STATES:-0}" "${DBONLY:-0}" "${RTONLY:-0}" "${BOTH:-0}" "${MISMATCH:-0}" "${NOHOST:-0}"
[ "${STATES:-0}" -gt 0 ] && pass "direct_stream_state populado" || bad "consolidação não gerou estado de stream"
if [ "${MISMATCH:-0}" -gt 0 ]; then
  q "SELECT 'mismatch: ' || stream_id || ' db=' || direct_host_from_db || ' runtime=' || direct_host_runtime FROM direct_stream_state WHERE direct_consistency='mismatch' LIMIT 3"
  printf '\n'
fi

say "4d. host efetivo nas sessões locais"
SESSD=$(q "SELECT COUNT(*) FROM cdn_sessions WHERE direct_source = 1 AND status='active'")
EFF=$(q "SELECT COUNT(*) FROM cdn_sessions WHERE direct_source = 1 AND status='active' AND direct_host_effective <> ''")
printf '  sessões direct ativas=%s com host efetivo=%s\n' "${SESSD:-0}" "${EFF:-0}"
ORPH=$(q "SELECT COUNT(*) FROM cdn_divergences WHERE kind='direct_orphan_session' AND status='open'")
printf '  sessões direct órfãs=%s\n' "${ORPH:-0}"

say "5. vazamento no corpo (origem do XUI nunca pode sair)"
BODY=$(curl -s -H "User-Agent: SmokeIntel/1.0" "http://$HOST/get.php?username=$USER&password=$PASS&type=m3u_plus" | head -c 200000)
ORIGIN_HOST=$(q "SELECT host FROM origins ORDER BY id ASC LIMIT 1")
if [ -n "${ORIGIN_HOST:-}" ] && printf '%s' "$BODY" | grep -qiF "$ORIGIN_HOST"; then
  bad "origem $ORIGIN_HOST vazou na playlist"
else
  pass "nenhuma origem do XUI no corpo"
fi

say "6. divergências e jobs"
"$PHP" "$BASE/bin/jobs-run.php" --job=detect_inconsistency >/dev/null 2>&1
DIV=$(q "SELECT COUNT(*) FROM cdn_divergences WHERE status='open'")
DIRDIV=$(q "SELECT COUNT(*) FROM cdn_divergences WHERE status='open' AND kind LIKE 'direct_%'")
printf '  divergências de direct source abertas=%s\n' "${DIRDIV:-0}"
LATE=$(q "SELECT COUNT(*) FROM job_state WHERE next_run_epoch > 0 AND next_run_epoch < strftime('%s','now') - 120")
printf '  divergências abertas=%s jobs atrasados=%s\n' "${DIV:-0}" "${LATE:-0}"
[ "${LATE:-0}" -eq 0 ] && pass "nenhum job atrasado" || bad "$LATE job(s) atrasados"

printf '\n\033[1mresultado: %d ok, %d falhas\033[0m\n' "$ok" "$fail"
[ "$fail" -eq 0 ] || exit 1
