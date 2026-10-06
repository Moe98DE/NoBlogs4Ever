# Concepts

## Network, sites and tenants

A NoBlogs4Ever installation is one **WordPress Multisite network** in subdomain mode. The network has a base domain (`PLATFORM_DOMAIN`, e.g. `example.org`); every site lives at `https://<slug>.example.org`.

Each site is treated as a separate **tenant**: its posts, pages, drafts, comments, media, menus, theme settings, analytics, contact-form key and members belong to it alone. The main site at the base domain hosts the platform's own pages (for example the Discover directory).

Tenants share one PHP runtime and one database. Isolation is enforced by WordPress capabilities plus the platform's policy layer, and verified by automated cross-tenant tests — it is *application-level* isolation, not separate containers per site. The [threat model](../architecture/threat-model.md) explains what that does and does not protect against.

## People and roles

There is **one account per person** across the network. An account can belong to many sites with a different role on each:

| Role | Scope | Typical use |
|---|---|---|
| **Operator** (WordPress "super admin") | The whole network | The people running the service. Needs two-factor sign-in in production. |
| **Administrator** | One site | The site's owner(s): settings, theme, members, import/export, deletion. |
| **Editor** | One site | Publishes and edits everyone's posts, moderates comments. |
| **Author** | One site | Publishes and edits their own posts, uploads media. |
| **Contributor** | One site | Writes drafts for review; cannot publish or upload. |
| **Subscriber** | One site | Can read a members-only site and manage their profile. |

Site administrators are deliberately *not* able to install plugins or themes, edit code, use unfiltered HTML, manage other sites or change network settings, even on their own site.

## Accounts and sessions

Sign-in cookies are **host-only**: signing in on `garden.example.org` does not sign you in on `journal.example.org`. This prevents one tenant's address from ever receiving another tenant's session cookie, at the cost of signing in once per site you work on. Sessions expire after `SESSION_HOURS` (24 by default) and can be ended everywhere from **Your platform → Account security**.

## Curated software

Everything executable — WordPress core, plugins, themes, language packs — is listed with a SHA-256 checksum in `dependencies.lock.json` and baked into the application image at build time. The web server cannot write to code directories. Operators change software by building and deploying a new image ([Upgrades](../operator-guide/upgrades.md)).

Currently curated:

* Themes: **Twenty Twenty-Five** (block theme, Site Editor) and **Twenty Twenty-One** (classic theme, Customizer, widgets, menus)
* Plugins: **Two Factor** + **WebAuthn provider** (network-wide), **Classic Editor** (network-wide, block editor stays the default), **Polylang** and **ActivityPub** (experimental, hidden unless enabled)
* Languages: English, German, French, Spanish (more can be added to the lock file)

## The worker

A second container runs the same image as a **worker**. Every 30 seconds it publishes scheduled posts (WordPress cron for every site), advances imports, builds requested site archives, refreshes the discovery directory, and hourly applies data-retention rules. Public page views never trigger background work, and in read-only mode the worker only records a heartbeat.

## Read-only mode

Operators can put the whole network into **emergency read-only mode** from Network Admin → Platform policy. Published sites stay readable; writes of every kind (publishing, uploads, comments, registrations, settings, imports, scheduled posts) pause, while signing in and switching the mode off keep working. It is intended for incidents and maintenance windows ([Policy](../operator-guide/policy.md)).

## Data the platform keeps

Accounts, content, media and settings — as any WordPress site. Beyond that, by design, only: an allowlisted security event log (no IPs or URLs), short-lived anonymous rate-limit counters in memory, optional aggregate page-view counts, migration workspaces for 30 days, and encrypted backups. Details in [Privacy and data retention](../architecture/privacy.md).
