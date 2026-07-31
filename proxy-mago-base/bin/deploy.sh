#!/usr/bin/env bash
# Deploy na VPS real: 45.140.192.237 (Ubuntu 22.04)
# Uso: sudo bash /opt/proxy-mago/proxy-mago-base/bin/deploy.sh
set -euo pipefail

BASE=/opt/proxy-mago/proxy-mago-base
SOCK=/run/php/php8.1-fpm.sock

cd "$BASE"
echo "==> git pull"
git pull --ff-only

echo "==> permissões"
mkdir -p storage/logs storage/cache
chown -R www-data:www-data storage
chmod -R 775 storage
find app public bin config -type f -name '*.php' -exec chmod 644 {} \;

echo "==> lint PHP"
find app public bin config -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

echo "==> desliga filtro de User-Agent (compatibilidade com todos os apps)"
php -r '
require "'"$BASE"'/app/Config.php";
require "'"$BASE"'/app/Database.php";
require "'"$BASE"'/app/SettingsRepository.php";
SettingsRepository::set("ua_filter_enabled", 0);
SettingsRepository::set("allowed_user_agent", "");
SettingsRepository::set("follow_external_redirects", 1); // filme/serie via direct source
SettingsRepository::set("log_segments", 0);              // 1 INSERT por .ts derruba a VPS
echo "ok\n";
'

echo "==> aplica migrações do schema (uma vez por deploy, não por request)"
php -r '
require "'"$BASE"'/app/Config.php";
require "'"$BASE"'/app/Database.php";
Database::migrateNow();
echo "schema ok\n";
'

echo "==> limpa caches curtos do painel (health/probe de binários)"
rm -f "$BASE"/storage/cache/health.json "$BASE"/storage/cache/bin-*.txt "$BASE"/storage/cache/agg-*.json 2>/dev/null || true

echo "==> memória do PHP-FPM (rede de segurança; o rewrite agora é streaming)"
POOL=/etc/php/8.1/fpm/pool.d/www.conf
grep -q 'php_admin_value\[memory_limit\]' "$POOL" \
  || echo 'php_admin_value[memory_limit] = 256M' >> "$POOL"

echo "==> nginx"
nginx -t
systemctl reload nginx
systemctl reload php8.1-fpm

echo "==> jobs internos (observabilidade / restreamento)"
php "$BASE/bin/jobs-run.php" --list
CRON=/etc/cron.d/proxy-mago-jobs
cat > "$CRON" <<CRONEOF
# Ticks de jobs do painel de restreamento — gerado por bin/deploy.sh
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
# Perfil rápido: sessões, runtime e telemetria leve
* * * * * www-data /usr/bin/php $BASE/bin/jobs-run.php --profile=fast >> $BASE/storage/logs/jobs-fast.log 2>&1
# Perfil pesado: syncs e consolidações que não podem disputar com o player toda hora
*/5 * * * * www-data /usr/bin/php $BASE/bin/jobs-run.php --profile=heavy --loop=25 >> $BASE/storage/logs/jobs-heavy.log 2>&1
CRONEOF
chmod 644 "$CRON"
touch "$BASE/storage/logs/jobs-fast.log" "$BASE/storage/logs/jobs-heavy.log"
chown www-data:www-data "$BASE/storage/logs/jobs-fast.log" "$BASE/storage/logs/jobs-heavy.log"
systemctl reload cron 2>/dev/null || systemctl restart cron 2>/dev/null || true

if php -m | grep -qi '^pdo_mysql$'; then
  echo "    pdo_mysql: ok (sync read-only do XUI disponível)"
else
  echo "    pdo_mysql: AUSENTE — para espelhar o XUI rode:"
  echo "      apt-get install -y php8.1-mysql && phpenmod pdo_mysql mysqli && systemctl reload php8.1-fpm"
fi

echo "==> smoke test"
chmod +x bin/smoke-test.sh
if [ "${SMOKE_DOMAIN:-}" != "" ] && [ "${SMOKE_USER:-}" != "" ]; then
  bash bin/smoke-test.sh "$SMOKE_DOMAIN" "$SMOKE_USER" "${SMOKE_PASS:-}" || true
else
  curl -s -o /dev/null -w 'proxy http -> %{http_code}\n' \
    -H 'Host: voods.suafontee.com' \
    'http://127.0.0.1/get.php?username=TESTE&password=TESTE&type=m3u_plus&output=mpegts' || true
  echo "(dica: SMOKE_DOMAIN=... SMOKE_USER=... SMOKE_PASS=... para o teste completo)"
fi

echo "Deploy concluído."
