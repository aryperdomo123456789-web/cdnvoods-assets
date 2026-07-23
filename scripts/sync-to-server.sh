#!/usr/bin/env bash
set -euo pipefail

SERVER_HOST="${SERVER_HOST:-45.140.192.237}"
SERVER_PATH="${SERVER_PATH:-/opt/proxy-mago}"

rsync -az --delete \
  --exclude '.git' \
  --exclude 'id_github_cdnvoods' \
  --exclude 'id_github_cdnvoods.pub' \
  --exclude 'proxy-mago-base/storage/app.sqlite' \
  --exclude 'proxy-mago-base/storage/local.config.php' \
  --exclude 'proxy-mago-base/storage/logs/' \
  --exclude 'proxy-mago-base/storage/cache/' \
  ./ root@"${SERVER_HOST}":"${SERVER_PATH}"

