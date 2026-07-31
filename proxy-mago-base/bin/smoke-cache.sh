#!/usr/bin/env bash
# Prova single-flight do micro-cache (anti cache stampede).
set -uo pipefail
cd "$(dirname "$0")/.."
. "$(cd "$(dirname "$0")" && pwd)/lib/smoke-serial.sh"
smoke_resolve_php
smoke_serialize
exec "${PHP[@]}" bin/smoke-cache.php
