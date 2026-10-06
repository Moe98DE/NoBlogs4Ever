# Architecture overview

## Logical components

```
                       Internet
                          │ 80/443 (production) · 127.0.0.1:8080 (development)
                 ┌────────▼─────────┐
                 │  edge: Caddy 2.10│  TLS (operator-supplied wildcard certificate), HSTS, CSP,
                 │                  │  host allowlist (domain + *.domain), blocks xmlrpc/wp-cron/
                 └────────┬─────────┘  uploads/*.php, no access logs
          ingress network │
       ┌──────────────────▼───────────────────┐        ┌──────────────────────────────┐
       │ app: Apache + PHP 8.4 + WordPress 7.1│        │ worker: same image           │
       │  Multisite (subdomains)              │        │  loop every 30 s:            │
       │  mu-plugin NBE (policy layer)        │        │  cron · imports · archives · │
       │  /wp-content/uploads → nbe-media.php │        │  directory · retention       │
       └──────────────────┬───────────────────┘        └──────────────┬───────────────┘
          data network    │ (internal: no Internet route)             │
                 ┌────────▼─────────┐                                  │
                 │  db: MariaDB 11.8│◄─────────────────────────────────┘
                 └────────▲─────────┘
                          │
                 ┌────────┴─────────┐  egress network → Restic repository (S3/B2/REST/Azure/local)
                 │ backup: Restic   │  profile "backup", run on schedule
                 └──────────────────┘

 Volumes: database · uploads · jobs (imports, archives) · logs (events) · rate (tmpfs counters)
          caddy (certificates state) · backup-status
 Tools:   cli / importer (profile "tools"): one-off WP-CLI and PHP scripts, same image
```

This describes the containers in `compose.yaml`; how they map onto physical hosts is up to the operator. The reference deployment is a single Docker host.

## Request path

1. Caddy accepts only the base domain and its subdomains (anything else gets HTTP 421), terminates TLS, adds security headers and forwards to `app:80`. Paths such as `/xmlrpc.php`, `/wp-cron.php`, `/.env`, `/.git/` and PHP files below uploads are refused at the edge.
2. Apache's `mod_remoteip` takes the client address from Caddy (trusted only from private ranges) — used solely for in-memory rate limiting.
3. `wp-config.php` reads secrets from files, configures Multisite with `DOMAIN_CURRENT_SITE = PLATFORM_DOMAIN`, disables file modifications, automatic updates and web cron, and in development strips the port from the host for site lookup.
4. WordPress resolves the site from the host name; the `NBE` must-use plugin applies policy (capabilities, privacy, headers…).
5. Requests below `/wp-content/uploads/` are rewritten to `nbe-media.php`, which authorises and streams the file.

## The platform plugin

All custom behaviour lives in `app/mu-plugins/nbe/` — small, single-purpose classes wired up by `Platform::boot()`:

| Module | Responsibility |
|---|---|
| `Security` | Operator-only capabilities, read-only mode, MFA enforcement, login/reset rate limits, enumeration resistance, headers, cookie SameSite, audit hooks |
| `Registration` | Registration policies, deny/allow lists, approval queue |
| `Sites` | Site creation (UI and signup validation), defaults for new sites, deletion messaging |
| `Media` | Allowed types, upload checks, tenant-correct upload URLs, authorised delivery |
| `Privacy` | No comment IPs, local avatars, no emoji CDN, members-only sites |
| `Embeds` | Operator-allowlisted, sandboxed iframes |
| `Analytics` | Aggregate counters, admin page, REST endpoint, retention |
| `Discovery` | Network directory index and shortcodes |
| `Export` | Full site archives built by the worker |
| `Archive`, `Migration`, `MigrationAdmin` | Safe intake, inventory, idempotent import, report, UI |
| `Contact` | Browser-encrypted contact form |
| `Health`, `Worker`, `EventLog`, `RateLimiter`, `Config`, `Policy` | Operations plumbing; `Policy` and `Archive` are pure PHP and unit-tested without WordPress |
| `Integrations`, `Admin` | Curated plugin catalog; "Your platform" and "Platform policy" pages |

See the [code tour](../contributing/code-tour.md).

## Data

| Store | Contents |
|---|---|
| MariaDB `wordpress` | WordPress network tables plus `wp_nbe_jobs` (imports), `wp_nbe_map` (source → destination IDs for idempotency), `wp_nbe_analytics` (aggregate counters), `wp_nbe_exports` (archive requests) |
| `uploads` volume | Media per site (`uploads/sites/<id>/…`) |
| `jobs` volume | Private import workspaces and finished archives (outside the web root) |
| `logs` volume | Daily JSON-lines security event files |
| `rate` tmpfs | HMAC-named rate-limit counters, lost on restart |

## Tenancy model

One network identity per person; roles per site; Multisite subdomain routing. All tenants share PHP processes, the database user and the uploads volume: isolation is enforced in the application (capabilities, site-scoped queries, authorised media delivery), not by the operating system. This keeps the platform simple to run for a small team, at the cost described in the [threat model](threat-model.md).

## Background work

`DISABLE_WP_CRON` is set and `wp-cron.php` is refused. The worker container (`scripts/worker.php` → `NBE\Worker::run()`) loops every `NBE_WORKER_INTERVAL` seconds as `www-data`: due WordPress cron events for every active site, import batches for up to `MIGRATION_TIME_BUDGET` seconds, one queued archive, the directory index, and hourly retention. It writes a heartbeat used by health checks, and does nothing but the heartbeat in read-only mode. One worker is assumed; running several is not supported yet (imports are protected by database advisory locks, cron is not).

## Email

PHPMailer is configured from `SMTP_*`; any authenticated SMTP provider works. A network-wide hourly cap protects the sender reputation. Development routes everything to Mailpit.

## Secrets

Docker secrets are mounted at `/run/secrets`; the entrypoint copies only the ones a container needs into a private tmpfs readable by `www-data`. Secrets never appear in environment variables, command lines or images.

## Caching

There is no shared page cache. Authenticated and admin responses are `private, no-store`. Public media is cacheable by browsers; restricted media is `private, no-store`. A reverse-proxy cache for anonymous traffic is on the roadmap; if you add one, bypass it for any request carrying a `wordpress_logged_in_*`, `wp-postpass_*` or `comment_author_*` cookie and for `/wp-admin`, `/wp-login.php`, `/wp-json` and members-only sites.
