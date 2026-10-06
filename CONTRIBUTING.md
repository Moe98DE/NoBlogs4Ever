# Contributing to NoBlogs4Ever

Thank you for helping! NoBlogs4Ever is a small project with a clear scope: a managed, privacy-first publishing platform on top of WordPress Multisite. Contributions of every size are welcome — bug reports, documentation, translations, tests, importers, operator tooling.

## Ground rules

* Be kind and constructive; we follow the [Code of Conduct](CODE_OF_CONDUCT.md).
* **Security issues go to private vulnerability reporting**, never to public issues — see [SECURITY.md](SECURITY.md).
* Never attach real migration archives, personal data, secrets or production logs. Use synthetic fixtures.
* Open an issue before large changes (new modules, new curated plugins/themes, architecture changes) so we can agree on the approach.

## Getting started

1. Read the [quick start](docs/getting-started/quickstart.md), then the [development guide](docs/contributing/development.md) and [code tour](docs/contributing/code-tour.md).
2. `make init && make dev && make bootstrap && make seed`
3. Look at the [roadmap](docs/getting-started/project-status.md#roadmap--good-places-to-help) or issues labelled `good first issue`.

## Making a change

1. Fork and create a branch from `main`.
2. Keep the change focused. Follow the conventions in the development guide (WordPress APIs for security, escaping, nonces, `$wpdb->prepare()`, no request logging).
3. Add or update tests — every behaviour change needs an assertion ([Testing](docs/contributing/testing.md)). Security-relevant changes need a negative test too (the stranger cannot).
4. Run locally:

   ```sh
   make test
   sh scripts/lint.sh            # or: sh scripts/lint.sh --fix
   composer install && composer analyse
   make integration              # with the stack running
   ```

   For deployment, image or filesystem changes also run `make production-smoke`; for backup changes `make backup backup-check restore-drill`.
5. Update the documentation in `docs/` and `CHANGELOG.md` (*Unreleased*).
6. Open a pull request using the template: what changed, how it was tested, security/privacy/migration implications, and any upgrade or rollback notes.

CI must be green. Maintainers review for correctness, security, privacy and maintainability; expect questions, not just approvals.

## Dependency updates

One dependency per pull request, with a link to upstream release notes. See [Dependencies and supply chain](docs/contributing/dependencies.md). Dependabot PRs are proposals that a maintainer reviews like any other change.

## Licensing of contributions

NoBlogs4Ever is licensed under GPL-2.0-or-later. By submitting a contribution you agree that it is licensed under the same terms and that you have the right to submit it. Please sign off your commits (`git commit -s`) to certify the [Developer Certificate of Origin](https://developercertificate.org/).
