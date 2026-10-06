# Releasing

Releases are tags `vMAJOR.MINOR.PATCH` (pre-releases: `v1.1.0-rc.1`). Version numbers follow semantic versioning from 1.0.0 on.

## Checklist

1. `main` is green in CI (static, full stack, image scan).
2. Update `CHANGELOG.md` (move *Unreleased* to the new version) and the version in `app/mu-plugins/nbe.php` and `NBE\Platform::VERSION`.
3. Optionally refresh committed evidence on a clean Linux host: `bash scripts/runtime-evidence.sh` and `make restore-drill` update `docs/evidence/`; `make release-check-strict` must pass.
4. Tag and push: `git tag -s v1.0.0 -m "NoBlogs4Ever 1.0.0" && git push origin v1.0.0`. Use `-a` instead of `-s` if you have no signing key set up.

The **Release** workflow then re-runs the static gates, builds and pushes `ghcr.io/moe98de/noblogs4ever:<tag>` (GHCR requires lowercase, so the workflow lowercases the repository name) with provenance and SBOM attestations, scans it, and publishes a GitHub release (marked as a pre-release when the tag contains `-`) with `release-manifest.json` (image digest, dependency lock hash and versions), a reproducible source tarball, a standalone SBOM and `SHA256SUMS`.

Operators deploy by digest from the manifest, never by tag.

## Repository settings (maintainers)

* Protect `main`: require the CI checks and one review.
* Enable private vulnerability reporting (Settings → Security).
* Allow GitHub Actions to create releases and write packages; make the GHCR package public.
* Connect GitBook (Git Sync) to the `docs/` folder via `.gitbook.yaml`.
