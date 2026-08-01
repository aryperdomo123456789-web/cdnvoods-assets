#!/usr/bin/env bash
# Wrapper serial do smoke de estado vivo (StateStore) + contrato LB v1.
set -uo pipefail
cd "$(dirname "$0")/.."
. bin/lib/smoke-serial.sh
smoke_resolve_php
smoke_serialize
exec "$PHP_RESOLVED" bin/smoke-statestore.php