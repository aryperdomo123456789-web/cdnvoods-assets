#!/usr/bin/env bash
# Instala/atualiza o músculo Go em UM nó de LB (canário primeiro, sempre).
#
#   bash bin/lb-go-deploy.sh <ip-do-lb> <token-do-no> <url-do-cerebro> [porta]
#
# O que faz, na ordem: copia binário -> escreve /etc/cdnvoods-lb.env ->
# valida contrato com `lb-go -check` -> só então sobe o serviço. Se o -check
# falhar, NADA é ativado: o nó continua servindo com o que já estava lá.
set -euo pipefail
cd "$(dirname "$0")/.."

LB_IP="${1:?uso: lb-go-deploy.sh <ip> <token> <brain_url> [porta]}"
LB_TOKEN="${2:?token do nó (lb_nodes.token)}"
BRAIN_URL="${3:?url do cérebro, ex.: https://painel.exemplo.com}"
PORT="${4:-8081}"
SSH_OPTS="-o StrictHostKeyChecking=accept-new -o ConnectTimeout=10"

[ -x lb-go/dist/lb-go ] || bash bin/lb-go-build.sh

echo "== enviando binário para $LB_IP"
scp $SSH_OPTS lb-go/dist/lb-go "root@$LB_IP:/usr/local/bin/lb-go.new"
scp $SSH_OPTS lb-go/systemd/lb-go.service "root@$LB_IP:/etc/systemd/system/lb-go.service"

ssh $SSH_OPTS "root@$LB_IP" LB_TOKEN="$LB_TOKEN" BRAIN_URL="$BRAIN_URL" PORT="$PORT" 'bash -s' <<'REMOTE'
set -euo pipefail
id -u cdnvoods >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin cdnvoods
mkdir -p /var/log/cdnvoods && chown cdnvoods:cdnvoods /var/log/cdnvoods

umask 077
cat > /etc/cdnvoods-lb.env <<ENV
LB_LISTEN=127.0.0.1:${PORT}
LB_BRAIN_URL=${BRAIN_URL}
LB_TOKEN=${LB_TOKEN}
LB_REDIS_HOST=${LB_REDIS_HOST:-127.0.0.1}
LB_REDIS_PORT=${LB_REDIS_PORT:-6379}
LB_REDIS_PASS=${LB_REDIS_PASS:-}
LB_REDIS_DB=${LB_REDIS_DB:-0}
LB_PUBLIC_SCHEME=${LB_PUBLIC_SCHEME:-http}
ENV
chown root:cdnvoods /etc/cdnvoods-lb.env && chmod 640 /etc/cdnvoods-lb.env

mv /usr/local/bin/lb-go.new /usr/local/bin/lb-go
chmod 755 /usr/local/bin/lb-go

echo "== validando contrato antes de ativar"
set +e
env $(grep -v '^#' /etc/cdnvoods-lb.env | xargs) /usr/local/bin/lb-go -check
rc=$?
set -e
if [ $rc -ne 0 ]; then
  echo "[FAIL] snapshot do contrato não validou; serviço NÃO foi ativado." >&2
  exit $rc
fi

systemctl daemon-reload
systemctl enable --now lb-go
sleep 1
systemctl is-active --quiet lb-go && echo "[ok] lb-go ativo" || { journalctl -u lb-go -n 30 --no-pager; exit 1; }
curl -fsS "http://127.0.0.1:${PORT}/healthz" && echo
REMOTE

echo "[ok] músculo Go no ar em $LB_IP:$PORT (aponte o upstream do Nginx do nó para essa porta)"