#!/usr/bin/env bash
# Compila o músculo Go em binário estático (roda em qualquer Ubuntu, sem libc nova).
#
#   bash bin/lb-go-build.sh              # build local -> lb-go/dist/lb-go
#   VERSION=v1.2 bash bin/lb-go-build.sh
set -euo pipefail
cd "$(dirname "$0")/../lb-go"

if ! command -v go >/dev/null 2>&1; then
  echo "[erro] Go não instalado. Ubuntu 22.04: sudo apt-get install -y golang-go (>=1.21) ou use o tarball oficial." >&2
  exit 2
fi

VERSION="${VERSION:-$(git rev-parse --short HEAD 2>/dev/null || echo dev)}"
mkdir -p dist

echo "== vet"
go vet ./...
echo "== testes (paridade de regra com o PHP)"
go test ./...
echo "== build $VERSION"
CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.Version=$VERSION" -o dist/lb-go ./cmd/lb-go

ls -lh dist/lb-go
echo "[ok] binário pronto: lb-go/dist/lb-go"