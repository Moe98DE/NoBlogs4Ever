<div align="center">

# NoBlogs4Ever

**Bring your NoBlogs back online.**

A self-hostable, privacy-first blogging platform in the spirit of NoBlogs — so that anyone with a server and the will to run it can give people their sites back.

[![CI](https://github.com/Moe98DE/noblogs4ever/actions/workflows/ci.yaml/badge.svg)](https://github.com/Moe98DE/noblogs4ever/actions/workflows/ci.yaml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
![Status: release candidate](https://img.shields.io/badge/status-1.0%20release%20candidate-orange)

[Why this exists](#why-this-exists) · [Bring your blog back](docs/user-guide/coming-from-noblogs.md) · [Run your own](#quick-start) · [Documentation](docs/README.md) · [Contributing](CONTRIBUTING.md)

</div>

---

## Why this exists

For nearly two decades, NoBlogs gave activists, collectives, artists and ordinary writers a place to publish without ads, without tracking, and without handing their readers over to anyone. When NoBlogs closed in September 2026, thousands of those sites went dark with it — and the people behind them were left holding backups and nowhere to put them.

NoBlogs4Ever started as a plan to host a successor privately. Doing that properly — with the same respect for privacy — turned out to cost more than one person can carry. So instead of one new host, this project gives the work away: **a complete, tested platform that anyone with the means can run**, for themselves, for their collective, or for a whole community of former NoBlogs writers.

If you have a server, you can put those blogs back on the internet. If you have a backup, you can find someone who runs NoBlogs4Ever and bring your blog home.

**What it keeps from that spirit**

* **Your readers stay private.** No request logs, no tracking scripts, no third-party requests from pages, no stored commenter IP addresses.
* **Your writing stays yours.** Import your old export with its media, links and menus; export everything again, any time, without asking anyone.
* **Nobody runs code they shouldn't.** Writers get WordPress's familiar editor and curated themes — never a server, a shell or a plugin installer.
* **Small crews can run it.** One Docker host, honest documentation, encrypted backups, tested restores.

NoBlogs4Ever is an independent, community project. It is not affiliated with or endorsed by the former NoBlogs operators; it simply tries to carry the idea forward. Under the hood it is WordPress Multisite plus one must-use plugin, a hardened container stack and operator tooling — not a new CMS.

## What you get

| For writers and site owners | For operators | For readers |
|---|---|---|
| Block editor by default, Classic Editor when wanted | One-command local stack, production overlay with TLS | No tracking scripts, cookies or third-party requests by default |
| Sites at `<slug>.<domain>` in one click | Tenants can't install plugins, themes or run code | Comment IP addresses are never stored |
| Invite collaborators with editorial roles | Registration policy: invitation, approval queue, allowlist or open | Members-only sites protect pages, feeds, REST and media |
| **Bring back** NoBlogs/WordPress exports with media, menus and CSS | MFA (TOTP / security keys) required for operators | Optional browser-encrypted contact form |
| **Export** everything: WXR or a full ZIP archive with media | Encrypted Restic backups + automated isolated restore drill | Aggregate-only, opt-in page counts |
| Optional, cookie-free page-view analytics | Emergency read-only mode, health checks, audit log | Opt-in network directory of public sites |

## Quick start

Want to run a NoBlogs4Ever of your own? Try it locally first. You need Docker with Compose v2.24+, Python 3, ~4 GB RAM and 5 GB disk. `lvh.me` and all its subdomains resolve to `127.0.0.1`, so tenant subdomains work locally without editing `/etc/hosts`.

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

## Bringing old blogs home

Getting old sites back online is what this project is for, so the importer gets the most care. Upload a WordPress export (`.xml`) — the kind NoBlogs and any WordPress site produce — or a ZIP with the export plus media. Old Multisite layouts such as `blogs.dir/…/files/` and `/files/…` links are understood. The importer:

1. stores the original untouched and inventories it **before** writing anything (posts, pages, authors, media, shortcodes, blocks, custom types, links to the old host);
2. hardens intake against zip-slip, symlinks, decompression bombs, entity expansion and oversized input — nothing in the archive is ever executed;
3. asks you to map every old author to a member (or invite them by email);
4. imports in small resumable batches — re-running never duplicates content;
5. verifies every media binary by checksum, rewrites URLs (including image sizes and legacy `/files/` paths) only when the local file exists, rebuilds menus, Additional CSS and Site Editor parts for curated themes;
6. produces a report of everything it could not carry over and anything still pointing at the old address — because the old host is not coming back.

Writers: start with [Coming from NoBlogs](docs/user-guide/coming-from-noblogs.md). Details: [import guide](docs/user-guide/importing.md), [migration engine](docs/architecture/migration-engine.md).

## Project status

**1.0 release candidate.** The platform is feature-complete against its [specification](docs/reference/specification.md) for the core scope and the full test pyramid passes: unit and security tests, 170+ live integration assertions against WordPress and MariaDB, 17 browser workflows (desktop and mobile), an isolated backup-restore drill, and a production-overlay smoke test with TLS and container hardening.

What "release candidate" means here, honestly:

* It has not yet carried a real community in production. If you are about to be the first, the [production checklist](docs/operator-guide/production-checklist.md) walks you through DNS, TLS, mail, off-site backups and monitoring — and we would love to hear how it goes.
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

Every contribution helps get another blog back online — bug reports, translations, documentation, importers for unusual backups, or simply telling us what your NoBlogs backup looks like. Start with [CONTRIBUTING.md](CONTRIBUTING.md) and the [code tour](docs/contributing/code-tour.md). Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md), and follow our [Code of Conduct](CODE_OF_CONDUCT.md).

## License

NoBlogs4Ever is free software under the [GNU General Public License v2.0 or later](LICENSE), the same license as WordPress. Bundled third-party components keep their own licenses ([details](docs/reference/licenses.md)).
