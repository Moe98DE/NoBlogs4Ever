# Development environment

## Set up

Follow the [quick start](../getting-started/quickstart.md), but start the stack with the live-code overlay:

```sh
make init
make dev          # = docker compose -f compose.yaml -f compose.dev.yaml up -d --build
make bootstrap
make seed
```

`compose.dev.yaml` bind-mounts `app/mu-plugins`, `app/nbe-media.php`, `config/wp-config.php`, `scripts/`, `tests/` and the lock file read-only into the app, worker, cli and importer containers. PHP changes apply on the next request — no image rebuild. To use it for every `docker compose` call in your shell:

```sh
export COMPOSE_FILE=compose.yaml:compose.dev.yaml
```

Rebuild the image (`make up` / `docker compose build`) when you change the `Dockerfile`, `dependencies.lock.json`, `config/` files other than `wp-config.php`, or Apache/PHP configuration.

## Useful commands

```sh
make shell                                       # bash in a tools container (runs as www-data)
docker compose run --rm cli wp --allow-root --url=http://garden.lvh.me post list
docker compose exec worker php /opt/nbe/scripts/worker.php        # one worker pass now
docker compose logs -f app worker
make test && make integration                    # see Testing
sh scripts/lint.sh --fix                         # apply the coding standard
composer install && composer analyse             # PHPStan (needs PHP 8.2+ and Composer on the host)
```

Mailpit shows every email at http://localhost:8025.

## Working without Docker

The pure-PHP parts (`Policy`, `Archive`) and their unit tests run with any PHP 8.2+ that has `zip` and `simplexml`: `php tests/unit.php`. Everything else needs WordPress and MariaDB — use the stack.

## Conventions

* PHP 8.2+ syntax, `declare(strict_types=1)`, PSR-12 via PHP-CS-Fixer (`.php-cs-fixer.dist.php`), PHPStan level 5.
* One responsibility per class in `app/mu-plugins/nbe/`, each with a static `register()` wired from `Platform::MODULES`. Keep `Policy` and `Archive` free of WordPress calls so they stay unit-testable.
* Use WordPress APIs for security-relevant work: capabilities, nonces (`check_admin_referer`), `$wpdb->prepare()`, escaping on output (`esc_html`, `esc_attr`, `esc_url`), `wp_kses_post` for HTML.
* Every user-visible string goes through `__()`/`esc_html__()`.
* Never log request data, credentials or content. Use `EventLog::record()` with its allowlisted fields.
* Admin UI uses WordPress's own markup (`wrap`, `form-table`, `widefat`, `notice`), labels every field and works on small screens.
* Shell scripts: `set -eu`, `shellcheck --severity=warning` clean.

## Environment variables for tests

| Variable | Used by |
|---|---|
| `NBE_BASE_URL` | browser tests (default `http://lvh.me:8080`) |
| `NBE_DEMO_PASSWORD_FILE` | browser tests (default `secrets/demo_password`) |
| `NBE_MIGRATED_URL` | browser tests (auto-discovered) |
| `NBE_CHROMIUM` | browser tests: use an existing Chromium binary |
| `NBE_SKIP_BUILD=yes` | `tests/production-smoke.sh`, `scripts/runtime-evidence.sh`: reuse the local image |
| `NBE_KEEP_SMOKE=yes` | keep the production smoke environment after a failure for inspection |

## Windows

Use WSL2 (recommended) or Git Bash with Docker Desktop. Clone with LF line endings (`git config --global core.autocrlf false`). After cloning on Windows, make sure scripts keep their executable bit in Git: `git update-index --chmod=+x scripts/*.sh tests/*.sh noblogs4ever`.
