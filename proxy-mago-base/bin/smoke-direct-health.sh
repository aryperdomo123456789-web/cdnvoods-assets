#!/usr/bin/env bash
# Prova o classificador de HOST FINAL (app/DirectHostHealth.php):
# host que entrega, host que barra a CDN (403), host sem resposta e host com
# catálogo velho (404). Insere hops sintéticos e remove tudo no final.
set -uo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP_BIN:-php}"
command -v "$PHP" >/dev/null 2>&1 || PHP="nix run nixpkgs#php82 --"
$PHP bin/../bin/smoke-direct-health.php
