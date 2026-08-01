#!/usr/bin/env bash
set -euo pipefail

PROFILE="${1:-backup}"
ROOT="$(git rev-parse --show-toplevel)"
BASE="$ROOT/proxy-mago-base"

if [ ! -d "$BASE" ]; then
  echo "[fail] projeto proxy-mago-base não encontrado em $BASE" >&2
  exit 1
fi

cd "$BASE"

case "$PROFILE" in
  backup|promote) ;;
  *)
    echo "uso: $0 [backup|promote]" >&2
    exit 1
    ;;
esac

echo "== validação de release ($PROFILE) =="
echo "repo: $ROOT"
echo "commit: $(git -C "$ROOT" rev-parse --short HEAD)"

echo "== php -l =="
find app public bin config -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "[ok] sintaxe PHP"

echo "== smoke-statestore =="
php bin/smoke-statestore.php

echo "== smoke-lb-only =="
php bin/smoke-lb-only.php

if [ "$PROFILE" = "promote" ]; then
  echo "== smoke-all =="
  bash bin/smoke-all.sh
fi

echo "[ok] validação $PROFILE concluída"
