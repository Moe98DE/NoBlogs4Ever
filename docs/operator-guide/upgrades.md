# Upgrades and rollback

Operators own every software change. Tenants cannot update anything.

## What can change

| Component | Where it is pinned | How to update |
|---|---|---|
| WordPress core, plugins, themes, languages | `dependencies.lock.json` (URL + SHA-256) | `python3 scripts/lock.py set <kind> <slug> <version> <url>` |
| PHP/Apache base image | `Dockerfile` `FROM …@sha256:` | Update tag and digest |
| MariaDB, Caddy, Mailpit, Restic | `compose.yaml`, `config/backup.Dockerfile` (`@sha256:`) | Update tag and digest |
| WP-CLI | `Dockerfile` + `scripts/wp-cli.sha256` | Update URL and checksum |
| Debian packages | `apt-get` in `Dockerfile` | Rebuild (not reproducible byte-for-byte; the released image digest is what you deploy) |

See [Dependencies and supply chain](../contributing/dependencies.md) for the contributor workflow.

## Upgrade procedure

1. Read upstream release notes and security advisories for every changed component.
2. Pick the new release's image digest from its `release-manifest.json`; check CI passed for that tag (unit, integration, browser, restore drill, production smoke, vulnerability scan).
3. Staging first: restore last night's backup into a staging host ([restore procedure](backup-restore.md#recovery-procedures)), deploy the new digest there, run `make bootstrap` (applies database upgrades and platform configuration), `./noblogs4ever doctor`, and a quick manual pass through publishing, import and export.
4. Production: announce a short window, enable read-only mode, `make backup`, set the new `NBE_APP_IMAGE` digest, `docker compose -f compose.yaml -f compose.production.yaml up -d`, `… run --rm cli php /opt/nbe/scripts/bootstrap.php`, `make monitoring-check`, then turn read-only off.
5. Keep the previous image digest and the pre-upgrade snapshot until you are confident.

`make bootstrap` is safe to run repeatedly: it never resets passwords, reinstalls the network or changes a customised main-site theme.

## Rollback

* **Code only** (no database schema change): set `NBE_APP_IMAGE` back to the previous digest and restart.
* **After a WordPress database upgrade**: older WordPress versions may not run on an upgraded schema. Restore the pre-upgrade snapshot ([full restore](backup-restore.md#lost-server-corrupted-database-or-failed-upgrade-full-restore)) and redeploy the previous digest. Content created after the snapshot must be recovered from a full site archive of the affected sites taken before the rollback.
