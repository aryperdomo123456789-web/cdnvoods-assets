#!/usr/bin/env bash
# Prova a consolidacao ao vivo: in_flight preso, rollup fresco x velho e resumo leve.
# ESCREVE em cdn_sessions e cdn_metrics => execucao serializada obrigatoria.
set -uo pipefail
cd "$(dirname "$0")/.."
. "$(cd "$(dirname "$0")" && pwd)/lib/smoke-serial.sh"
smoke_resolve_php
smoke_serialize
exec "${PHP[@]}" bin/smoke-runtime-live.php
