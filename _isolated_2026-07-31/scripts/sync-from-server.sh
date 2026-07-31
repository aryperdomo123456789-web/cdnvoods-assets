#!/usr/bin/env bash
set -euo pipefail

SERVER_HOST="${SERVER_HOST:-45.140.192.237}"
SERVER_PATH="${SERVER_PATH:-/opt/proxy-mago}"

rsync -az \
  --exclude '.git' \
  root@"${SERVER_HOST}":"${SERVER_PATH}/" ./

