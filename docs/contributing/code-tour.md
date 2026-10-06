# Code tour

```
app/
  mu-plugins/nbe.php          loader: autoloads NBE\* and calls Platform::boot()
  mu-plugins/nbe/             the platform (one class per file, namespace NBE)
  nbe-media.php               front controller for /wp-content/uploads/*
config/
  wp-config.php               secrets from files, Multisite constants, hardening
  Caddyfile, Caddyfile.production   edge proxy (dev HTTP / prod TLS)
  php.ini, uploads.conf, remoteip.conf, wordpress.htaccess
  backup.Dockerfile           Restic + MariaDB client
scripts/                      bootstrap/configure, worker, health, backup/restore, operator tools, CLI
tests/                        unit, integration/, browser/, fixtures/, smoke tests
tools/contact-key-tool.html   offline key generator/decryptor for the contact form
docs/                         this manual (GitBook)
Dockerfile, compose*.yaml, dependencies.lock.json, Makefile, noblogs4ever (operator CLI)
```

## Boot sequence

`wp-config.php` → WordPress loads must-use plugins → `nbe.php` registers an autoloader → `Platform::boot()` calls `register()` on each module in `Platform::MODULES`, then `Contact::boot()`, mail settings and the revision limit.

## Modules

| Class | Read this when you want to… |
|---|---|
| `Config` | add a setting (env var, optionally runtime-editable via `RUNTIME_KEYS`) |
| `Policy` | change a pure rule: slugs, emails, metadata, iframe hosts, CSS safety, user-agent classes. Unit-tested |
| `Security` | adjust capabilities, read-only behaviour, MFA enforcement, headers, enumeration, audit hooks |
| `Registration` | change sign-up rules or the approval queue |
| `Sites` | change site creation or defaults for new sites |
| `Media` | allow a file type, change upload checks or media delivery |
| `Privacy` | change comment/avatar/private-site behaviour |
| `Embeds` | change iframe handling |
| `Analytics` | change what is counted or the report page |
| `Discovery` | change the directory index or shortcodes |
| `Export` | change the full site archive |
| `Archive` | change intake safety or WXR parsing. Unit-tested, WordPress-free |
| `Migration` | change how content is imported, rewritten or validated |
| `MigrationAdmin` | change the import screens or author mapping |
| `Contact` | change the encrypted contact form (`contact.js` is the browser half) |
| `Health` | add a health signal |
| `Worker` | add background work |
| `EventLog`, `RateLimiter` | logging and rate-limit primitives |
| `Integrations` | change the curated plugin list shown to site admins |
| `Admin` | change the "Your platform" or "Platform policy" pages |
| `Platform` | add a module; stable helper methods used by scripts |

## Database tables

Created and upgraded idempotently by `Platform::install()` (called from `scripts/configure.php`): `wp_nbe_jobs`, `wp_nbe_map`, `wp_nbe_analytics`, `wp_nbe_exports`. Use `dbDelta()` for schema changes and keep them backward compatible (see `Analytics::install()` for a data migration example).

## Scripts

| Script | Role |
|---|---|
| `bootstrap.php` → `install-single.php`, `configure.php` | Install network without exposing the admin password; apply configuration (idempotent) |
| `worker.php` | One worker pass |
| `health.php` | Container health probe |
| `seed.php` | Demo data (development only) |
| `operator-import.php`, `validate-migration.php`, `purge-jobs.php` | Operator migration tools |
| `backup.sh`, `backup-freshness.sh`, `restic-env.sh`, `restore-drill.sh` | Backups |
| `operator_cli.py`, `production-check.py`, `generate-secrets.py` | `./noblogs4ever` setup/check/doctor |
| `release-check.py`, `release-manifest.php`, `runtime-evidence.sh`, `supply-chain.sh`, `lock.py`, `fetch.php` | Supply chain and releases |
