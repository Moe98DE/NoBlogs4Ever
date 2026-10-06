# NoBlogs4Ever

NoBlogs4Ever is a **managed, privacy-first publishing platform** built on WordPress Multisite. It lets a small operator team host websites for individuals and collectives — `https://<site>.<your-domain>` — while keeping all code, upgrades and infrastructure under central control.

This manual is written for three audiences:

| You are… | Start here |
|---|---|
| **A writer or site owner** using a NoBlogs4Ever service | [Your account](user-guide/account.md), [Creating sites](user-guide/sites.md), [Importing an existing site](user-guide/importing.md) |
| **An operator** running the service for a community | [Concepts](getting-started/concepts.md), [Configuration](operator-guide/configuration.md), [Deploying to production](operator-guide/deployment.md) |
| **A developer** who wants to contribute | [Quick start](getting-started/quickstart.md), [Code tour](contributing/code-tour.md), [Testing](contributing/testing.md) |

## Design principles

1. **WordPress does the publishing.** Familiar editors, roles, feeds, comments and WXR compatibility come from WordPress itself; NoBlogs4Ever adds policy around it rather than replacing it.
2. **Tenants never run code.** Plugins, themes and language packs are curated, checksum-locked and baked into an immutable image. Site administrators control content and presentation, not the execution environment.
3. **Privacy by default.** No request logs, no tracking scripts, no third-party requests from pages, no stored comment IP addresses. What *is* collected is documented in [Privacy and data retention](architecture/privacy.md).
4. **No lock-in.** Every site can be exported, including media and settings, without operator help — and imported elsewhere.
5. **Honest claims.** The documentation states what is tested, what is experimental and what is not implemented. See [Project status](getting-started/project-status.md).

## At a glance

* WordPress 7.1 Multisite (subdomain mode), PHP 8.4, MariaDB 11.8, Caddy 2.10, Restic 0.18 — all pinned.
* One must-use plugin (`app/mu-plugins/nbe/`) with ~15 small modules: security, registration, sites, media, privacy, embeds, analytics, discovery, export, migration, health, worker, admin UI.
* Operator tooling: `make` targets, a `./noblogs4ever` setup/check/doctor CLI, backup/restore drills, production smoke test.
* License: GPL-2.0-or-later.
