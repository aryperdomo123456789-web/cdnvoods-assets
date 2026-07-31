#!/usr/bin/env bash
# Smoke S2-P0-5: ensaio de corte da trilha quente para PostgreSQL.
#
# Precisa de um Postgres de laboratorio. Sem ele, o smoke SAI COMO SKIP
# (nao inventa prova). Configure por ambiente:
#
#   PROXY_MAGO_DB_DRIVER=pgsql PROXY_MAGO_DB_HOST=127.0.0.1 \
#   PROXY_MAGO_DB_PORT=5432 PROXY_MAGO_DB_NAME=proxy_mago \
#   PROXY_MAGO_DB_USER=proxy_mago PROXY_MAGO_DB_PASS=... \
#   bash bin/smoke-pg-cut.sh
set -uo pipefail
cd "$(dirname "$0")/.."
. bin/lib/smoke-serial.sh
smoke_resolve_php
smoke_serialize
PHP_BIN="${PHP_BIN:-$PHP_RESOLVED}"
[ -n "$PHP_BIN" ] || { echo "PHP nao encontrado (exporte PHP_BIN)" >&2; exit 2; }

if [ "${PROXY_MAGO_DB_DRIVER:-}" != "pgsql" ] || [ -z "${PROXY_MAGO_DB_HOST:-}" ]; then
  echo "  [skip] sem Postgres de laboratorio (defina PROXY_MAGO_DB_DRIVER=pgsql e PROXY_MAGO_DB_HOST)"
  echo "== pg-cut: 0 ok / 0 falhas (skip)"
  exit 0
fi

if ! "$PHP_BIN" -m | grep -qi pdo_pgsql; then
  echo "  [skip] PHP sem extensao pdo_pgsql ($PHP_BIN)"
  echo "== pg-cut: 0 ok / 0 falhas (skip)"
  exit 0
fi

echo "== 1/3 schema quente no destino"
"$PHP_BIN" bin/pg-migrate.php || exit 1

echo
echo "== 2/3 plano do corte (dry-run, nao escreve)"
"$PHP_BIN" bin/pg-cut.php --dry-run || exit 1

echo
echo "== 3/3 corte real no laboratorio (truncate + copia + paridade)"
"$PHP_BIN" bin/pg-cut.php --fresh || exit 1

echo
echo "== 4/4 idempotencia: repetir o corte nao duplica linha"
"$PHP_BIN" bin/pg-cut.php --fresh || exit 1

echo "== pg-cut: ensaio completo OK"
