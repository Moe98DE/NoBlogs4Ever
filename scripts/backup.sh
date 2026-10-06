#!/usr/bin/env bash
set -euo pipefail
umask 077
. /scripts/restic-env.sh
load_restic_backend_env
mkdir -p /scratch/config
trap 'rm -rf /scratch/*' EXIT
# Private client file, never a password argument or logged environment variable.
printf '[client]\nhost=db\nuser=wordpress\npassword=%s\n' "$(cat /run/secrets/db_password)" > /scratch/client.cnf
mariadb-dump --defaults-extra-file=/scratch/client.cnf --single-transaction --quick --skip-comments wordpress > /scratch/database.sql
# Configuration is reproducible source. Secret recovery uses separately escrowed operator secrets.
cp /source/compose*.yaml /source/Dockerfile /source/dependencies.lock.json /scratch/config/
cp -R /source/config /source/app /source/scripts /scratch/config/
rm -f /scratch/client.cnf
if ! restic snapshots >/dev/null 2>&1; then
  echo 'Backup repository unavailable or not initialized. Initialize explicitly with restic init.' >&2
  exit 1
fi
restic backup --tag nbe /scratch/database.sql /scratch/config /data/uploads /data/jobs
restic forget --tag nbe --keep-daily "${BACKUP_RETENTION_DAYS:-14}" --prune
restic check
mkdir -p /status
date -u +%FT%TZ > /status/last-success
