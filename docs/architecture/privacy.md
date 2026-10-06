# Privacy and data retention

This page states what the platform stores and for how long, in terms you can reuse in your installation's privacy notice. It describes the software's defaults; your hosting, email and backup providers have their own practices.

## Requests

* **No request logs.** Apache access/error logging and Caddy request logging are disabled. URLs, IP addresses, user agents and referrers of page views are not written anywhere.
* **Rate limiting** keeps short-lived counters in a memory-backed volume. Each counter is named by an HMAC of the action, client address and time window (keyed with a platform secret), contains only a number, and is deleted within about two hours — or immediately when the container restarts.
* **Container output** (startup messages, PHP fatal errors) is kept by Docker, rotated at 3 × 10 MB per container.
* The client address is visible in memory while a request is processed, as with any web server.

## Accounts and content

The database stores what WordPress needs: usernames, email addresses, password hashes, profile fields, two-factor configuration (TOTP secrets and security-key public keys, not application-layer encrypted), session tokens, site memberships and roles, and all content, comments, media and settings. Operators can access all of it. Deleting a site or account removes it from the live database; see backups below.

## Comments

Commenters' IP addresses and browser user agents are never stored. Name, email and website are stored as the commenter provides them, according to the site's discussion settings. Avatars are a generic local image unless the operator enables Gravatar.

## Security event log

An allowlisted structured log records events such as sign-ins, failed sign-ins (without usernames), role and membership changes, operator grants, site creation and deletion, policy changes, imports and exports. Each line contains only: time, event name, site ID, acting user ID, and optionally a target user, site, job ID, count, role or short code. It cannot contain passwords, tokens, cookies, URLs, IP addresses or message contents. Files are deleted after `LOG_RETENTION_DAYS` (default 7 days).

## Analytics (opt-in per site)

When a site enables page counting, each counted view increments a counter for (site, UTC day, post, browser family, device class). No IP address, user agent string, cookie, referrer, URL query or visitor identifier is stored, and no script runs in the reader's browser. Signed-in members, previews, feeds and error pages are not counted; members-only and non-indexed sites are never counted. Rows are deleted after `ANALYTICS_RETENTION_DAYS` (default 14 days). Only that site's administrators can see its numbers.

## Directory (opt-in per site)

Listed public sites expose their name, tagline and latest published, non-password-protected posts on the main site. Unlisting removes them from the index within minutes.

## Migration data

Uploaded archives, parsed copies and reports are kept privately (outside the web root) for `MIGRATION_RETENTION_DAYS` (default 30 days) after the import finished, then deleted. A table mapping source item IDs to new IDs remains so that re-imports do not duplicate content. Imported comments never carry IP addresses; imported user passwords are never used.

## Full site archives

Built on request, stored privately, downloadable by the site's administrators, deleted after `EXPORT_RETENTION_DAYS` (default 7 days). They contain no credentials or secrets.

## Encrypted contact

Message content is encrypted in the visitor's browser; the platform handles only ciphertext. The platform and the email provider can observe that a message was sent to a site, when, its approximate size, the sender's connection while sending (not stored) and the email envelope. A compromised server could serve altered code or keys. See [Encrypted contact form](../user-guide/encrypted-contact.md).

## Email

Transactional email (activation, password reset, invitations, import notices) is handed to the configured SMTP provider, which sees recipients, senders, subjects, contents and timing.

## Third parties

By default pages load no third-party scripts, fonts, avatars or emoji images. Content that authors embed (videos, maps, posts) is loaded from those services when a reader views it: it can disappear, is not backed up, and reveals the reader's visit to that service under its own privacy policy. Federation (experimental) copies public posts to followers' servers, which may retain them after local deletion.

## Backups

Encrypted Restic snapshots of the database, media and import/archive workspaces are kept for `BACKUP_RETENTION_DAYS` (default 14 daily snapshots). Deleted content therefore persists in backups until they expire. Actual deletion also depends on the storage provider's versioning and retention settings.

## What we deliberately do not do

No advertising, no behavioural analytics, no fingerprinting, no cross-site identifiers, no third-party comment services, no IP-based reputation databases.
