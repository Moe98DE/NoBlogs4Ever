#!/usr/bin/env bash
# Full recovery drill. Every database/container/network target is disposable and uniquely named.
set -euo pipefail
stage='initializing'
trap 'echo "Restore drill failed during $stage" >&2' ERR
root=$(CDPATH='' cd -- "$(dirname "$0")/.." && pwd)
compose_command=${COMPOSE:-docker compose}
work=$(mktemp -d)
work=$(cd "$work" && pwd -P)
temp_root=$(cd "${TMPDIR:-/tmp}" && pwd -P)
case "$work" in "$temp_root"/tmp.*) ;; *) echo 'Unsafe restore work directory' >&2; exit 1 ;; esac
docker_root=$root
docker_work=$work
if command -v cygpath >/dev/null 2>&1 && [ -n "${MSYSTEM:-}" ]; then
  # Git Bash converts container paths such as /restore into Windows paths.
  # Convert only host bind sources and disable its implicit argument rewrite.
  docker_root=$(cygpath -m "$root")
  docker_work=$(cygpath -m "$work")
  export MSYS_NO_PATHCONV=1
fi
suffix=$(printf '%s' "$work" | sha256sum | cut -c1-10)
network=nbe-restore-$suffix
db=nbe-restore-db-$suffix
app=nbe-restore-app-$suffix
worker=nbe-restore-worker-$suffix
cleanup() {
  docker rm -f -v "$worker" "$app" "$db" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT
chmod 700 "$work"
cd "$root"
$compose_command --profile backup run --rm -v "$docker_work:/restore" backup restic restore latest --tag nbe --target /restore
stage='checking restored files'
test -s "$work/scratch/database.sql"
test -d "$work/data/uploads" && test -d "$work/data/jobs" && test -d "$work/scratch/config"
for required in Dockerfile dependencies.lock.json compose.yaml config app scripts; do
  test -e "$work/scratch/config/$required"
done
docker network create --internal "$network" >/dev/null
db_image='mariadb:11.8@sha256:2439dcd7d14010ecd1ff7a4e1c5abe8e208c34fe35290744deeeaac3569043c3'
docker run -d --name "$db" --network "$network" --network-alias db \
  -e MARIADB_DATABASE=wordpress -e MARIADB_USER=wordpress \
  -e MARIADB_PASSWORD_FILE=/run/secrets/db_password -e MARIADB_ROOT_PASSWORD_FILE=/run/secrets/db_root_password \
  -v "$docker_root/secrets/db_password:/run/secrets/db_password:ro" -v "$docker_root/secrets/db_root_password:/run/secrets/db_root_password:ro" \
  "$db_image" >/dev/null
ready=false
for _ in $(seq 1 90); do
  if docker exec "$db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then ready=true; break; fi
  sleep 1
done
$ready || { echo 'Restore database did not become healthy' >&2; exit 1; }
stage='loading recovered database'
# Passwords are passed through protected option files, not process arguments.
printf '[client]\nuser=root\npassword=%s\n' "$(cat "$root/secrets/db_root_password")" > "$work/root.cnf"
printf '[client]\nuser=wordpress\npassword=%s\n' "$(cat "$root/secrets/db_password")" > "$work/app.cnf"
chmod 600 "$work/root.cnf" "$work/app.cnf"
docker cp "$docker_work/root.cnf" "$db:/tmp/root.cnf"
docker exec -i "$db" mariadb --defaults-extra-file=/tmp/root.cnf wordpress < "$work/scratch/database.sql"
# The application-only dump intentionally excludes mysql system tables.
escaped_password=$(php -r 'echo str_replace(["\\", "\x27"], ["\\\\", "\x27\x27"], trim(file_get_contents($argv[1])));' "$docker_root/secrets/db_password")
printf "CREATE USER IF NOT EXISTS 'wordpress'@'%%' IDENTIFIED BY '%s'; GRANT ALL ON wordpress.* TO 'wordpress'@'%%'; FLUSH PRIVILEGES;\n" "$escaped_password" > "$work/grant.sql"
docker exec -i "$db" mariadb --defaults-extra-file=/tmp/root.cnf < "$work/grant.sql"
docker exec "$db" mariadb-check --defaults-extra-file=/tmp/root.cnf wordpress >/dev/null
stage='starting recovered application and worker'
if [ -n "${MSYSTEM:-}" ]; then mkdir "$work/logs"; else mkdir -m 700 "$work/logs"; fi
common=(--network "$network" -e DB_HOST=db -e DB_NAME=wordpress -e DB_USER=wordpress -e APP_ENV=development -e PLATFORM_SCHEME=http -e PLATFORM_DOMAIN=lvh.me -e PLATFORM_PORT=8080 -e SMTP_HOST=127.0.0.1 -e ENABLE_EXPERIMENTAL_INTEGRATIONS=no -e NBE_JOB_DIR=/var/lib/nbe/jobs -e NBE_RATE_DIR=/run/nbe-rate -v "$docker_root/secrets/db_password:/run/secrets/db_password:ro" -v "$docker_root/secrets/wp_salt:/run/secrets/wp_salt:ro" -v "$docker_root/secrets/smtp_password:/run/secrets/smtp_password:ro" -v "$docker_work/data/uploads:/var/www/html/wp-content/uploads" -v "$docker_work/data/jobs:/var/lib/nbe/jobs" -v "$docker_work/logs:/var/lib/nbe/logs" --tmpfs /tmp --tmpfs /var/run/apache2 --tmpfs /var/lock/apache2 --tmpfs /run/nbe-secrets)
docker run -d --name "$app" --network-alias app "${common[@]}" noblogs4ever:local >/dev/null
docker run -d --name "$worker" "${common[@]}" noblogs4ever:local nbe-worker >/dev/null
healthy=false
for _ in $(seq 1 90); do
  if docker exec "$app" php /opt/nbe/scripts/health.php >/dev/null 2>&1 && docker exec "$worker" php /opt/nbe/scripts/health.php --worker >/dev/null 2>&1; then healthy=true; break; fi
  sleep 2
done
$healthy || { echo 'Recovered application or worker did not become healthy' >&2; exit 1; }
stage='verifying recovered tenants and media'
evidence=$(docker exec "$app" wp eval-file /opt/nbe/tests/restore-verify.php --allow-root | tail -1)
printf '%s' "$evidence" | php -r '$d=json_decode(stream_get_contents(STDIN),true);exit(($d["tenants"]??0)<2||($d["published"]??0)<1||($d["media"]??0)<1);'
first_domain=$(printf '%s' "$evidence" | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["domains"][0];')
second_domain=$(printf '%s' "$evidence" | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["domains"][1];')
stage='rendering recovered tenant pages'
for domain in "$first_domain" "$second_domain"; do
  status=$(docker exec "$app" curl --silent --show-error --connect-to "$domain:8080:app:80" -o /tmp/rendered.html -w '%{http_code}' "http://$domain:8080/")
  test "$status" = 200 || { echo "Recovered tenant $domain did not render HTTP 200 (got $status)" >&2; exit 1; }
  docker exec "$app" test -s /tmp/rendered.html || { echo "Recovered tenant $domain rendered an empty page" >&2; exit 1; }
done
stage='verifying operator login'
ADMIN_PASSWORD_FILE="$docker_root/secrets/admin_password" php -r '$p=trim(file_get_contents(getenv("ADMIN_PASSWORD_FILE")));echo "testcookie=1&log=operator&pwd=".rawurlencode($p)."&wp-submit=Log+In&redirect_to=".rawurlencode("http://lvh.me:8080/wp-admin/");' | docker exec -i "$app" sh -c 'umask 077; cat > /tmp/login-form'
docker exec "$app" test -s /tmp/login-form || { echo 'Login request fixture was not created in recovery container.' >&2; exit 1; }
docker exec "$app" curl --silent --show-error --connect-to lvh.me:8080:app:80 -D /tmp/login-headers -o /tmp/login-body -c /tmp/cookies http://lvh.me:8080/wp-login.php
docker exec "$app" curl --silent --show-error --connect-to lvh.me:8080:app:80 -D /tmp/login-headers -o /tmp/login-body-post -b /tmp/cookies -c /tmp/cookies -H 'Content-Type: application/x-www-form-urlencoded' --data-binary @/tmp/login-form http://lvh.me:8080/wp-login.php
if ! docker exec "$app" grep -Eiq '^location: .*wp-admin' /tmp/login-headers; then
  docker exec "$app" sh -c "grep -iE '^(HTTP/|Location:)' /tmp/login-headers" >&2 || true
  echo 'Recovered operator login did not reach administration.' >&2
  exit 1
fi
stage='verifying recovered export'
docker exec "$app" sh -c 'rm -rf /tmp/export && mkdir /tmp/export && wp export --dir=/tmp/export --allow-root >/dev/null && test -n "$(find /tmp/export -name "*.xml" -print -quit)"'
dump_hash=$(sha256sum "$work/scratch/database.sql" | cut -d' ' -f1)
blogs=$(printf '%s' "$evidence" | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["blogs"];')
users=$(printf '%s' "$evidence" | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["users"];')
printf '{"database_restore":"passed","full_application_boot":"passed","operator_login":"passed","two_tenants":"passed","tenant_isolation":"passed","rendering":"passed","media":"passed","export":"passed","app_worker_health":"passed","network":"isolated","dump_sha256":"%s","blogs":%s,"users":%s,"scope":"isolated full recovery; never targets the live database"}\n' "$dump_hash" "$blogs" "$users" > "$root/docs/evidence/restore.json"
echo 'Full isolated restore passed: database, uploads, job/config state, application, worker, login, tenants, isolation, rendering, media, and export.'
