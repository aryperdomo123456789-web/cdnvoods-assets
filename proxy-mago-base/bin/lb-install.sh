#!/usr/bin/env bash
# Instalador do MÚSCULO (LB) — enviado pelo cérebro via SSH.
# Placeholders __XXX__ são substituídos por LbInstaller::renderInstaller().
set -euo pipefail

PROFILE="__PROFILE__"
WORKERS="__WORKERS__"
CONNECTIONS="__CONNECTIONS__"
FPM_CHILDREN="__FPM_CHILDREN__"
FPM_START="__FPM_START__"
FPM_MIN="__FPM_MIN__"
FPM_MAX="__FPM_MAX__"

LB_DIR="/opt/proxy-mago-lb"
PKG="/root/proxy-mago-lb-package.b64"

log() { echo "[lb] $*"; }

php_version() {
  php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo ""
}

fpm_service() {
  local v; v="$(php_version)"
  if [ -n "$v" ] && systemctl list-unit-files | grep -q "php${v}-fpm.service"; then
    echo "php${v}-fpm"
  else
    systemctl list-unit-files | awk '/php.*-fpm\.service/{print $1; exit}' | sed 's/\.service//'
  fi
}

bootstrap() {
  log "perfil=$PROFILE workers=$WORKERS"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y nginx curl tar gzip ca-certificates
  apt-get install -y php-fpm php-cli php-curl php-sqlite3 php-mbstring php-xml
  mkdir -p "$LB_DIR"/{app,public,config,storage/logs,storage/cache}
  systemctl enable nginx >/dev/null 2>&1 || true
  log "bootstrap ok: $(nginx -v 2>&1) / php $(php_version)"
}

package() {
  [ -f "$PKG" ] || { echo "pacote ausente em $PKG"; exit 1; }
  mkdir -p "$LB_DIR"
  base64 -d "$PKG" | gzip -d | tar -x -C "$LB_DIR"
  mkdir -p "$LB_DIR/storage/logs" "$LB_DIR/storage/cache"
  chown -R www-data:www-data "$LB_DIR/storage"
  chmod 750 "$LB_DIR/storage"
  find "$LB_DIR/app" "$LB_DIR/public" -type f -name '*.php' -exec php -l {} \; >/dev/null
  log "pacote aplicado: $(find "$LB_DIR" -name '*.php' | wc -l) arquivos php"
}

configure() {
  local svc sock
  svc="$(fpm_service)"
  sock="/run/php/$(php_version)-fpm.sock"
  [ -S "$sock" ] || sock="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"

  # ---- tuning nginx por perfil ----
  cat > /etc/nginx/conf.d/proxy-mago-lb-tuning.conf <<EOF
worker_processes ${WORKERS};
worker_rlimit_nofile 65535;
EOF
  sed -i "s/^worker_processes.*/worker_processes ${WORKERS};/" /etc/nginx/nginx.conf || true
  sed -i "s/worker_connections.*/worker_connections ${CONNECTIONS};/" /etc/nginx/nginx.conf || true
  # o bloco de tuning global não pode declarar worker_processes dentro de conf.d
  rm -f /etc/nginx/conf.d/proxy-mago-lb-tuning.conf

  # ---- vhost do músculo: só proxy, nada de painel ----
  cat > /etc/nginx/sites-available/proxy-mago-lb.conf <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    root ${LB_DIR}/public;
    index proxy.php;

    access_log off;
    error_log /var/log/nginx/proxy-mago-lb.error.log warn;

    sendfile on;
    tcp_nodelay on;
    tcp_nopush on;
    keepalive_timeout 20s;
    client_body_buffer_size 16k;
    client_max_body_size 4m;

    location = /__lb_health {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${LB_DIR}/public/health.php;
        fastcgi_param SCRIPT_NAME /health.php;
        fastcgi_pass unix:${sock};
    }

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${LB_DIR}/public/proxy.php;
        fastcgi_param SCRIPT_NAME /proxy.php;
        fastcgi_param PATH_INFO \$uri;
        fastcgi_param QUERY_STRING \$query_string;
        fastcgi_pass unix:${sock};
        fastcgi_buffering off;
        fastcgi_read_timeout 3600s;
        fastcgi_send_timeout 3600s;
        proxy_buffering off;
    }
}
EOF
  ln -sf /etc/nginx/sites-available/proxy-mago-lb.conf /etc/nginx/sites-enabled/proxy-mago-lb.conf
  rm -f /etc/nginx/sites-enabled/default

  # ---- php-fpm enxuto ----
  local pooldir
  pooldir="$(dirname "$(ls /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -n1)")"
  if [ -n "$pooldir" ] && [ -d "$pooldir" ]; then
    cat > "$pooldir/zz-proxy-mago-lb.conf" <<EOF
[www]
pm = dynamic
pm.max_children = ${FPM_CHILDREN}
pm.start_servers = ${FPM_START}
pm.min_spare_servers = ${FPM_MIN}
pm.max_spare_servers = ${FPM_MAX}
pm.max_requests = 800
request_terminate_timeout = 3600s
php_admin_value[memory_limit] = 128M
php_admin_value[expose_php] = Off
EOF
  fi

  nginx -t
  systemctl reload nginx
  [ -n "$svc" ] && systemctl restart "$svc" || true
  log "configure ok (perfil=$PROFILE, fpm=$svc, socket=$sock)"
}

case "${1:-}" in
  bootstrap) bootstrap ;;
  package)   package ;;
  configure) configure ;;
  all)       bootstrap; package; configure ;;
  *) echo "uso: $0 {bootstrap|package|configure|all}"; exit 2 ;;
esac
