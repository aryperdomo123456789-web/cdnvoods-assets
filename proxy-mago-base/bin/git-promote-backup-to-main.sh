#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
BASE="$ROOT/proxy-mago-base"
TMP=""

cleanup() {
  if [ -n "$TMP" ] && [ -d "$TMP" ]; then
    git -C "$ROOT" worktree remove --force "$TMP" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

cd "$ROOT"

if [ -n "$(git status --short)" ]; then
  echo "[fail] worktree suja. Limpe antes do promote." >&2
  git status --short
  exit 1
fi

if ! git remote get-url assets >/dev/null 2>&1; then
  echo "[fail] remote 'assets' não configurado" >&2
  exit 1
fi

echo "== fetch assets =="
git fetch assets

BACKUP_SHA="$(git rev-parse assets/backup)"
MAIN_SHA="$(git rev-parse assets/main)"

if [ "$BACKUP_SHA" = "$MAIN_SHA" ]; then
  echo "[ok] assets/backup e assets/main já estão no mesmo commit ($BACKUP_SHA)"
  exit 0
fi

if ! git merge-base --is-ancestor assets/main assets/backup; then
  echo "[fail] assets/backup não contém assets/main em fast-forward seguro" >&2
  echo "       main:   $MAIN_SHA"
  echo "       backup: $BACKUP_SHA"
  exit 1
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/proxy-mago-promote.XXXXXX")"
echo "== worktree temporário =="
git worktree add --detach "$TMP" "$BACKUP_SHA" >/dev/null

echo "== validação do commit de backup =="
"$TMP/proxy-mago-base/bin/git-validate-release.sh" promote

echo "== promote assets/backup -> assets/main =="
ALLOW_PUSH_MAIN=1 git push assets "$BACKUP_SHA:refs/heads/main"

echo "[ok] promote concluído"
echo "     backup: $BACKUP_SHA"
echo "     main:   $(git ls-remote --heads assets main | awk '{print $1}')"
