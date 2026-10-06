# Testing

The test pyramid, fastest first. CI runs all of it on every pull request; a pull request is mergeable only when every job is green — no test is silently skipped.

| Layer | Command | Needs | What it covers |
|---|---|---|---|
| Unit & security | `php tests/unit.php`, `php tests/xml-unit.php` | PHP with zip/simplexml | Slug, email, CSS, iframe and path policy; user-agent classes; archive traversal/symlink/bomb/duplicate rejection; malicious XML; multi-file WXR; limits |
| Crypto | `node tests/contact-crypto.mjs` | Node 22 | Encrypted-contact round trip, tamper and wrong-key rejection |
| Tooling | `python3 tests/test_*.py` | Python 3.10 | Secret generation, production validator, operator CLI |
| All of the above + syntax + secret scan | `make test` | | |
| Coding standard | `sh scripts/lint.sh` | PHP, curl | PSR-12 |
| Static analysis | `composer analyse` | Composer | PHPStan level 5 with WordPress stubs |
| Integration | `make integration` | running stack | 170+ assertions inside WordPress + MariaDB (below) |
| Browser | `make e2e` | stack + `make integration seed` + Node + Chromium | 17 workflows on desktop and mobile, including TOTP enrolment |
| Backup & restore | `make backup backup-check restore-drill` | stack + initialised repository | Encrypted snapshot and isolated full recovery |
| Production smoke | `make production-smoke` | Docker | Production overlay with temporary TLS and secrets: hardening, topology, health, HTTPS rendering, headers |
| Image scan | CI job `image-scan` | | CycloneDX SBOM, Trivy (fixable HIGH/CRITICAL fail) |

## Integration suite

`tests/integration.php` loads every file in `tests/integration/` in order (pass a prefix to run some: `wp eval-file /opt/nbe/tests/integration.php 30`). Each file creates its own random users and sites.

* `10-core.php` — tenant isolation, every role, operator capabilities, read-only capabilities, executable upload rejection, the first migration fixture (inventory, author mapping, batches, idempotency, media, `/files/` rewriting, comments, metadata filtering), TOTP validity and replay, WebAuthn provider, session revocation.
* `20-platform.php` — registration policies and the approval queue, read-only exceptions, site allowance and defaults, MIME policy, iframe allowlist, analytics isolation and retention, discovery visibility rules, members-only REST, user enumeration, comment IP removal, avatars, contact endpoint, event log allowlist.
* `30-presentation-migration.php` — second fixture: several authors, invite-by-email, password/scheduled/sticky posts, a 3000 px image (scaled copy + original integrity), derivative URLs, block ID remapping, synced patterns, category hierarchy, menus (incl. out-of-order parents), Additional CSS, template parts, re-run idempotency.
* `40-export-roundtrip.php` — full site archive contents, checksums, privacy, tenant separation, round-trip re-import, retention.

Fixtures live in `tests/fixtures/`. When you add a feature, extend a fixture and assert the outcome — the specification requires expected import results to be asserted, not just "no error".

## Browser suite

`tests/browser/*.spec.js` (Playwright). Run against a seeded stack:

```sh
make integration seed
npm ci && npx playwright install chromium
npm run test:e2e                  # both projects
npx playwright test --project=desktop -g "classic"
```

Traces and screenshots of failures are written to `test-results/`.

## Writing good tests here

* Prefer integration tests over mocks: the point is that WordPress, MariaDB and the platform behave correctly together.
* Assert security properties positively *and* negatively (the owner can, the stranger cannot).
* Never weaken or delete an assertion to make a test pass; never disable a security control in tests — use the APIs (e.g. `Security::readonlyAllows()`) to test decisions directly.
* Tests must not need Internet access (the importer never fetches remote media; mail is captured with `pre_wp_mail`).
