#!/usr/bin/env bash
# Laboratório real do CDN Voods. Credenciais NUNCA ficam no repositório:
# exporte as variáveis antes de rodar (ver docs/LABORATORIO_REAL.md).
set -euo pipefail
cd "$(dirname "$0")/../.."
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [ -z "$PHP_BIN" ]; then PHP_BIN="nix run nixpkgs#php82 --"; fi
: "${LAB_DB_USER:?exporte LAB_DB_USER}"; : "${LAB_USER:?exporte LAB_USER}"
$PHP_BIN bin/lab/lab-real.php
