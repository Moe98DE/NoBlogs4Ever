#!/usr/bin/env bash
set -euo pipefail
root=$(CDPATH='' cd -- "$(dirname "$0")/.." && pwd)
compose_command=${COMPOSE:-docker compose}
mkdir -p "$root/.runtime"
work=$(mktemp -d "$root/.runtime/production-smoke.XXXXXX")
project=nbe-prod-smoke-$(printf '%s' "$work" | sha256sum | cut -c1-10)
cleanup() {
  if [ "${NBE_KEEP_SMOKE:-no}" = yes ]; then
    echo "Preserved failed smoke environment: project=$project work=$work" >&2
    return
  fi
  $compose_command --project-name "$project" --env-file "$work/production.env" -f "$root/compose.yaml" -f "$root/compose.production.yaml" -f "$work/test.yaml" down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT
chmod 700 "$work"
mkdir -m 700 "$work/secrets" "$work/tls" "$work/imports" "$work/backups"
for name in db_password db_root_password wp_salt smtp_password backup_password admin_password demo_password; do
  openssl rand -base64 48 > "$work/secrets/$name"
  chmod 600 "$work/secrets/$name"
done
printf '# no remote credentials in the isolated smoke test\n' > "$work/secrets/restic_backend.env"
chmod 600 "$work/secrets/restic_backend.env"
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj '/CN=nbe.test' -addext 'subjectAltName=DNS:nbe.test,DNS:*.nbe.test' -keyout "$work/tls/privkey.pem" -out "$work/tls/fullchain.pem" >/dev/null 2>&1
chmod 600 "$work/tls/privkey.pem" "$work/tls/fullchain.pem"
port=$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')
cat > "$work/production.env" <<EOF
APP_ENV=production
NBE_APP_IMAGE=noblogs4ever:local
PLATFORM_SCHEME=https
PLATFORM_DOMAIN=nbe.test
PLATFORM_PORT=443
BRAND_NAME=NoBlogs4Ever smoke
SUPPORT_EMAIL=support@nbe.test
NBE_ADMIN_EMAIL=operator@nbe.test
NBE_ADMIN_USER=operator
SMTP_HOST=smtp.nbe.test
SMTP_PORT=587
SMTP_USER=smoke
SMTP_TLS=tls
SMTP_FROM=publisher@nbe.test
REGISTRATION_POLICY=invitation
RESTIC_REPOSITORY=/repository
BACKUP_LOCAL_OFFHOST_ACK=yes
ENABLE_EXPERIMENTAL_INTEGRATIONS=no
TLS_DIRECTORY=$work/tls
NBE_ENV_FILE=$work/production.env
NBE_COMPOSE_DB_PASSWORD_FILE=$work/secrets/db_password
NBE_COMPOSE_DB_ROOT_PASSWORD_FILE=$work/secrets/db_root_password
NBE_COMPOSE_WP_SALT_FILE=$work/secrets/wp_salt
NBE_COMPOSE_SMTP_PASSWORD_FILE=$work/secrets/smtp_password
NBE_COMPOSE_BACKUP_PASSWORD_FILE=$work/secrets/backup_password
NBE_COMPOSE_ADMIN_PASSWORD_FILE=$work/secrets/admin_password
NBE_COMPOSE_DEMO_PASSWORD_FILE=$work/secrets/demo_password
RESTIC_BACKEND_ENV_FILE=$work/secrets/restic_backend.env
IMPORT_DIRECTORY=$work/imports
BACKUP_REPOSITORY_PATH=$work/backups
STORAGE_CHECK_PATH=$work
EOF
cat > "$work/test.yaml" <<EOF
services:
  edge:
    ports: !override ['127.0.0.1:$port:443']
EOF
compose() { $compose_command --project-name "$project" --env-file "$work/production.env" -f "$root/compose.yaml" -f "$root/compose.production.yaml" -f "$work/test.yaml" "$@"; }
compose config --quiet
test "$(compose config --services | grep -cx mail || true)" -eq 0
if [ "${NBE_SKIP_BUILD:-no}" != yes ]; then
  # The production overlay deliberately has no build context. Build the
  # disposable local smoke image through the development definition only.
  $compose_command --project-name "$project" --env-file "$work/production.env" -f "$root/compose.yaml" build app
fi
compose up -d db app worker edge
bootstrap_output=$(compose run --rm cli php /opt/nbe/scripts/bootstrap.php 2>&1) || {
  # Show the failure without ever echoing the administrator password.
  printf '%s\n' "$bootstrap_output" | grep -vF -f "$work/secrets/admin_password" | tail -20 >&2
  echo 'Clean production bootstrap failed.' >&2
  exit 1
}
if printf '%s' "$bootstrap_output" | grep -F -f "$work/secrets/admin_password" >/dev/null; then
  echo 'Bootstrap exposed administrator password in command output.' >&2
  exit 1
fi
for _ in $(seq 1 60); do
  status=$(compose ps --format json 2>/dev/null || true)
  if printf '%s' "$status" | grep -q '"Health":"healthy"'; then break; fi
  sleep 2
done
compose exec -T edge caddy validate --config /etc/caddy/Caddyfile
smoke=$(compose run --rm cli wp eval-file /opt/nbe/tests/production-smoke.php --allow-root | tail -1)
printf '%s' "$smoke" | php -r '$d=json_decode(stream_get_contents(STDIN),true);exit(!($d["site_id"]??0)||!($d["post_id"]??0));'
fetch() { curl --noproxy '*' --silent --show-error --insecure --connect-to "$1:443:127.0.0.1:$port" -D "$work/headers" -o "$work/body" -w '%{http_code}' "https://$1$2"; }
test "$(fetch nbe.test /wp-login.php)" = 200 || { echo 'Production login page did not return 200.' >&2; exit 1; }
grep -qi '^strict-transport-security:' "$work/headers" || { echo 'HSTS header missing.' >&2; exit 1; }
grep -qi '^cache-control:.*no-store' "$work/headers" || { echo 'Login page is cacheable.' >&2; exit 1; }
site_domain=$(printf '%s' "$smoke" | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["site_domain"];')
status=$(fetch "$site_domain" /)
test "$status" = 200 || { echo "Tenant home returned HTTP $status (expected 200 over HTTPS without redirects)." >&2; exit 1; }
grep -q 'Production smoke publication' "$work/body" || { echo 'Tenant home does not show the published post.' >&2; exit 1; }
grep -qi '^content-security-policy:.*frame-ancestors' "$work/headers" || { echo 'CSP header missing.' >&2; exit 1; }
media_path=$(printf '%s' "$smoke" | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo parse_url($d["media_url"], PHP_URL_PATH);')
test "$(fetch "$site_domain" "$media_path")" = 200 || { echo 'Uploaded media is not served.' >&2; exit 1; }
test "$(fetch "$site_domain" /wp-content/uploads/evil.php)" != 200 || { echo 'PHP below uploads is reachable.' >&2; exit 1; }
test "$(fetch "$site_domain" /xmlrpc.php)" = 403 || { echo 'XML-RPC is reachable.' >&2; exit 1; }
for service in edge app worker; do
  cid=$(compose ps -q "$service")
  test "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$cid")" = true
  docker inspect -f '{{json .HostConfig.SecurityOpt}}' "$cid" | grep -q no-new-privileges
done
edge_networks=$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$(compose ps -q edge)")
db_networks=$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$(compose ps -q db)")
test "$(printf '%s' "$edge_networks" | wc -w | tr -d ' ')" -eq 1
test "$(printf '%s' "$db_networks" | wc -w | tr -d ' ')" -eq 1
test "$edge_networks" != "$db_networks"
compose exec -T app php /opt/nbe/scripts/health.php
# The worker started before bootstrap created the tables; run one pass now instead of waiting 30 s.
compose exec -T worker php /opt/nbe/scripts/worker.php
compose exec -T worker php /opt/nbe/scripts/health.php --worker
echo 'Production Compose smoke passed: TLS, topology, hardening, app/database/worker health, publishing, and public rendering.'
