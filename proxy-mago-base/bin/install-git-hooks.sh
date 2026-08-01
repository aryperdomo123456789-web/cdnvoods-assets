#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$ROOT/.githooks"

if [ ! -f "$HOOKS_DIR/pre-push" ]; then
  echo "[fail] hook não encontrado em $HOOKS_DIR/pre-push" >&2
  exit 1
fi

chmod +x "$HOOKS_DIR/pre-push"
git config core.hooksPath "$HOOKS_DIR"

echo "[ok] core.hooksPath => $HOOKS_DIR"
echo "[ok] push direto para assets/main agora é bloqueado por padrão"
