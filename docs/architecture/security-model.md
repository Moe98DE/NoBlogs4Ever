# Security model

WordPress's own mechanisms — capabilities, nonces, password hashing, session tokens, KSES sanitisation — are authoritative. NoBlogs4Ever narrows them and adds platform controls; it does not invent its own authentication or cryptography (the encrypted contact form uses the browser's WebCrypto API).

## Trust boundaries

| Principal | Trusted with |
|---|---|
| **Host operator** (shell, Docker, database) | Everything. Can bypass every application control. |
| **Network operator** (super admin, MFA required in production) | Network settings, users, sites, curated software activation. Cannot install code (`DISALLOW_FILE_MODS`), but can grant roles and read all content. |
| **Site administrator** | Content, members, presentation and settings of their own site. No code, no unfiltered HTML, no other sites. |
| **Editor / author / contributor / subscriber** | Standard WordPress role semantics within one site. |
| **Visitor** | Public content of public sites; registration per policy; comments; encrypted contact. |

## Controls

**Code and configuration**

* Everything executable is in the image, read-only for the web server; `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, automatic updates off.
* `map_meta_cap` denies to non-operators: plugin/theme install/update/edit/delete, `unfiltered_html`, `unfiltered_upload`, language updates, all network capabilities.
* Site administrators may only toggle plugins on the curated list.

**Authentication**

* Passwords hashed by WordPress core; sessions in WordPress session tokens; lifetime `SESSION_HOURS`; "sign out everywhere".
* Two-factor: TOTP, WebAuthn security keys, backup codes (Two Factor plugin). Email codes are disabled. Operators are forced to enrol in production; optional for site administrators.
* Rate limits (per client address, in memory, HMAC-keyed): sign-in 20/15 min, password reset 8/15 min, registration 5/h, site creation 5/h, comments 2/15 s and 10/10 min, contact 5/h, imports 5/h per user.
* Enumeration resistance: generic sign-in errors, identical lost-password response, REST user listing hidden from visitors, `?author=N` returns 404, oEmbed author fields removed.
* XML-RPC and application passwords are disabled.

**Browser**

* Cookies are host-only (no cross-tenant cookie sharing), `HttpOnly`, `Secure` over HTTPS, `SameSite=Lax`.
* Headers: HSTS (production), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (camera/microphone/geolocation off), CSP `frame-ancestors 'self'; object-src 'none'; base-uri 'self'`. The CSP does not restrict scripts, because the block editor and themes require inline scripts; script injection is prevented by sanitisation (no `unfiltered_html` for tenants).
* Uploaded files are served with their checked MIME type, `nosniff`, a sandboxing CSP (except PDF) and `Content-Disposition: attachment` for non-media types.

**Uploads**

* Extension allowlist; content must match the extension (`wp_check_filetype_and_ext`); 40-megapixel image limit; size and per-site quota limits. PHP and other executables are refused, and Apache's PHP engine is disabled below uploads as a second layer.

**Tenancy**

* All platform queries are scoped to the current site; import jobs, archives and analytics check site and capability on every access.
* Members-only sites are enforced for pages, feeds, REST, sitemaps, robots and media.
* Cross-tenant tests: site admins cannot administer other sites, cannot start imports into them, cannot read their analytics or archives.

**Migration intake** — see [Migration engine](migration-engine.md#intake-safety).

**CSRF** — every state-changing form uses WordPress nonces; the contact endpoint is cookie-less and checks `Origin`.

**Infrastructure** (production overlay)

* Only Caddy publishes ports. MariaDB is on an internal network without Internet access. The backup container reaches only the database and its repository.
* Read-only root filesystems for edge, app, worker and backup; capabilities dropped to the minimum; `no-new-privileges`; PID, memory and CPU limits.
* Pinned image digests, SHA-256-locked downloads, SHA-pinned CI actions, SBOM and vulnerability scanning.

**Logging** — no request logs; an allowlisted event log that structurally cannot contain passwords, tokens, cookies, URLs, IPs or message contents.

## Known limits

* Application-level multi-tenancy: a vulnerability in WordPress core, a curated plugin or PHP that yields code execution affects all tenants, because they share processes and database credentials.
* Network operators can read all content; there is no end-to-end encryption of posts.
* Read-only mode and MFA enforcement are application controls; they do not constrain someone with shell or database access.
* Restic protects backups at rest; the repository credentials plus `backup_password` expose every tenant.

See the [threat model](threat-model.md).
