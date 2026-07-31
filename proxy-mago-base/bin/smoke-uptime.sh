#!/usr/bin/env bash
# Smoke do uptime real por sessão (S2-P0-3).
set -uo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -z "$PHP_BIN" ] && command -v nix >/dev/null 2>&1; then
  PHP_BIN="$(nix build nixpkgs#php82 --no-link --print-out-paths)/bin/php"
fi
[ -n "$PHP_BIN" ] || { echo "PHP nao encontrado (exporte PHP_BIN)" >&2; exit 2; }
"$PHP_BIN" bin/smoke-uptime.php
