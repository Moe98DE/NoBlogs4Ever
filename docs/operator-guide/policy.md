# Platform policy, approvals and read-only mode

Operators manage platform-wide policy in **Network Admin → Platform policy**. Every change is recorded in the security event log shown at the bottom of the same page.

## Registration

| Policy | Who can create an account |
|---|---|
| **Invitation** (default) | Nobody signs up alone. Site administrators invite people from *Users → Add New*; operators can add users in *Network Admin → Users*. Signed-in people can still create sites. |
| **Approval** | Anyone can request an account at `/wp-signup.php`. The request is held: no activation email is sent and its activation key is replaced by an unguessable value. Operators see it under **Registrations awaiting approval** and approve (the applicant receives a fresh activation link) or reject (the request is deleted). `NBE_ADMIN_EMAIL` receives a notice for each new request. |
| **Allowlist** | Self-registration for email addresses at the exact domains listed. |
| **Unrestricted** | Anyone. Combine with the deny list and watch for abuse; production checks require `NBE_ACK_OPEN_REGISTRATION=yes`. |

The **deny list** applies to everyone, including invitations. Self-registration is rate-limited per connection (5 per hour), as is site creation.

**Sites per person** limits how many sites one account may administer; operators are exempt.

**Require two-factor for site administrators** extends the operator MFA requirement to anyone with administrator rights on any site. Affected users are redirected to their profile until they enrol a TOTP app or security key.

## Emergency read-only mode

Tick **Pause all writes on every site** and save. While active:

* published sites, feeds and media stay readable;
* every non-GET request is refused with HTTP 503 — publishing, editing, uploads, comments, registrations, password resets, settings, imports, contact messages;
* write capabilities are removed so the dashboard hides editing actions, and a banner explains the situation;
* the worker stops publishing scheduled posts and stops imports and archives (it keeps writing its heartbeat);
* signing in (including the two-factor step) keeps working, and operators can save this page to end maintenance.

Read-only mode is a *coordination* tool for incidents and maintenance. It is enforced by the application, so it does not protect against an attacker who can already run code on the server, and operators with WP-CLI or database access can bypass it. See [Incident response](incident-response.md).

From the command line:

```sh
docker compose run --rm cli wp --allow-root site option update nbe_readonly 1   # on
docker compose run --rm cli wp --allow-root site option update nbe_readonly 0   # off
```

## Recent security events

The table lists the last 100 events from the allowlisted event log: sign-ins and failed sign-ins (without usernames), role and membership changes, operator grants and revocations, site creation/deletion/spam, policy changes, approvals, plugin and theme switches, import/export activity, contact-key changes and worker errors. Each row holds only the time, event name, site ID, acting user ID and a few numeric or coded fields. Files are kept for `LOG_RETENTION_DAYS` (7 by default) in the private `logs` volume.

## Other network settings

Standard WordPress network settings (*Network Admin → Settings*) still apply — for example the welcome email text, banned email domains, or site upload space. Keep "Plugins" menu enabled so site administrators can see the curated plugins they may toggle.
