# Deploying to production

This guide takes a fresh Linux host to a running platform. Work through the [production checklist](production-checklist.md) alongside it; it covers the parts no script can verify for you (DNS, mail delivery, off-site backups, monitoring, legal notices).

## 1. What you need

* A maintained Linux server (2+ vCPU, 4+ GB RAM to start; disk for media + database + local backup cache) with Docker Engine and **Compose v2.24.4+**.
* A domain, with DNS records for both the base name and a wildcard: `example.org` and `*.example.org` → the server.
* A TLS certificate covering **both** `example.org` and `*.example.org`. Wildcard certificates need an ACME DNS-01 challenge (certbot, lego, acme.sh with your DNS provider) or a commercial certificate. Put `fullchain.pem` and `privkey.pem` (mode 0600) in a directory such as `/etc/noblogs4ever/tls` and renew them automatically.
* An SMTP account with authentication and TLS (any provider).
* Off-site backup storage reachable by Restic: S3-compatible object storage, Backblaze B2, a REST server or Azure Blob.
* Only ports 80 and 443 open to the Internet.

## 2. Get the release

Use a tagged release. Each release publishes an image at `ghcr.io/moe98de/noblogs4ever:<tag>` and a `release-manifest.json` with its immutable digest.

```sh
sudo mkdir -p /srv/noblogs4ever && cd /srv/noblogs4ever
git clone --branch v1.0.0 https://github.com/Moe98DE/noblogs4ever.git .
```

## 3. Configure

Run the guided setup; it writes `.env` (or another file you name) and creates missing random secrets, without deploying anything:

```sh
./noblogs4ever setup --config .env
```

Then edit `.env` — the [configuration reference](configuration.md) explains every variable. At minimum:

```ini
APP_ENV=production
PLATFORM_SCHEME=https
PLATFORM_DOMAIN=example.org
NBE_APP_IMAGE=ghcr.io/moe98de/noblogs4ever@sha256:<digest from release-manifest.json>
SUPPORT_EMAIL=support@example.org
NBE_ADMIN_EMAIL=operators@example.org
SMTP_HOST=smtp.provider.example
SMTP_PORT=587
SMTP_USER=platform@example.org
SMTP_TLS=tls
SMTP_FROM=no-reply@example.org
REGISTRATION_POLICY=invitation
RESTIC_REPOSITORY=s3:https://s3.provider.example/bucket/noblogs4ever
BACKUP_REQUIRE_REMOTE=yes
TLS_DIRECTORY=/etc/noblogs4ever/tls
STORAGE_CHECK_PATH=/var/lib/docker
```

Put the SMTP password in `secrets/smtp_password` and the storage credentials in `secrets/restic_backend.env`:

```ini
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
```

Keep `secrets/` and the TLS directory **owned by root** (files `0600`, directories `0700`; the TLS directory may be `0711`). The production edge and backup containers run as root with every capability dropped, so they cannot read files owned by another user.

Copy `secrets/backup_password`, `secrets/wp_salt`, `secrets/db_*` and the TLS key to your separate secret escrow (password manager, sealed envelope…) **now**. Without `backup_password` your backups cannot be restored.

## 4. Check

```sh
make production-check            # configuration, secrets, TLS, disk, rendered Compose hardening
./noblogs4ever check --config .env   # + DNS and SMTP reachability; exit 0 = PASS, 2 = something unverified
```

Fix every FAIL.

## 5. Start and bootstrap

```sh
docker compose -f compose.yaml -f compose.production.yaml pull
docker compose -f compose.yaml -f compose.production.yaml up -d
docker compose -f compose.yaml -f compose.production.yaml run --rm cli php /opt/nbe/scripts/bootstrap.php
```

The production overlay publishes only Caddy on 80/443, runs the edge, app and worker with read-only root filesystems, dropped capabilities and `no-new-privileges`, removes Mailpit, and keeps MariaDB on an internal network without Internet access. Tip: `export COMPOSE_FILE=compose.yaml:compose.production.yaml` in the operator's shell so plain `docker compose` uses both files.

## 6. First sign-in

Sign in at `https://example.org/wp-login.php` as `operator` with `secrets/admin_password`. You will be sent to your profile: enrol an authenticator app or a security key and save the backup codes. Then:

* create a **named** operator account for each person (Network Admin → Users), grant super admin, enrol MFA, and stop using the bootstrap account;
* change the bootstrap account's password or delete it;
* review **Network Admin → Platform policy**.

## 7. Mail, backups, monitoring

```sh
make smtp-test                                                   # sends one real message to SMTP_TEST_RECIPIENT
docker compose --profile backup run --rm backup restic init      # only once, on the intended repository
make backup && make backup-check
make restore-drill                                               # isolated recovery test (needs two sites with media)
make monitoring-check
```

Schedule backups with the included systemd units (adjust `WorkingDirectory`):

```sh
sudo cp scripts/noblogs4ever-backup.{service,timer} /etc/systemd/system/
sudo systemctl enable --now noblogs4ever-backup.timer
```

Point your monitoring at `https://example.org/wp-json/nbe/v1/live` and run `make monitoring-check` from cron or your monitoring agent ([Monitoring](monitoring.md)).

## 8. Open the doors

Publish your terms, privacy notice (you can build it from [Privacy and data retention](../architecture/privacy.md)) and support contact, run `./noblogs4ever doctor --config .env`, and invite your first users.
