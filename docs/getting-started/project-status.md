# Project status and roadmap

**Version:** 1.0.0 release candidate · **License:** GPL-2.0-or-later

The scope is defined by the [product specification](../reference/specification.md). This page records, feature by feature, how mature each part is. *Tested* means an automated test in this repository exercises it on every CI run.

## Maturity legend

* ✅ **Stable** — implemented and covered by automated tests.
* 🟡 **Usable** — implemented, partly tested or depending on per-deployment qualification.
* 🧪 **Experimental** — packaged, off by default, not covered by release tests.
* ⬜ **Not implemented** — planned or explicitly out of scope.

## Publishing

| Feature | Status | Notes |
|---|---|---|
| Subdomain sites, self-service creation, reserved/invalid name protection | ✅ | Browser + integration tests |
| Block editor; Classic Editor per site/per post | ✅ | Browser tests publish with both |
| Posts, pages, drafts, scheduling, revisions (30 kept), categories, tags, feeds | ✅ | WordPress core; scheduled posts run in the worker |
| Media upload with MIME/extension/content and pixel checks; per-site quota | ✅ | Executable and SVG uploads rejected |
| Authorized media delivery (drafts, private/members-only sites), HTTP ranges, caching | ✅ | |
| Curated themes (block + classic), Site Editor, Customizer, menus, Additional CSS | 🟡 | WordPress core features; not exhaustively UI-tested |
| oEmbed (YouTube, Vimeo, …) and operator-allowlisted iframes (sandboxed) | ✅ | |
| Comments with moderation, rate limits, no stored IP/user agent | ✅ | |
| Network directory, recent posts and topics across opted-in sites | ✅ | |
| Multilingual content (Polylang) | 🧪 | Off unless `ENABLE_EXPERIMENTAL_INTEGRATIONS=yes` |
| ActivityPub federation | 🧪 | Same; remote copies cannot be recalled |
| Admin interface translations | 🟡 | German, French, Spanish core packs bundled |

## Accounts and security

| Feature | Status | Notes |
|---|---|---|
| Registration policies: invitation, approval queue, domain allowlist, open; deny list | ✅ | |
| TOTP enrolment and sign-in | ✅ | Browser test enrols an authenticator and signs in with a one-time code; replay rejected (integration) |
| Security keys (WebAuthn), backup codes | 🟡 | Provider registered and tested; WebAuthn enrolment needs HTTPS and an authenticator, so it is not browser-tested yet |
| MFA required for operators in production; optional for site admins | ✅ | |
| Login/reset/registration/comment/contact/site-creation rate limits without IP history | ✅ | |
| User enumeration resistance (REST users, `?author=`, lost-password) | ✅ | |
| Session expiry and "sign out everywhere" | ✅ | |
| Tenant capability boundaries (no plugins/themes/code/unfiltered HTML) | ✅ | |
| Audit log of security-relevant events | ✅ | Allowlisted fields only |
| Emergency read-only mode | ✅ | Browser + integration tests |

## Migration and portability

| Feature | Status | Notes |
|---|---|---|
| WXR 1.0–1.2 and ZIP (WXR + media), several WXR files per archive | ✅ | |
| Hardened intake (zip-slip, symlinks, bombs, DTD/entities, limits) | ✅ | Unit tests |
| Inventory before import, author mapping, invite-by-email | ✅ | |
| Idempotent, resumable batches; retry; email notice on completion | ✅ | |
| Media by checksum; image derivatives; `/files/` and `blogs.dir` paths; galleries; featured images | ✅ | |
| Menus, Additional CSS, synced patterns, Site Editor templates for curated themes | ✅ | |
| Password-protected, sticky and scheduled posts | ✅ | |
| Post-import source-independence validator | 🟡 | CLI tool for operators (`make migration-validate`) |
| Full site archive export (WXR + media + settings + checksums) and round-trip re-import | ✅ | |
| Remote media fetching from the old host | ⬜ | Deliberately not implemented (SSRF/consent); provide media in the archive |
| Widget import, plugin-specific data, user passwords | ⬜ | Not portable; reported where detectable |

## Operations

| Feature | Status | Notes |
|---|---|---|
| Development stack, live-reload overlay, demo seed | ✅ | |
| Production overlay: TLS, read-only containers, capability drops, internal DB network | ✅ | Production smoke test in CI |
| Operator CLI: setup / check / doctor | ✅ | |
| Encrypted Restic backups (local, S3, B2, REST, Azure), freshness check | ✅ | |
| Isolated full restore drill (boot, login, tenants, media, export) | ✅ | CI runs it |
| Health endpoints and monitoring check | ✅ | |
| SBOM and vulnerability scan; pinned images, actions and downloads | ✅ | |
| Horizontal scaling (several app nodes, object storage for media) | ⬜ | Not designed yet |

## Roadmap — good places to help

Roughly in priority order:

1. **First instances hosting former NoBlogs writers.** Feedback from real operators on the [production checklist](../operator-guide/production-checklist.md), and from writers on the import.
2. **Importer for static website mirrors** (wget, HTTrack, SingleFile, WebRecorder WARC) for writers who only saved the public pages of their NoBlogs site. Anonymised sample mirrors are very welcome.
3. **A public list of instances** that welcome former NoBlogs writers, maintained by the community.
4. **Browser-tested WebAuthn enrolment** using Playwright's virtual authenticator over HTTPS.
5. **Qualify Polylang and ActivityPub** (migration, privacy, deletion behaviour) and graduate them from experimental.
6. **Mastodon / social auto-posting** with OAuth tokens encrypted at rest (spec §20).
7. **Operator UI for large imports** (today a CLI path) and per-site storage reports.
8. **Accessibility review** of the platform's own admin pages with assistive technology (they use WordPress components and labelled forms, but no formal audit has been done).
9. **Scale-out design**: shared media storage, several app containers, a reverse-proxy page cache for anonymous traffic.
10. **More languages** in `dependencies.lock.json`, and a `noblogs4ever` text domain for the platform's own interface strings so they can be translated.

Open an issue before large changes so the design can be discussed — see [CONTRIBUTING.md](https://github.com/Moe98DE/noblogs4ever/blob/main/CONTRIBUTING.md).
