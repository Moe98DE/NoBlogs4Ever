# Day-to-day runbook

## Daily (automated)

* Backup timer runs `make backup`; check the result in monitoring.
* `make monitoring-check` every few minutes.

## Weekly

* Read **Network Admin → Platform policy → Recent security events** for unexpected operator grants, role changes, plugin switches or bursts of failed sign-ins.
* Review **Registrations awaiting approval** if you use the approval policy.
* Look for failed imports (`failed_migrations` in health) and help the site owners.
* Check free disk space and per-site storage (`wp site list --fields=blog_id,domain` and `du -sh` on the uploads volume).

## Monthly

* `make restore-drill` and record how long it took.
* Review dependency updates (Dependabot PRs, WordPress security releases) and plan an [upgrade](upgrades.md).
* Re-run `./noblogs4ever check` and `make smtp-test`.

## Common tasks

| Task | How |
|---|---|
| Add an operator | Network Admin → Users → add or edit user → "Grant this user super admin privileges". They must enrol MFA at first sign-in. Never share operator accounts. |
| Remove an operator | Revoke super admin, then `wp user session destroy <login> --all`. |
| Suspend an abusive site | Network Admin → Sites → Deactivate / Archive / Spam. The event is logged. |
| Change a site's storage quota | Network Admin → Sites → Edit → Settings → Site Upload Space Quota. |
| Help a user who lost their second factor | Verify identity out of band, then `wp user meta delete <id> _two_factor_enabled_providers` (they will be asked to enrol again). |
| Reset a password | Users ask for a reset email themselves; operators can trigger one with `wp user reset-password <login>`. |
| Run WP-CLI | `make shell`, then `wp --allow-root --url=https://site.example.org …` (runs as the web server user). |
| Run one worker pass now | `docker compose exec worker php /opt/nbe/scripts/worker.php` |
| Rebuild the directory | `docker compose run --rm cli wp --allow-root eval '\NBE\Discovery::rebuild();'` |

Never grant super admin to solve an editorial permission problem; use site roles.

Site deletion: explain the [retention implications](../user-guide/sites.md#deleting-a-site) and suggest a full site archive first.
