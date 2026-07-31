#!/usr/bin/env bash
# Biblioteca comum dos smokes que ESCREVEM na trilha quente do SQLite
# (cdn_sessions, proxy_request_events, proxy_user_runtime, cdn_metrics).
#
# Regra oficial (P0): esses smokes rodam SERIALIZADOS. Rodar dois em paralelo
# no mesmo arquivo SQLite produz `database is locked` — isso é limitação do
# backend, não bug do painel, e resultado paralelo NÃO conta como prova.
#
# Uso, no topo do smoke:
#   . "$(dirname "$0")/lib/smoke-serial.sh"
#   smoke_resolve_php
#   smoke_serialize        # segura o lock até o fim do processo
#
# Variáveis:
#   SMOKE_LOCK_WAIT   segundos de espera pelo lock (padrão 300)
#   SMOKE_NO_SERIAL=1 desativa (só para depurar; nunca para prova final)

smoke_resolve_php() {
  if [ -n "${PHP_BIN:-}" ]; then PHP=("$PHP_BIN")
  elif command -v php >/dev/null 2>&1; then PHP=(php)
  elif command -v nix >/dev/null 2>&1; then PHP=(nix run nixpkgs#php82 --)
  else echo "PHP nao encontrado (exporte PHP_BIN)" >&2; exit 2; fi
  export PHP_RESOLVED="${PHP[0]}"
}

smoke_serialize() {
  [ "${SMOKE_NO_SERIAL:-0}" = "1" ] && { echo "  [warn] serializacao DESATIVADA (SMOKE_NO_SERIAL=1) — resultado nao vale como prova"; return 0; }
  local base lock wait
  base="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  mkdir -p "$base/storage/cache"
  lock="$base/storage/cache/smoke-hot.lock"
  wait="${SMOKE_LOCK_WAIT:-300}"
  if ! command -v flock >/dev/null 2>&1; then
    echo "  [warn] flock ausente: nao foi possivel serializar (rode um smoke por vez)"
    return 0
  fi
  exec 9>"$lock"
  if ! flock -w "$wait" 9; then
    echo "  [FAIL] outro smoke da trilha quente esta rodando (lock: $lock)" >&2
    exit 3
  fi
}
