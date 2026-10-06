# Incident response

Use this when you suspect an account, a site or the platform itself is compromised. Write down UTC times and actions as you go.

## 1. Contain

* **Account-level** (one stolen password or session): revoke the user's sessions (`wp user session destroy <login> --all`), reset their password, remove super admin if applicable.
* **Platform-level**: enable **read-only mode** (Network Admin → Platform policy, or `wp site option update nbe_readonly 1`) and pause the worker (`docker compose stop worker`). Published sites stay online; nothing can be changed.
* **Suspected code execution on the server**: do not rely on application-level controls. Put a static maintenance page at the edge (or stop the stack) and isolate the host from the network. Read-only mode is a coordination tool, not forensic isolation; anyone with WP-CLI or database access can bypass it.

## 2. Preserve evidence

Copy to access-controlled storage: the security event log (`logs` volume), `docker compose logs`, the running image digest (`docker inspect`), a database dump, and a disk snapshot if the host itself is suspect. Collect only what you need — the platform deliberately has no request logs, and you should not start collecting readers' data for convenience.

## 3. Investigate

* Audit log: operator grants (`operator_granted`), role and membership changes, plugin/theme switches, policy changes, contact-key changes, sign-ins.
* `wp super-admin list`, `wp user list --role=administrator --url=…` on affected sites, recently created users.
* Content changes: `wp post list --orderby=modified --url=…`, revisions.
* Scheduled events: `wp cron event list --url=…`.
* Image integrity: compare the running digest with the release manifest; rebuild from the tagged source if in doubt.
* Upstream: security advisories for WordPress, the curated plugins, PHP, MariaDB, Caddy and the base image.

## 4. Eradicate and rotate

* Deploy a known-good image digest on a clean host if the server may be compromised.
* Rotate secrets according to scope: `wp_salt` (signs everyone out and invalidates all cookies), operator passwords and second factors, `db_password`/`db_root_password`, `smtp_password`, backup storage credentials. `backup_password` only if the repository itself was exposed (then re-key with `restic key add`/`remove`).
* External integrations: if experimental plugins stored OAuth tokens or keys, revoke them at the provider. Deleting posts does not revoke a connected application.

## 5. Recover

Restore affected sites or the whole platform from a snapshot taken before the incident, first into isolation ([recovery procedures](backup-restore.md#recovery-procedures)) with mail and federation disabled. Check tenant isolation, MFA, media and content integrity, then switch over. Recover legitimate content created after the snapshot through full site archives.

## 6. Resume and notify

Turn read-only off only when the cause is fixed and checks pass (`./noblogs4ever doctor`, `make monitoring-check`, a manual publish test). Notify affected users with facts: time window, what data was involved, what you did, what they should do (for example change passwords, re-enrol two-factor). Check your legal notification duties (e.g. GDPR's 72-hour rule). Document lessons learned and how long incident evidence will be kept.
