#!/bin/sh
# Container entrypoint for app, worker, cli and importer.
#
# Docker Compose file-backed secrets keep their host permissions, which the
# unprivileged web server user usually cannot read. Copy only the secrets this
# container needs into a private tmpfs readable by www-data, then drop
# privileges where the command allows it.
set -eu

mkdir -p /run/nbe-secrets
chmod 750 /run/nbe-secrets
chown root:www-data /run/nbe-secrets
for name in db_password wp_salt smtp_password admin_password demo_password; do
  if [ -r "/run/secrets/$name" ]; then
    cp "/run/secrets/$name" "/run/nbe-secrets/$name"
    chown root:www-data "/run/nbe-secrets/$name"
    chmod 640 "/run/nbe-secrets/$name"
  fi
done

# Files created by an earlier root-run tool must stay writable by the web server.
for dir in /var/www/html/wp-content/uploads /var/lib/nbe/jobs /var/lib/nbe/logs; do
  [ -d "$dir" ] && find "$dir" -xdev -user root -exec chown www-data:www-data {} + 2>/dev/null || true
done

if [ "${1:-}" = nbe-worker ]; then
  exec runuser -u www-data -- sh -c 'while true; do php /opt/nbe/scripts/worker.php; sleep "${NBE_WORKER_INTERVAL:-30}"; done'
fi

# One-off tools (WP-CLI, PHP scripts) run as www-data so that every file they
# create in shared volumes stays usable by the web server. The importer keeps
# root only to read operator-mounted archives, then hands jobs to www-data.
if [ "${NBE_RUN_AS:-}" = www-data ] && [ "$(id -u)" = 0 ]; then
  export HOME=/tmp
  exec runuser -u www-data -- "$@"
fi

exec docker-php-entrypoint "$@"
