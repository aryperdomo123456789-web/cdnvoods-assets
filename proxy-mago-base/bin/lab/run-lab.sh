#!/usr/bin/env bash
# Laboratório real do CDN Voods (sem mock). Credenciais NUNCA no repositório:
# exporte as variáveis descritas em docs/LABORATORIO_REAL.md antes de rodar.
set -euo pipefail
cd "$(dirname "$0")/../.."

: "${LAB_DB_USER:?exporte LAB_DB_USER}"
: "${LAB_USER:?exporte LAB_USER}"

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -z "$PHP_BIN" ] && command -v nix >/dev/null 2>&1; then
  PHP_BIN="$(nix build nixpkgs#php82 --no-link --print-out-paths)/bin/php"
fi
if [ -z "$PHP_BIN" ]; then
  echo "PHP não encontrado. Instale php8.1+ ou exporte PHP_BIN." >&2
  exit 2
fi

exec "$PHP_BIN" bin/lab/lab-real.php
