#!/usr/bin/env bash
# Smoke S2-P0-4: portabilidade da trilha quente (SQLite -> PostgreSQL).
set -uo pipefail
cd "$(dirname "$0")/.."
. "$(cd "$(dirname "$0")" && pwd)/lib/smoke-serial.sh"
smoke_resolve_php
smoke_serialize
PHP_BIN="${PHP_BIN:-$PHP_RESOLVED}"
[ -n "$PHP_BIN" ] || { echo "PHP nao encontrado (exporte PHP_BIN)" >&2; exit 2; }
"$PHP_BIN" bin/smoke-portability.php
