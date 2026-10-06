# Operator commands

Run `./noblogs4ever` on a Linux operator host with Python 3.10+; on Windows, use `python noblogs4ever` for local inspection only. Production deployment remains Linux/Compose based.

## Setup

`./noblogs4ever setup --config .env` creates a new config interactively and creates missing cryptographically random local secrets. It displays its file/domain/policy summary before writing. It does not deploy, configure DNS, provision provider credentials or initialize a backup repository. The wizard prints the expected base and wildcard DNS names. Existing config and secret values are preserved. If required secrets are missing for an existing configuration, restore them from escrow rather than rerunning a generator. Production SMTP uses a provider-issued password entered without echo; an arbitrary generated string is not assumed to authenticate.

For configuration management, first provision an env file and provider secrets out of band, then run `./noblogs4ever setup --non-interactive --config production.env`. The command does not invent missing configuration. Fill in the production image digest, absolute TLS/storage paths, certificate, SMTP and remote Restic credentials before using `check` to qualify deployment. Treat the env file as private even though ordinary passwords belong in `secrets/`. On a POSIX host, setup creates it with mode 0600 and keeps generated secrets at mode 0600 under a 0700 directory. Review the production checklist before starting Compose.

## Check

`./noblogs4ever check --config production.env [--json] [--probe-backup]` reuses `scripts/production-check.py` for configuration, local secret/TLS/storage and rendered Compose validation, then probes base/wildcard DNS and TCP SMTP reachability. `--probe-backup` runs the real Restic freshness/repository check in the backup service; omit it before repository initialization. TCP reachability cannot establish authentication or delivery, so it remains WARN and requires `make smtp-test`. Host firewall/external port exposure also remains WARN pending operator review. Exit 0 means every item is PASS, 1 means at least one FAIL, and 2 means no FAIL but at least one unverified WARN. JSON uses the same status semantics. `make production-check` remains the strict local configuration gate and uses the same validator.

## Doctor

`./noblogs4ever doctor --config production.env [--json]` validates the configuration, checks whether DB/app/worker containers exist, then runs the private application health command. It reports DB, worker heartbeat, storage, backup age, failed/stalled migrations and emergency read-only state without printing credentials. In production it also checks the Restic repository. A missing container or unhealthy result is FAIL. Live TLS handshake and SMTP delivery require separate probes. This is read-only and safe to run against a production installation.

`setup`, `check` and `doctor` do not replace the release gate or the per-instance staging and recovery drills. Optional browser onboarding was deferred: putting infrastructure secrets in a first-run web flow would increase attack surface for little benefit.
