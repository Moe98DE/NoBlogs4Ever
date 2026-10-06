# Dependencies and supply chain

## What is locked where

* **`dependencies.lock.json`** — WordPress core, plugins, themes and language packs: HTTPS URL, version and SHA-256. `scripts/fetch.php` downloads and verifies them at image build time; a mismatch fails the build.
* **Container images** — `Dockerfile`, `compose.yaml`, `config/backup.Dockerfile` pin every image by `@sha256:` digest.
* **WP-CLI and PHP-CS-Fixer** — checksum files in `scripts/`.
* **GitHub Actions** — pinned to commit SHAs (checked by `scripts/release-check.py`).
* **Dev tooling** — `composer.lock` (PHPStan), `package-lock.json` (Playwright).

## Updating a WordPress component

```sh
python3 scripts/lock.py list
python3 scripts/lock.py set plugin two-factor 0.17.0 https://downloads.wordpress.org/plugin/two-factor.0.17.0.zip
python3 scripts/lock.py verify       # re-download everything and compare
```

Then rebuild, run the whole test suite, and describe in the pull request what changed upstream (security fixes, behaviour changes, database upgrades).

Adding a plugin or theme to the curated catalog is a product decision: open an issue first. A new tenant-visible plugin must also be added to `Integrations::TENANT_PLUGINS`; themes are allowed automatically when they are in the lock file.

### Translation packs

Language packs on downloads.wordpress.org are regenerated when translations change, under the same URL. When the build fails with a checksum mismatch for a `language` entry, verify you are on a trusted network and refresh it: `python3 scripts/lock.py set language de_DE 7.1 https://downloads.wordpress.org/translation/core/7.1/de_DE.zip`.

## Updating an image

Find the new digest (`docker buildx imagetools inspect mariadb:11.8`), update the reference, rebuild, run the full suite including the restore drill. For MariaDB, read the upgrade notes; for PHP, check WordPress and plugin compatibility.

## Policy

* One dependency per pull request.
* Dependabot pull requests are proposals; a maintainer reads release notes before merging.
* Never disable checksum verification, use `latest` tags, or fetch code at runtime.
* The image scan fails on fixable HIGH/CRITICAL vulnerabilities. Unfixed upstream issues are visible in the SBOM/scan output and reviewed at release time.
