#!/usr/bin/env bash
# Prova o classificador de HOST FINAL (app/DirectHostHealth.php): host que
# entrega, host que barra a CDN (403), host sem resposta e catálogo velho (404).
set -uo pipefail
cd "$(dirname "$0")/.."
if command -v php >/dev/null 2>&1; then
  exec php bin/smoke-direct-health.php
fi
exec nix run nixpkgs#php82 -- bin/smoke-direct-health.php
