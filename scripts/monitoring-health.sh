#!/usr/bin/env bash
set -euo pipefail
compose_command=${COMPOSE:-docker compose}
db=$($compose_command ps -q db)
app=$($compose_command ps -q app)
worker=$($compose_command ps -q worker)
test -n "$db" && test -n "$app" && test -n "$worker"
docker exec "$db" healthcheck.sh --connect --innodb_initialized >/dev/null
docker exec "$worker" php /opt/nbe/scripts/health.php --worker >/dev/null
docker exec "$app" php /opt/nbe/scripts/health.php --operations
