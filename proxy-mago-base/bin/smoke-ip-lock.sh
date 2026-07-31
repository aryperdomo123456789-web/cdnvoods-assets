#!/usr/bin/env bash
# Smoke da trava CDN por IP (S2-P0-1): exato, CIDR, faixa, curinga, IPv6,
# regra inválida e fail-open quando o usuário não tem trava.
set -uo pipefail
cd "$(dirname "$0")/.."
. "$(cd "$(dirname "$0")" && pwd)/lib/smoke-serial.sh"
smoke_resolve_php
smoke_serialize

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -z "$PHP_BIN" ] && command -v nix >/dev/null 2>&1; then
  PHP_BIN="$(nix build nixpkgs#php82 --no-link --print-out-paths)/bin/php"
fi
[ -n "$PHP_BIN" ] || { echo "PHP nao encontrado (exporte PHP_BIN)" >&2; exit 2; }

"$PHP_BIN" bin/smoke-ip-lock.php
