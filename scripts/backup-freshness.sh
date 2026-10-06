#!/usr/bin/env sh
set -eu
. /scripts/restic-env.sh
load_restic_backend_env
max_hours=${BACKUP_MAX_AGE_HOURS:-26}
case "$max_hours" in *[!0-9]*|'') echo 'BACKUP_MAX_AGE_HOURS must be an integer' >&2; exit 2 ;; esac
[ -r /status/last-success ] || { echo 'Backup freshness failed: no successful backup marker.' >&2; exit 1; }
# The backup image uses BusyBox date, which cannot parse the ISO-8601 T/Z
# form emitted by backup.sh. Parse the UTC marker explicitly.
marker=$(sed 's/T/ /; s/Z$//' /status/last-success)
saved=$(date -u -d "$marker" +%s 2>/dev/null || true)
[ -n "$saved" ] || { echo 'Backup freshness failed: invalid success marker.' >&2; exit 1; }
age=$(( $(date +%s) - saved ))
[ "$age" -le $((max_hours * 3600)) ] || { echo "Backup freshness failed: last success is $((age / 3600)) hours old." >&2; exit 1; }
restic snapshots --latest 1 --tag nbe >/dev/null
printf '{"ok":true,"age_seconds":%s,"max_age_hours":%s}\n' "$age" "$max_hours"
