#!/usr/bin/env bash
# Capture low-cardinality JSONL resource samples while one reviewed migration job runs.
set -euo pipefail
compose_command=${COMPOSE:-docker compose}
job=${NBE_PERF_MIGRATION_JOB_ID:-}
output=${NBE_PERF_MIGRATION_OUTPUT:-migration-performance.jsonl}
max_seconds=${NBE_PERF_MIGRATION_MAX_SECONDS:-3600}
[[ "$job" =~ ^[a-f0-9-]{36}$ ]] || { echo 'Set NBE_PERF_MIGRATION_JOB_ID to a queued job UUID.' >&2; exit 2; }
[[ "$max_seconds" =~ ^[0-9]+$ ]] || { echo 'NBE_PERF_MIGRATION_MAX_SECONDS must be an integer.' >&2; exit 2; }
app=$($compose_command ps -q app)
worker=$($compose_command ps -q worker)
db=$($compose_command ps -q db)
test -n "$app" && test -n "$worker" && test -n "$db"
started=$(date +%s)
printf '{"event":"start","captured_at":"%s","job":"%s","max_seconds":%s}\n' "$(date -u +%FT%TZ)" "$job" "$max_seconds" > "$output"
while true; do
  docker stats --no-stream --format '{{json .}}' "$app" "$worker" "$db" >> "$output"
  state=$(docker exec -e NBE_PERF_JOB_ID="$job" "$app" php -r 'require "/var/www/html/wp-load.php";global $wpdb;echo (string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$wpdb->base_prefix}nbe_jobs WHERE id=%s",getenv("NBE_PERF_JOB_ID")));')
  case "$state" in
    complete|needs_attention|failed) break ;;
    intake|awaiting_author_mapping) echo "Job cannot run in state $state." >&2; exit 1 ;;
    queued|running) ;;
    *) echo 'Job is missing or has an unknown state.' >&2; exit 1 ;;
  esac
  if [ $(( $(date +%s) - started )) -ge "$max_seconds" ]; then
    printf '{"event":"timeout","elapsed_seconds":%s}\n' "$(( $(date +%s) - started ))" >> "$output"
    exit 1
  fi
  sleep 5
done
elapsed=$(( $(date +%s) - started ))
printf '{"event":"finish","captured_at":"%s","state":"%s","elapsed_seconds":%s}\n' "$(date -u +%FT%TZ)" "$state" "$elapsed" >> "$output"
test "$state" != failed
echo "Migration performance evidence written to $output; inspect CPU, memory, I/O and completion state."
