#!/usr/bin/env bash
# Smoke do enforcement de limite de conexões pela CDN (S2-P0-2).
set -uo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -z "$PHP_BIN" ] && command -v nix >/dev/null 2>&1; then
  PHP_BIN="$(nix build nixpkgs#php82 --no-link --print-out-paths)/bin/php"
fi
[ -n "$PHP_BIN" ] || { echo "PHP nao encontrado (exporte PHP_BIN)" >&2; exit 2; }
"$PHP_BIN" bin/smoke-limit.php
