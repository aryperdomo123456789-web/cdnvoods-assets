#!/usr/bin/env bash
# Bateria OFICIAL da trilha quente — execucao SERIAL, um smoke por vez.
#
#   bash bin/smoke-all.sh
#
# Por que serial: todos os smokes abaixo escrevem no MESMO arquivo SQLite
# (cdn_sessions, proxy_request_events, proxy_user_runtime, cdn_metrics).
# Em paralelo, o proprio SQLite devolve `database is locked` e o resultado
# nao prova nada sobre o painel. Prova final = esta bateria, serial, com zero
# ocorrencia de "database is locked".
set -uo pipefail
cd "$(dirname "$0")/.."
. bin/lib/smoke-serial.sh
smoke_resolve_php
smoke_serialize   # o runner segura o lock; os filhos herdam via SMOKE_CHILD

export SMOKE_CHILD=1
export PHP_BIN="${PHP_BIN:-$PHP_RESOLVED}"

SUITES=(
  smoke-runtime-live.sh
  smoke-cache.sh
  smoke-fresh.sh
  smoke-lb.sh
  smoke-ip-lock.sh
  smoke-limit.sh
  smoke-uptime.sh
  smoke-direct-health.sh
)

fails=0
locks=0
log_dir="storage/logs/smoke"
mkdir -p "$log_dir"

for s in "${SUITES[@]}"; do
  printf '\n\033[1m=== %s\033[0m\n' "$s"
  out="$log_dir/${s%.sh}.log"
  if bash "bin/$s" >"$out" 2>&1; then
    tail -n 3 "$out" | sed 's/^/    /'
    echo "  [ok]   $s"
  else
    tail -n 25 "$out" | sed 's/^/    /'
    echo "  [FAIL] $s (log: $out)"
    fails=$((fails+1))
  fi
  # Padrao especifico: a linha de checagem do proprio smoke contem a frase.
  if grep -qEi '\[db:lock\]|General error: 5 database is locked' "$out"; then
    echo "  [LOCK] $s registrou 'database is locked' — ver instrumentacao no log"
    locks=$((locks+1))
  fi
done

printf '\n\033[1m== bateria serial: %d falhas / %d suites com lock\033[0m\n' "$fails" "$locks"
[ "$fails" -eq 0 ] && [ "$locks" -eq 0 ] || exit 1
