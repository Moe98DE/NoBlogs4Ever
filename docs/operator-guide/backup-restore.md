# Backup and restore

## What is backed up

`make backup` runs the `backup` container (Restic 0.18), which:

1. dumps the database with `mariadb-dump --single-transaction` (consistent without locking);
2. copies the reproducible configuration (Compose files, Dockerfile, lock file, `config/`, `app/`, `scripts/`);
3. snapshots the dump, configuration, **uploads** volume and **jobs** volume (imports, archives) into the Restic repository, encrypted and authenticated with `secrets/backup_password`;
4. applies retention (`--keep-daily BACKUP_RETENTION_DAYS`, 14 by default), prunes, and runs `restic check`;
5. writes a success marker read by health checks.

**Not** in the backup, by design: `secrets/`, TLS keys, `.env` (keep them in separate escrow) and the security event log.

## Repositories

Set `RESTIC_REPOSITORY` and put credentials in `secrets/restic_backend.env` (parsed as `KEY=value`, never executed):

| Backend | `RESTIC_REPOSITORY` | Credentials |
|---|---|---|
| S3-compatible | `s3:https://s3.provider.example/bucket/prefix` | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (+ `AWS_DEFAULT_REGION`) |
| Backblaze B2 | `b2:bucket:/prefix` | `B2_ACCOUNT_ID`, `B2_ACCOUNT_KEY` |
| REST server | `rest:https://backup.example/noblogs4ever` | `RESTIC_REST_USERNAME`, `RESTIC_REST_PASSWORD` |
| Azure Blob | `azure:container:/prefix` | `AZURE_ACCOUNT_NAME`, `AZURE_ACCOUNT_KEY` |
| Local path | `/repository` (mounted from `BACKUP_REPOSITORY_PATH`) | none — only with `BACKUP_LOCAL_OFFHOST_ACK=yes` and independent off-host replication |

The backup container sits on the internal database network and an egress network for the repository; it publishes no ports. Use storage-side versioning or object lock to protect against an attacker who obtains the credentials.

Initialise once, deliberately:

```sh
docker compose --profile backup run --rm backup restic init
```

Scripts never initialise a repository automatically, so a typo cannot silently start a new empty one.

## Schedule and verify

```sh
make backup          # take a snapshot
make backup-check    # last success younger than BACKUP_MAX_AGE_HOURS and repository readable
```

Use `scripts/noblogs4ever-backup.timer` (daily, randomised) or cron. For a snapshot where database and media are guaranteed to match, enable read-only mode for the minute the backup runs. Recovery point objective: at most one backup interval plus its duration (≈ 24 h with the daily timer). No RPO/RTO is promised beyond what you measure.

## Restore drill

```sh
make restore-drill
```

restores the latest snapshot into **disposable, uniquely named containers on an isolated network** — never the live database or volumes — and verifies: database load and integrity, application and worker health, operator sign-in over HTTP, at least two tenants with their isolation intact, page rendering, media files, and a WXR export. Results are written to `docs/evidence/restore.json`. CI runs it on every change; operators should run it monthly and after upgrades, timing it to know their real recovery time.

## Recovery procedures

Always restore into isolation first, check, then switch. Keep incoming mail and federation disabled in the isolated copy.

### Lost server, corrupted database or failed upgrade (full restore)

1. Provision a host as in [Deploying](deployment.md); restore `.env`, `secrets/` and TLS from escrow.
2. Pull the image digest you were running (or the previous one after a failed upgrade).
3. Restore files: `docker compose --profile backup run --rm -v "$PWD/restore:/restore" backup restic restore latest --tag nbe --target /restore`.
4. Start only the database, load `restore/scratch/database.sql` (`docker compose exec -T db mariadb -uroot -p"$(cat secrets/db_root_password)" wordpress < …`).
5. Copy `restore/data/uploads/.` into the `uploads` volume and `restore/data/jobs/.` into the `jobs` volume (e.g. with a temporary container mounting both).
6. Start app and worker, run `make bootstrap` (idempotent), `make monitoring-check`, sign in, spot-check sites. Then point DNS/ingress at it.

### One site deleted by mistake

Restore the latest snapshot that contains it into the restore drill environment (keep it with `restic restore` + a temporary stack), export the site there with **Tools → Full site archive** (or `wp export` plus its `uploads/sites/<id>` folder), create the site again on production with the same address, and import the archive. Comments, media and settings come back; members need to be re-added.

### Lost media volume only

Restore `data/uploads` from the latest snapshot into the uploads volume; the database is unaffected. Media uploaded after that snapshot is lost — compare with the database (`wp media regenerate --only-missing` can rebuild thumbnails, not originals).

### Compromised operator account

Follow [Incident response](incident-response.md): read-only mode, revoke the account's sessions (`wp user session destroy <user> --all`), revoke super admin, rotate `wp_salt` (signs everyone out), review the audit log and recent role/plugin changes. If content or configuration was altered, restore the affected sites from a snapshot taken before the compromise.
