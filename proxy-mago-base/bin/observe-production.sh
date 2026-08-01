#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

LB_IP="${1:-143.14.168.78}"
LB_HOST_HEADER="${2:-voods.suafontee.com}"
BRAIN_HOST_HEADER="${3:-cdnvoods.vr766.com}"
LB_SSH_HOST="${LB_SSH_HOST:-143.14.168.78}"
LB_TOKEN="${LB_TOKEN:-23c9de0e6750012b4fc3665d1b153462e92dffede18d98cd}"
OUT_DIR="${OUT_DIR:-storage/logs/production-observe}"

mkdir -p "$OUT_DIR"

ts_utc="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
ts_epoch="$(date -u +%s)"
tmp_dir="$(mktemp -d)"
trap 'rmdir "$tmp_dir" 2>/dev/null || true' EXIT

contract_json="$tmp_dir/contract.json"
health_json="$tmp_dir/health.json"
playlist_head="$tmp_dir/playlist.txt"

curl -fsS --max-time 20 \
  -H "Host: ${BRAIN_HOST_HEADER}" \
  -H "X-LB-Token: ${LB_TOKEN}" \
  http://127.0.0.1/lb-contract.php > "$contract_json"

ssh "root@${LB_SSH_HOST}" \
  "curl -fsS -H 'Host: ${LB_HOST_HEADER}' http://127.0.0.1/healthz" > "$health_json"

ssh "root@${LB_SSH_HOST}" "python3 - <<'PY'
import urllib.request

req = urllib.request.Request(
    'http://127.0.0.1/get.php?username=1111&password=1111&type=m3u_plus&output=ts',
    headers={'Host': '${LB_HOST_HEADER}', 'User-Agent': 'curl/8.5.0'},
)
with urllib.request.urlopen(req, timeout=20) as resp:
    chunk = resp.read(4096).decode('utf-8', 'replace')
print('\n'.join(chunk.splitlines()[:6]))
PY" > "$playlist_head"

pg_divergences="$(sudo -u postgres psql -d proxy_mago -Atqc "SELECT count(*) FROM cdn_divergences;" 2>/dev/null || echo -1)"
pg_proxy_events="$(sudo -u postgres psql -d proxy_mago -Atqc "SELECT count(*) FROM proxy_request_events;" 2>/dev/null || echo -1)"
pg_job_runs="$(sudo -u postgres psql -d proxy_mago -Atqc "SELECT count(*) FROM job_runs;" 2>/dev/null || echo -1)"
pg_job_steps="$(sudo -u postgres psql -d proxy_mago -Atqc "SELECT count(*) FROM job_step_history;" 2>/dev/null || echo -1)"
pg_audit="$(sudo -u postgres psql -d proxy_mago -Atqc "SELECT count(*) FROM cdn_audit_timeline;" 2>/dev/null || echo -1)"

sqlite_divergences="$(sqlite3 storage/app.sqlite "SELECT count(*) FROM cdn_divergences;" 2>/dev/null || echo -1)"
sqlite_proxy_events="$(sqlite3 storage/app.sqlite "SELECT count(*) FROM proxy_request_events;" 2>/dev/null || echo -1)"
sqlite_job_runs="$(sqlite3 storage/app.sqlite "SELECT count(*) FROM job_runs;" 2>/dev/null || echo -1)"
sqlite_job_steps="$(sqlite3 storage/app.sqlite "SELECT count(*) FROM job_step_history;" 2>/dev/null || echo -1)"
sqlite_audit="$(sqlite3 storage/app.sqlite "SELECT count(*) FROM cdn_audit_timeline;" 2>/dev/null || echo -1)"

pg_cut_pid="$(pgrep -f 'php .*bin/pg-cut.php' | head -n1 || true)"
if [ -n "$pg_cut_pid" ]; then
  pg_cut_running=true
  pg_cut_ps="$(ps -o etime=,time=,pcpu=,rss=,cmd= -p "$pg_cut_pid" | sed 's/^[[:space:]]*//')"
else
  pg_cut_running=false
  pg_cut_ps=""
fi

php_error_tail="$(tail -n 20 storage/logs/php-error.log 2>/dev/null || true)"
worker_error_tail="$(tail -n 20 storage/logs/php-fpm-worker.log 2>/dev/null || true)"

jq -n \
  --arg ts_utc "$ts_utc" \
  --argjson ts_epoch "$ts_epoch" \
  --slurpfile contract "$contract_json" \
  --slurpfile health "$health_json" \
  --arg playlist_head "$(cat "$playlist_head")" \
  --argjson pg_divergences "${pg_divergences}" \
  --argjson pg_proxy_events "${pg_proxy_events}" \
  --argjson pg_job_runs "${pg_job_runs}" \
  --argjson pg_job_steps "${pg_job_steps}" \
  --argjson pg_audit "${pg_audit}" \
  --argjson sqlite_divergences "${sqlite_divergences}" \
  --argjson sqlite_proxy_events "${sqlite_proxy_events}" \
  --argjson sqlite_job_runs "${sqlite_job_runs}" \
  --argjson sqlite_job_steps "${sqlite_job_steps}" \
  --argjson sqlite_audit "${sqlite_audit}" \
  --argjson pg_cut_running "$( [ "$pg_cut_running" = true ] && echo true || echo false )" \
  --arg pg_cut_ps "$pg_cut_ps" \
  --arg php_error_tail "$php_error_tail" \
  --arg worker_error_tail "$worker_error_tail" \
  '{
    ts_utc: $ts_utc,
    ts_epoch: $ts_epoch,
    contract: $contract[0],
    lb_health: $health[0],
    playlist_head: $playlist_head,
    cold_pg: {
      cdn_divergences: $pg_divergences,
      proxy_request_events: $pg_proxy_events,
      job_runs: $pg_job_runs,
      job_step_history: $pg_job_steps,
      cdn_audit_timeline: $pg_audit
    },
    cold_sqlite: {
      cdn_divergences: $sqlite_divergences,
      proxy_request_events: $sqlite_proxy_events,
      job_runs: $sqlite_job_runs,
      job_step_history: $sqlite_job_steps,
      cdn_audit_timeline: $sqlite_audit
    },
    pg_cut: {
      running: $pg_cut_running,
      ps: $pg_cut_ps
    },
    log_tails: {
      php_error: $php_error_tail,
      php_fpm_worker: $worker_error_tail
    }
  }' > "$OUT_DIR/${ts_epoch}.json"

ln -sfn "${ts_epoch}.json" "$OUT_DIR/latest.json"
cat "$OUT_DIR/${ts_epoch}.json"
