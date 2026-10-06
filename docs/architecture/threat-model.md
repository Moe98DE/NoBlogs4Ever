# Threat model

**Assets:** unpublished content and members-only sites; accounts and sessions; operator privileges; media; encrypted-contact messages; readers' privacy; backups; availability of published sites.

**Assumptions:** the host operator, the released image (WordPress, curated plugins/themes, PHP, MariaDB, Caddy) and the TLS/DNS/SMTP/backup providers are trusted. Tenants and visitors are untrusted. Migration archives are untrusted.

## Threats, controls and residual risk

| Threat | Controls | Residual risk |
|---|---|---|
| **Compromised author / editor / contributor** | Per-site roles; no `unfiltered_html`; KSES sanitisation; upload checks | Can damage or publish within their granted scope on that site |
| **Compromised site administrator** | Cannot install code, edit files, use unfiltered HTML, change network settings or reach other sites; imports and archives bound to their site; deletion needs email confirmation | Full control over that site's content, members and settings |
| **Compromised network operator** | MFA required in production; named accounts; audit log of grants, roles, plugin/theme and policy changes; read-only mode | Can read and modify all tenants; cannot install code via the UI. Rotate salts and sessions on suspicion |
| **Cross-tenant access** | Site-scoped capability checks; host-only cookies; site-bound job/export/analytics queries; members-only enforcement incl. media; automated cross-tenant tests | Shared PHP runtime and database: a code-execution bug crosses tenants |
| **Vulnerable plugin or theme** | Small curated set; checksum-locked; experimental plugins off by default; SBOM + vulnerability scan; read-only code | Zero-days in WordPress core or curated plugins affect everyone until patched |
| **Malicious upload** | Extension allowlist; content/extension match; no SVG/HTML/PHP; pixel limit; PHP disabled under uploads (Apache) and blocked at the edge (Caddy); authorised delivery with `nosniff` and sandbox CSP | Malformed media exploiting GD/browser bugs |
| **XSS** | No `unfiltered_html` for tenants; KSES on posts, comments and imports; iframes only from operator allowlist, sandboxed | Admin-side XSS in WordPress or a plugin; CSP does not block inline scripts |
| **CSRF** | WordPress nonces on every platform form and admin-post action; contact endpoint checks `Origin` and uses no cookies | Bugs in third-party plugin endpoints |
| **SSRF** | No remote media fetching during import; validator only requests the tenant's own URLs through the local app; oEmbed limited to WordPress providers | oEmbed discovery and experimental federation make outbound requests by design |
| **SQL injection** | `$wpdb->prepare()` throughout; PHPStan static analysis | Bugs in core/plugins |
| **Privilege escalation** | `map_meta_cap` denies operator capabilities to everyone else; role changes logged; tests for each role | Logic errors in WordPress capability mapping |
| **Credential stuffing / brute force** | Sign-in rate limit per client; generic errors; MFA | Distributed attacks below per-address limits; no IP reputation |
| **Password-reset abuse** | Reset rate limit; identical response for unknown accounts; WordPress single-use reset keys | Mailbox compromise grants account access unless MFA is enabled |
| **Session theft** | `HttpOnly`, `Secure`, `SameSite=Lax`, host-only cookies; HSTS; configurable lifetime; sign-out-everywhere | A stolen live session works until it expires or is revoked; MFA does not help once signed in |
| **Malicious migration archive** | Preflight of every entry: traversal, absolute paths, symlinks, devices, duplicates, entry count, size, compression ratio; private 0600 extraction; nothing executed; DTD/entities rejected; no network during parsing; operator limits behind acknowledgement and hard ceilings | SimpleXML memory use grows with file size (bounded by limits) |
| **Malicious XML** | As above (`LIBXML_NONET`, no DTD) | — |
| **Dependency compromise** | SHA-256 locks for every download; digest-pinned images; SHA-pinned CI actions; Dependabot proposals reviewed by humans | A malicious upstream release that is reviewed and accepted; Debian packages from mutable repositories at build time |
| **Leaked OAuth/API token** | No social OAuth implemented; tokens and secrets never imported or exported | Experimental plugins may store their own tokens |
| **Leaked backup** | Restic encryption and authentication; password escrowed separately; remote storage credentials separate | Repository + password = every tenant's data |
| **Server compromise** | Read-only containers, dropped capabilities, internal DB network, no Docker API exposure; incident procedure; rebuild from tagged image | Full compromise of all data on the host |
| **Denial of service / resource exhaustion** | Edge host allowlist; per-container CPU/memory/PID limits; upload, quota, archive and import limits; worker time budget; mail cap | A single host with shared resources; no CDN or WAF included |
| **Reader surveillance** | No request logs; no tracking scripts; no third-party requests by default; aggregate-only analytics; no stored comment IPs | SMTP providers, embeds and federation peers see metadata outside the platform |

## Application-level multi-tenancy

Every site runs in the same PHP processes with the same database account and the same uploads volume. Isolation therefore relies on correct application code. This is the standard WordPress Multisite model and keeps operations simple; it is *not* equivalent to giving each tenant its own container, database user or virtual machine. Operators with stronger isolation needs should run separate NoBlogs4Ever instances per trust group.

## Out of scope

A malicious host operator; a compromised operator browser; legal compulsion of providers; traffic analysis by network observers; physical attacks on the host.
