#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
BASE="$ROOT/proxy-mago-base"

cd "$ROOT"

if [ -n "$(git status --short)" ]; then
  echo "[fail] worktree suja. Commit/stash antes de publicar no backup." >&2
  git status --short
  exit 1
fi

if ! git remote get-url assets >/dev/null 2>&1; then
  echo "[fail] remote 'assets' não configurado" >&2
  exit 1
fi

echo "== fetch assets =="
git fetch assets

echo "== validação antes do backup =="
"$BASE/bin/git-validate-release.sh" backup

HEAD_SHA="$(git rev-parse HEAD)"
echo "== push HEAD -> assets/backup =="
git push assets "$HEAD_SHA:refs/heads/backup"

echo "[ok] backup atualizado em assets/backup => $(git ls-remote --heads assets backup | awk '{print $1}')"
echo "[ok] próximo passo: $BASE/bin/git-promote-backup-to-main.sh"
