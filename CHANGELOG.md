# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/) from 1.0.0 on.

## [Unreleased] — 1.0.0 release candidate

### Added
- Registration **approval queue** (held signups, operator approve/reject, operator notification) and correct invitation mode (site administrators can invite people; self-registration closed).
- **Full site archive** export (WXR + media + `site.json` + SHA-256 manifest), built by the worker, with retention; archives re-import with theme, CSS, menus and front page.
- **Site analytics** dashboard page and richer aggregates (browser family, device class), still without identifiers.
- **Network directory**: recent posts, topics and site list (`[nbe_recent]`, `[nbe_topics]`, `[nbe_directory]`, `/wp-json/nbe/v1/discover`) and a Discover page on the main site.
- Migration: menus, Additional CSS, synced patterns and Site Editor templates for curated themes; image-derivative URL rewriting; block-ID and `wp-image-` class remapping; password-protected and sticky posts; several WXR files per archive; invite-by-email author mapping; human-readable report; completion emails.
- Operator-allowlisted, sandboxed **iframe embeds** (`EMBED_IFRAME_HOSTS`).
- Optional **MFA for site administrators**; operator audit log in Network Admin; security events for role, membership, plugin, theme and policy changes.
- Site deletion guidance (export first, backup and federation retention) in the UI and confirmation email.
- Language packs (de_DE, fr_FR, es_ES) in the lock file; `scripts/lock.py` to maintain it.
- `compose.dev.yaml` live-code overlay, richer demo seed, `make help`.
- Test suites: 170+ integration assertions in four files, 32 browser runs (desktop + mobile), PHPStan level 5, shellcheck.
- GitBook-ready documentation, GPL-2.0-or-later `LICENSE`, Code of Conduct, issue and PR templates.

### Fixed
- Read-only mode blocked the sign-in form, locking operators out of turning it off.
- Remote backups were impossible in production (backup container had no network route to its repository).
- Rate limiting could treat every visitor as one client on hosts whose Docker networks use 10.x or 192.168.x addresses.
- New subdomain sites got `http://` addresses in production (WordPress default), causing redirect loops behind HTTPS.
- Migration failed for any image larger than 2560 px (integrity check compared the scaled copy).
- Imported password-protected posts became public.
- WordPress's comment flood check treated all anonymous commenters as one person because IPs are not stored.
- Files created by root-run WP-CLI tools were unwritable for the web server (WP-CLI now runs as `www-data`).
- Site creation redirected to the wrong host; user enumeration through REST and `?author=`; Gravatar and emoji CDN requests leaking reader visits.
- `production-smoke.sh` contained an unquoted comment line that aborted every run; operator import and validation variables were not passed into containers.

### Changed
- The platform plugin is split into focused modules (`Security`, `Registration`, `Sites`, `Media`, `Privacy`, `Analytics`, `Discovery`, `Export`, `Migration`, …) with an autoloader.
- CI split into static, full-stack and image-scan jobs; actions updated and SHA-pinned; vulnerability gate fails on fixable HIGH/CRITICAL findings.
- Evidence files moved to `docs/evidence/`; process reports replaced by the documentation and project status page.
