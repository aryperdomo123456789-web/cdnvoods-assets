#!/usr/bin/env bash
# Wrapper serial do smoke de cérebro puro (lb_require_delivery + rota padrão).
set -uo pipefail
cd "$(dirname "$0")/.."
. bin/lib/smoke-serial.sh
smoke_resolve_php
smoke_serialize
exec "$PHP_RESOLVED" bin/smoke-lb-only.php