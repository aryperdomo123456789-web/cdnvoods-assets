#!/usr/bin/env bash
# Smoke test do módulo de restreamento — /opt/proxy-mago/proxy-mago-base (VPS 45.140.192.237)
set -uo pipefail

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$BASE"
. "$(cd "$(dirname "$0")" && pwd)/lib/smoke-serial.sh"
smoke_resolve_php
smoke_serialize
PHP="${PHP_BIN:-php}"
fails=0

check() { if [ "$2" = "0" ]; then echo "  [ok]   $1"; else echo "  [FAIL] $1"; fails=$((fails+1)); fi; }

echo "==> 1. schema local"
$PHP -r '
require "'"$BASE"'/app/bootstrap-cli.php";
$need = ["xui_sync_config","xui_users_cache","xui_streams_cache","xui_activity_now_cache",
         "proxy_request_events","proxy_session_links","proxy_user_runtime","job_runs","job_state"];
$have = array_column(Database::pdo()->query("SELECT name FROM sqlite_master WHERE type=\"table\"")->fetchAll(), "name");
$miss = array_diff($need, $have);
if ($miss) { fwrite(STDERR, "faltando: ".implode(",", $miss)."\n"); exit(1); }
exit(0);'
check "todas as tabelas de restreamento existem" $?

echo "==> 2. catálogo de jobs"
timeout 15s $PHP bin/jobs-run.php --list >/dev/null 2>&1
check "bin/jobs-run.php --list" $?

echo "==> 3. tick de jobs locais (sem XUI)"
timeout 20s $PHP bin/jobs-run.php --job=consolidate_runtime --force >/dev/null 2>&1
check "consolidate_runtime" $?
timeout 20s $PHP bin/jobs-run.php --job=detect_inconsistency --force >/dev/null 2>&1
check "detect_inconsistency" $?
timeout 25s $PHP bin/jobs-run.php --job=cleanup --force >/dev/null 2>&1
check "cleanup" $?

echo "==> 4. driver MySQL (opcional, só para espelho do XUI)"
if $PHP -m | grep -qi '^pdo_mysql$'; then
  echo "  [ok]   pdo_mysql presente"
  timeout 15s $PHP bin/xui-sync.php --test >/dev/null 2>&1
  if [ $? -eq 0 ]; then echo "  [ok]   conexão read-only com o XUI"; else echo "  [warn] XUI inacessível — stream não é afetado"; fi
else
  echo "  [warn] pdo_mysql ausente: apt-get install -y php8.1-mysql && phpenmod pdo_mysql"
fi

echo "==> 5. independência do stream em relação ao XUI"
$PHP -r '
require "'"$BASE"'/app/bootstrap-cli.php";
// nenhuma rota pública pode instanciar o conector MySQL
$src = file_get_contents("'"$BASE"'/public/proxy.php");
exit(str_contains($src, "XuiReadOnly") ? 1 : 0);'
check "public/proxy.php não toca no MySQL do XUI" $?

echo "==> 6. cron instalado"
[ -f /etc/cron.d/proxy-mago-jobs ] && echo "  [ok]   /etc/cron.d/proxy-mago-jobs" || echo "  [warn] cron não instalado — rode bash bin/deploy.sh"

echo
if [ "$fails" -eq 0 ]; then echo "SMOKE RESTREAMENTO: OK"; exit 0; fi
echo "SMOKE RESTREAMENTO: $fails falha(s)"; exit 1
