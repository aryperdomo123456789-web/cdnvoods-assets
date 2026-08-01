#!/usr/bin/env bash
# Paridade do MÚSCULO GO com a regra do cérebro (contrato v1).
#
# Não toca em SQLite: valida o motor Go isoladamente (vet + testes de trava de
# IP com CIDR, expiração, resolução de alias, mascaramento de origem, direct
# source e compat de major). Sem Go instalado, o smoke AVISA e passa — a VPS do
# cérebro não precisa de Go, só a máquina que compila o binário.
set -uo pipefail
cd "$(dirname "$0")/../lb-go"

if ! command -v go >/dev/null 2>&1; then
  echo "  [skip] Go não instalado nesta máquina — motor Go não verificado aqui."
  echo "== smoke lb-go: 0 falhas (pulado)"
  exit 0
fi

fails=0
echo "== go vet"
go vet ./... || fails=$((fails+1))
echo "== go test"
go test ./... || fails=$((fails+1))
echo "== build estático"
CGO_ENABLED=0 go build -trimpath -o /tmp/lb-go-smoke ./cmd/lb-go || fails=$((fails+1))

# O motor não pode subir sem coordenadas do cérebro: falhar aqui é o esperado.
if LB_BRAIN_URL= LB_TOKEN= /tmp/lb-go-smoke -check >/dev/null 2>&1; then
  echo "  [FAIL] motor aceitou subir sem LB_BRAIN_URL/LB_TOKEN"
  fails=$((fails+1))
else
  echo "  [ok]   motor recusa subir sem coordenadas do cérebro"
fi

rm -f /tmp/lb-go-smoke
printf '== smoke lb-go: %d falhas\n' "$fails"
[ "$fails" -eq 0 ] || exit 1