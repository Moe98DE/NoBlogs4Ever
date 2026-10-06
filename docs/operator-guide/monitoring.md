# Monitoring and health

The platform exposes health signals without reader, URL, IP or per-tenant dimensions.

## Endpoints and commands

| Probe | Access | Healthy when |
|---|---|---|
| `GET /wp-json/nbe/v1/live` | public | Database reachable and private job storage writable. Returns `{"ok":true,"time":…}` (HTTP 200) or HTTP 503. Use it for an external HTTPS uptime check. |
| `GET /wp-json/nbe/v1/health` | operators (signed in) | Full status JSON (below). |
| `php /opt/nbe/scripts/health.php` | inside app container (Docker `HEALTHCHECK`) | Database, writable storage, > 256 MB free. |
| `php /opt/nbe/scripts/health.php --worker` | inside worker container | Also: worker heartbeat younger than 3 minutes. |
| `make monitoring-check` | host | Database container health + worker + `--operations`: also a backup younger than `BACKUP_MAX_AGE_HOURS` and no failed or stalled imports. Exit code 0/1, one JSON line. |
| `./noblogs4ever doctor --json` | host | All of the above plus configuration validation and image drift detection. |

Status fields: `database`, `worker_age_seconds`, `worker_last_error` (stage name of the last worker failure, if any), `storage_free_bytes`, `storage_writable`, `backup_age_seconds`, `backup_max_age_seconds`, `readonly`, `failed_migrations`, `stalled_migrations`, `pending_exports`.

A public page returning HTTP 200 does **not** prove the platform is healthy — always alert on the checks above.

## Suggested alerts

| Condition | Severity |
|---|---|
| `/live` failing from outside | page immediately |
| Worker heartbeat older than 3 minutes | high — scheduled posts and imports stop |
| Backup older than `BACKUP_MAX_AGE_HOURS`, or `make backup-check` failing | high |
| Free disk below your safety margin (start with 20 %) | high |
| Failed or stalled imports | normal — look at the report, contact the site owner |
| `readonly: true` when nobody planned maintenance | high |
| TLS certificate expiring within 21 days | high |
| Repeated `make smtp-test` failures / provider bounce reports | normal |

Run `make monitoring-check` from cron, a systemd timer, a Nagios/Icinga/Checkmk local check or a Prometheus node-exporter textfile wrapper. Do not put credentials in probe URLs and do not add per-site labels to metrics.

## Logs

* **Security events**: JSON lines in the `logs` volume (`/var/lib/nbe/logs/events-YYYY-MM-DD.jsonl`), visible in Network Admin → Platform policy. Allowlisted fields only.
* **Container output**: `docker compose logs`; the `local` driver rotates at 3 × 10 MB per container. Apache access logs and Caddy request logs are disabled on purpose; PHP errors are not displayed.

To investigate a problem without request logs, reproduce it, look at `worker_last_error`, the audit log, the migration report, or run the failing operation through WP-CLI (`make shell`).
