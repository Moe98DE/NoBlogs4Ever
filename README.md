<div align="center">

# NoBlogs4Ever

**A managed, privacy-first publishing platform built on WordPress Multisite.**

Give individuals and small collectives their own websites — without handing them a server, a database, plugins or a shell.

[![CI](https://github.com/Moe98DE/noblogs4ever/actions/workflows/ci.yaml/badge.svg)](https://github.com/Moe98DE/noblogs4ever/actions/workflows/ci.yaml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
![Status: release candidate](https://img.shields.io/badge/status-1.0%20release%20candidate-orange)

[Quick start](#quick-start) · [Documentation](docs/README.md) · [Features](#what-you-get) · [Architecture](#how-it-works) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md)

</div>

---

NoBlogs4Ever turns a stock WordPress Multisite network into a hosting service you can run for a community: everyone gets `https://<their-site>.<your-domain>`, the familiar WordPress editor, curated themes, and safe collaboration — while the operator keeps sole control of code, upgrades and infrastructure. It is designed for groups that care about **reader privacy**, **reliable migration away from (and back to) other WordPress hosts**, and **being maintainable by a very small team**.

It is not a new CMS. WordPress does the publishing; NoBlogs4Ever adds a single must-use plugin, a hardened container stack and operator tooling around it.

## What you get

| For writers and site owners | For operators | For readers |
|---|---|---|
| Block editor by default, Classic Editor when wanted | One-command local stack, production overlay with TLS | No tracking scripts, cookies or third-party requests by default |
| Sites at `<slug>.<domain>` in one click | Tenants can't install plugins, themes or run code | Comment IP addresses are never stored |
| Invite collaborators with editorial roles | Registration policy: invitation, approval queue, allowlist or open | Members-only sites protect pages, feeds, REST and media |
| **Import** WordPress/NoBlogs exports with media, menus and CSS | MFA (TOTP / security keys) required for operators | Optional browser-encrypted contact form |
| **Export** everything: WXR or a full ZIP archive with media | Encrypted Restic backups + automated isolated restore drill | Aggregate-only, opt-in page counts |
| Optional, cookie-free page-view analytics | Emergency read-only mode, health checks, audit log | Opt-in network directory of public sites |

## Quick start

You need Docker with Compose v2.24+, Python 3, ~4 GB RAM and 5 GB disk. `lvh.me` and all its subdomains resolve to `127.0.0.1`, so tenant subdomains work locally without editing `/etc/hosts`.

```sh
git clone https://github.com/Moe98DE/noblogs4ever.git && cd noblogs4ever
make init        # .env + random local secrets in secrets/
make up          # build the image and start Caddy, WordPress, worker, MariaDB, Mailpit
make bootstrap   # install the network and apply the platform policy
make seed        # optional: demo sites "garden" and "journal" with every role
```

Then open:

| | |
|---|---|
| Platform | http://lvh.me:8080 — sign in as `operator` with the password in `secrets/admin_password` |
| Demo sites | http://garden.lvh.me:8080, http://journal.lvh.me:8080 — users `alice`, `bob`, `editor`, `author`, `contributor`, `subscriber`, password in `secrets/demo_password` |
| Outgoing email | http://localhost:8025 (Mailpit catches everything) |

Working on the PHP code? `make dev` starts the same stack with the plugin live-mounted, so edits apply without rebuilding. Run `make help` for every command.

## How it works

```
                 ┌──────────────── ingress network ────────────────┐
 Internet ──► Caddy (TLS, headers, *.domain) ──► app: Apache + PHP 8.4 + WordPress 7.1 Multisite
                                                   │   └─ mu-plugin "NBE": policy, privacy, migration,
                                                   │      export, analytics, discovery, health
                                                   │
                                     worker ───────┤   same image; cron, imports, archives, retention
                                                   │
                 ┌──────── internal "data" network (no Internet) ┐
                 │  MariaDB 11.8                                  │
                 └────────────────────────────────────────────────┘
 backup (Restic) ── encrypted snapshots of SQL + uploads + job state → local or S3/B2/REST/Azure
```

* **Isolation** is WordPress's own: per-site roles and capabilities, hardened so only operators can touch code (`DISALLOW_FILE_MODS`, capability filters, read-only image). Cross-tenant access is covered by automated tests. It is *application-level* multi-tenancy — read the [threat model](docs/architecture/threat-model.md).
* **Everything installable is locked**: WordPress core, plugins, themes and language packs are downloaded by SHA-256 from `dependencies.lock.json` at image build time; container bases are pinned by digest; CI actions by commit SHA.
* **Background work** (scheduled posts, imports, exports, retention) runs in a separate worker container; public requests never trigger cron.
* **No request logs.** The platform writes an allowlisted security event log (event name, time, numeric IDs) and nothing else.

Read the full [architecture overview](docs/architecture/overview.md).

## Migration is a first-class feature

Upload a WordPress export (`.xml`) or a ZIP with the export plus media, from WordPress, WordPress Multisite (`blogs.dir`/`/files/` layouts) or a NoBlogs4Ever archive. The importer:

1. stores the original untouched and inventories it **before** writing anything (posts, pages, authors, media, shortcodes, blocks, custom types, links to the old host);
2. hardens intake against zip-slip, symlinks, decompression bombs, entity expansion and oversized input — nothing in the archive is ever executed;
3. asks you to map every old author to a member (or invite them by email);
4. imports in small resumable batches — re-running never duplicates content;
5. verifies every media binary by checksum, rewrites URLs (including image sizes and legacy `/files/` paths) only when the local file exists, rebuilds menus, Additional CSS and Site Editor parts for curated themes;
6. produces a report of everything it could not carry over and any remaining dependency on the old host.

See the [import guide](docs/user-guide/importing.md) and the [migration engine](docs/architecture/migration-engine.md).

## Project status

**1.0 release candidate.** The platform is feature-complete against its [specification](docs/reference/specification.md) for the core scope and the full test pyramid passes: unit and security tests, 170+ live integration assertions against WordPress and MariaDB, 17 browser workflows (desktop and mobile), an isolated backup-restore drill, and a production-overlay smoke test with TLS and container hardening.

What "release candidate" means here, honestly:

* It has not yet run a real community in production. Operators must still qualify their own DNS, TLS, SMTP, off-site backups and monitoring ([production checklist](docs/operator-guide/production-checklist.md)).
* Multilingual (Polylang) and federation (ActivityPub) are packaged but **experimental** and off by default.
* Mastodon/social auto-posting is not implemented.

The [project status page](docs/getting-started/project-status.md) lists every feature's maturity and the roadmap — a good place to find something to work on.

## Documentation

The [`docs/`](docs/README.md) folder is the full manual (also publishable as a GitBook):

* **Getting started** — [quick start](docs/getting-started/quickstart.md), [concepts](docs/getting-started/concepts.md), [status & roadmap](docs/getting-started/project-status.md)
* **User guide** — accounts & two-factor, sites, publishing, collaborators, importing, exporting, analytics, encrypted contact
* **Operator guide** — configuration, deployment, production checklist, policy & approvals, backups, monitoring, upgrades, incident response
* **Architecture** — overview, security model, threat model, privacy, migration engine, REST API
* **Contributing** — development, testing, code tour, dependencies, releasing

## Contributing

Contributions are very welcome — from bug reports and translations to new importers. Start with [CONTRIBUTING.md](CONTRIBUTING.md) and the [code tour](docs/contributing/code-tour.md). Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md), and follow our [Code of Conduct](CODE_OF_CONDUCT.md).

## License

NoBlogs4Ever is free software under the [GNU General Public License v2.0 or later](LICENSE), the same license as WordPress. Bundled third-party components keep their own licenses ([details](docs/reference/licenses.md)).
