#!/usr/bin/env bash
# Prova a consolidação ao vivo: in_flight preso, rollup fresco x velho e resumo leve.
set -uo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP_BIN:-$(command -v php || true)}"
if [ -n "$PHP" ]; then
  exec "$PHP" bin/smoke-runtime-live.php
fi
exec nix run nixpkgs#php82 -- bin/smoke-runtime-live.php
