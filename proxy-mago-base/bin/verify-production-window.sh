#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

WINDOW_HOURS="${1:-24}"
OBS_DIR="${OBS_DIR:-storage/logs/production-observe}"

if ! [[ "$WINDOW_HOURS" =~ ^[0-9]+$ ]] || [ "$WINDOW_HOURS" -le 0 ]; then
  echo '{"ok":false,"error":"window_hours_invalido"}'
  exit 2
fi

now_epoch="$(date -u +%s)"
cut_epoch="$((now_epoch - WINDOW_HOURS * 3600))"

latest_file="$(find "$OBS_DIR" -maxdepth 1 -type f -name '*.json' | sort | tail -n1 || true)"
if [ -z "$latest_file" ]; then
  echo '{"ok":false,"error":"sem_observacoes"}'
  exit 1
fi

jq -s --argjson cut "$cut_epoch" --argjson hours "$WINDOW_HOURS" '
  map(select(.ts_epoch >= $cut)) as $rows
  | if ($rows | length) == 0 then
      {
        ok: false,
        window_hours: $hours,
        error: "janela_sem_amostras"
      }
    else
      ($rows[0].ts_epoch) as $first_epoch
      | ($rows[-1].ts_epoch) as $last_epoch
      | ((now | floor) - $first_epoch) as $age_from_first
      | {
        ok:
          (
            ($rows | length) > 0
            and ($age_from_first >= ($hours * 3600))
            and ($rows | all(.contract.state.effective_driver == "redis"))
            and ($rows | all(.contract.state.degraded == false))
            and ($rows | all(.lb_health.ok == true))
            and ($rows | all(.lb_health.state_degraded == false))
            and ($rows | all((.playlist_head | startswith("#EXTM3U"))))
            and ($rows | all((.log_tails.php_error | test("database is locked") | not)))
            and ($rows | all((.log_tails.php_fpm_worker | test("database is locked") | not)))
          ),
        window_hours: $hours,
        samples: ($rows | length),
        first_sample_utc: ($rows[0].ts_utc),
        last_sample_utc: ($rows[-1].ts_utc),
        covered_full_window: ($age_from_first >= ($hours * 3600)),
        checks: {
          full_window_elapsed: ($age_from_first >= ($hours * 3600)),
          redis_effective: ($rows | all(.contract.state.effective_driver == "redis")),
          contract_not_degraded: ($rows | all(.contract.state.degraded == false)),
          lb_health_ok: ($rows | all(.lb_health.ok == true)),
          lb_not_degraded: ($rows | all(.lb_health.state_degraded == false)),
          playlist_ok: ($rows | all((.playlist_head | startswith("#EXTM3U")))),
          no_locked_in_php_error_tail: ($rows | all((.log_tails.php_error | test("database is locked") | not))),
          no_locked_in_worker_tail: ($rows | all((.log_tails.php_fpm_worker | test("database is locked") | not)))
        }
      }
    end
' "$OBS_DIR"/*.json
