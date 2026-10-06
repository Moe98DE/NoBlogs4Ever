# Quick start

This brings up a complete local platform — edge proxy, WordPress Multisite, background worker, database and a mail catcher — in a few minutes.

## Requirements

* Docker Engine with **Compose v2.24.4 or newer** (Docker Desktop on macOS/Windows works)
* Python 3.10+ and `make` (on Windows use WSL2 or Git Bash)
* About 4 GB of free RAM and 5 GB of disk
* Node 22 only if you want to run the browser tests

No `/etc/hosts` editing is needed: `lvh.me` and every `*.lvh.me` name are public DNS records pointing at `127.0.0.1`.

## Start the stack

```sh
git clone https://github.com/Moe98DE/noblogs4ever.git
cd noblogs4ever
make init        # creates .env from .env.example and random secrets in secrets/
make up          # builds the image (downloads and verifies WordPress, plugins, themes) and starts everything
make bootstrap   # installs the Multisite network and applies the platform configuration
make seed        # optional demo content
```

`make bootstrap` is idempotent: run it again after pulling changes or rebuilding.

## Look around

| URL | What it is |
|---|---|
| http://lvh.me:8080 | Main site. The **Discover** page lists sites that opted into the directory. |
| http://lvh.me:8080/wp-admin/network/ | Network admin. Sign in as `operator`; the password is in `secrets/admin_password`. |
| http://garden.lvh.me:8080 | Demo site owned by `alice`, with an editor, author, contributor and subscriber. |
| http://journal.lvh.me:8080 | Demo site owned by `bob` (a different tenant). |
| http://localhost:8025 | Mailpit: every email the platform sends (activation, password reset, import notices…). |

Demo users share the password in `secrets/demo_password`.

Things to try:

* As `alice`, open **Your platform** in the dashboard and create a new site.
* Open **Tools → Import publication** and upload `tests/fixtures/publication.xml`. Run a worker pass with `docker compose exec worker php /opt/nbe/scripts/worker.php` (or wait 30 seconds), map the author, and read the migration report.
* Turn on **Count page views** under Your platform → Site privacy, visit the site in a private window, then open **Dashboard → Site analytics**.
* As `operator`, open **Network Admin → Platform policy**, switch registration to *Approval*, register a new account at `/wp-signup.php` in a private window, and approve it.

## Everyday commands

```sh
make help          # list everything
make dev           # like `make up`, but the PHP code is live-mounted (no rebuild after edits)
make logs          # follow logs
make shell         # shell in a tools container; `wp --allow-root …` works there
make test          # fast tests, no Docker needed
make integration   # integration tests inside WordPress (stack must be running)
make down          # stop (data is kept in Docker volumes)
docker compose down -v   # stop and DELETE all local data
```

## Troubleshooting

* **Port 8080 or 8025 already in use** — change `PLATFORM_PORT` in `.env` (and re-run `make bootstrap`), or stop the other service.
* **`make up` fails with a checksum mismatch** — an upstream file changed under the same URL (this happens to translation packs). See [Dependencies](../contributing/dependencies.md).
* **"Error establishing a database connection" right after `make up`** — MariaDB is still initialising on first start; wait a few seconds and retry `make bootstrap`.
* **You are asked to sign in again on another site** — sessions are deliberately per site address (host-only cookies). See [Concepts](concepts.md#accounts-and-sessions).
* **Windows line endings** — the repository enforces LF (`.gitattributes`). If scripts fail with `\r` errors, re-clone with `git config --global core.autocrlf false`.
