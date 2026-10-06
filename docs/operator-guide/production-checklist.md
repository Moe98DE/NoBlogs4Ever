# Per-instance production checklist

This qualifies one installation of a tagged release. It does not certify future infrastructure behaviour. Keep the completed checklist with your operations records.

## Before deployment

- [ ] Deploy a signed supported release by the exact OCI digest in its manifest; verify source/SBOM/checksums and review unsuppressed vulnerabilities.
- [ ] Prepare a maintained Linux host with Compose 2.24.4+, measured CPU/RAM/PIDs/storage, restricted operators, firewall, time sync, security updates, and no public Docker API.
- [ ] Set base/wildcard DNS. Expose only intended 80/443; never publish MariaDB/app/worker/backup.
- [ ] Configure `.env`: production/HTTPS, immutable `NBE_APP_IMAGE`, real domain/support/admin/from addresses, resource/retention limits, absolute `STORAGE_CHECK_PATH` on the Docker-volume filesystem, and intentional registration. Open registration additionally requires `NBE_ACK_OPEN_REGISTRATION=yes`.
- [ ] Run `make init`; keep `secrets/` 0700 and files 0600; escrow DB/WP/admin/SMTP/Restic secrets separately with tested named access.
- [ ] Install readable wildcard/base `fullchain.pem` and `privkey.pem` below absolute `TLS_DIRECTORY`; automate renewal/reload and choose `TLS_MIN_VALID_DAYS`.
- [ ] Configure authenticated provider-neutral SMTP with TLS and an operator test mailbox. Keep its password only in the secret file.
- [ ] Configure a deliberately pre-initialized remote `RESTIC_REPOSITORY`; credentials belong only in 0600 `secrets/restic_backend.env`. Normally set `BACKUP_REQUIRE_REMOTE=yes`. Local plus independently tested off-host replication requires explicit `BACKUP_LOCAL_OFFHOST_ACK=yes`.
- [ ] Initialize only the intended Restic repository manually. Escrow its password independently.
- [ ] Run `make production-check`; retain and resolve every result.

## Staging qualification

- [ ] Start the exact production digest/overlays. Confirm no Mailpit; edge only on ingress; DB only on internal data; hardening/resources and all health checks.
- [ ] Bootstrap once; create named operators; enroll/test MFA/recovery; revoke a session; remove temporary bootstrap-password handling.
- [ ] Run `make smtp-test`; confirm receipt and SPF/DKIM/DMARC/provider headers without logging credentials.
- [ ] With two synthetic tenants, exercise roles/isolation, publishing/editing/scheduling/comments, block/Classic editor, media, feeds, privacy, registration, read-only, and native WXR export.
- [ ] Perform a representative migration, inventory/map authors, inspect/retry, then run `make migration-validate` with the old host and reviewed embed allowlist. Check `/files/`, pages/posts, media, internal links, feeds, canonicals, CSS/menus/documents, and blocked old-host requests.
- [ ] For large imports, mount a reviewed archive below `IMPORT_DIRECTORY`, set explicit limits/ack/site/user, pass storage preflight, preserve hard ceilings, and monitor memory/disk/time. Never enable remote media fetching.
- [ ] Keep Polylang/ActivityPub disabled unless the staging matrix in [Optional integrations](integrations.md) passes.
- [ ] Run performance qualification on realistic data and record all host/container/database/network conditions. Do not turn observations into guarantees.

## Recovery and operations

- [ ] Use read-only mode and pause worker for a consistent backup; run `make backup`, `make backup-check`, and `make restore-drill` on a snapshot with two tenants/media. Record snapshot, elapsed recovery, observed loss, checksums, login/isolation/render/media/export/worker evidence. Never target live DB/uploads.
- [ ] Schedule backup/freshness/integrity/retention, certificate checks, and `make monitoring-check`; test owned alert delivery.
- [ ] Monitor HTTPS, DB, worker age, free storage, backup age, emergency/read-only state, failed/stalled migrations, certificate and SMTP. Avoid reader tracking/high-cardinality labels.
- [ ] Rehearse incident response, secret/session rotation, edge isolation, recovery, contacts, decision authority, and evidence retention.
- [ ] Record observed RPO/RTO without guarantees.

## Opening and updates

- [ ] Review privacy/terms/support/deletion/backup/federation notices and local legal duties; use real support/security contacts.
- [ ] Open ingress/registration only after named operator approval.
- [ ] For each update, review upstream changes/checksums/digests/SBOM/vulnerabilities; restore and test in staging; take a consistent backup; deploy tested digest; update DB/smoke; then resume.
- [ ] Keep the prior compatible image/recovery point. Never assume code rollback reverses schema changes.
