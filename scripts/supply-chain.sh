#!/usr/bin/env bash
set -euo pipefail
root=$(CDPATH='' cd -- "$(dirname "$0")/.." && pwd)
case "${1:-}" in
  sbom)
    command -v syft >/dev/null || { echo 'Install a reviewed/pinned Syft release; CI is the authoritative SBOM job.' >&2; exit 2; }
    syft packages noblogs4ever:local -o "cyclonedx-json=$root/sbom.cdx.json"
    ;;
  scan)
    command -v trivy >/dev/null || { echo 'Install a reviewed/pinned Trivy release; CI is the authoritative vulnerability job.' >&2; exit 2; }
    trivy image --scanners vuln,secret,misconfig --severity HIGH,CRITICAL --ignore-unfixed=false --exit-code 1 noblogs4ever:local
    ;;
  *) echo 'Usage: scripts/supply-chain.sh sbom|scan' >&2; exit 2 ;;
esac
