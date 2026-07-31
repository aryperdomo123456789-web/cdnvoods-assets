#!/usr/bin/env bash
# Smoke test pós-deploy — VPS REAL 45.140.192.237 (Ubuntu 22.04)
# Path do projeto: /opt/proxy-mago/proxy-mago-base
#
# Uso:
#   bash bin/smoke-test.sh voods.suafontee.com 4Jknjjujtsuper 4Jknjjujtsuper
#
# Roda contra 127.0.0.1 com Host: <dominio>, então NÃO depende de DNS nem de TLS.
set -uo pipefail

DOM="${1:-voods.suafontee.com}"
USER="${2:-4Jknjjujtsuper}"
PASS="${3:-4Jknjjujtsuper}"
BASE="${BASE:-http://127.0.0.1}"
H="Host: ${DOM}"
UA="TiviMate/4.7.0 (Android)"
TMP="$(mktemp -d)"
FAIL=0

# Nada que possa vazar da origem pode aparecer no corpo.
LEAKS='dafonte\.uk|38\.190\.176\.170'

hit() { # hit <label> <path+query> <esperado>
  local label="$1"
  local url="$2"
  local want="$3"
  local out="$TMP/${label}.out"
  local res
  res=$(curl -s -o "$out" -D "$TMP/${label}.hdr" -H "$H" -A "$UA" \
        --max-time 300 -w '%{http_code} %{size_download} %{time_total} %{redirect_url}' \
        "${BASE}${url}")
  local code size time redir
  redir=""
  read -r code size time redir <<<"$res"
  local leak
  leak=$(grep -oiE "$LEAKS" "$out" 2>/dev/null | wc -l | tr -d " ")
  local status="OK"
  [ "$code" != "$want" ] && { status="FALHA(code=$code)"; FAIL=1; }
  [ -n "${redir:-}" ] && { status="FALHA(redirect=$redir)"; FAIL=1; }
  [ "$leak" != "0" ] && { status="FALHA(vazamento=$leak)"; FAIL=1; }
  printf '%-16s %-6s %10s bytes %6ss  %s\n' "$label" "$code" "$size" "$time" "$status"
}

echo "== smoke test  dominio=${DOM}  (VPS 45.140.192.237)"
hit player_api "/player_api.php?username=${USER}&password=${PASS}" 200
hit categorias  "/player_api.php?username=${USER}&password=${PASS}&action=get_vod_categories" 200
hit xmltv       "/xmltv.php?username=${USER}&password=${PASS}" 200
hit get_mpegts  "/get.php?username=${USER}&password=${PASS}&type=m3u_plus&output=mpegts" 200
hit get_hls     "/get.php?username=${USER}&password=${PASS}&type=m3u_plus&output=hls" 200

echo "-- alias desconhecido deve dar 404"
code=$(curl -s -o /dev/null -H 'Host: dominio-que-nao-existe.invalid' -w '%{http_code}' "${BASE}/get.php?username=a&password=b")
[ "$code" = "404" ] && echo "alias_invalido   404    OK" || { echo "alias_invalido   $code   FALHA"; FAIL=1; }

echo "-- painel NAO pode existir no dominio publico"
for p in / /login.php /dashboard.php /setup.php /health.php; do
  code=$(curl -s -o /dev/null -H "$H" -w '%{http_code}' "${BASE}${p}")
  [ "$code" = "404" ] || { echo "painel exposto em $p (code=$code)"; FAIL=1; }
done
echo "painel_oculto    404    $([ $FAIL -eq 0 ] && echo OK || echo VERIFICAR)"

echo "-- primeira URL de midia da playlist"
grep -m1 '^http' "$TMP/get_mpegts.out" 2>/dev/null || echo "(playlist vazia)"

rm -rf "$TMP"
[ $FAIL -eq 0 ] && echo "== SMOKE TEST OK" || echo "== SMOKE TEST COM FALHAS"
exit $FAIL
