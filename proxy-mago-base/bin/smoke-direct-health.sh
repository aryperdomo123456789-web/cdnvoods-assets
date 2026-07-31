#!/usr/bin/env bash
# Prova o classificador de HOST FINAL (app/DirectHostHealth.php): host que
# entrega, host que barra a CDN (403), host sem resposta e catálogo velho (404).
set -uo pipefail
cd "$(dirname "$0")/.."
. "$(cd "$(dirname "$0")" && pwd)/lib/smoke-serial.sh"
smoke_resolve_php
smoke_serialize
PHP="${PHP_BIN:-$(command -v php || true)}"
if [ -n "$PHP" ]; then
  exec "$PHP" bin/smoke-direct-health.php
fi
exec nix run nixpkgs#php82 -- bin/smoke-direct-health.php
