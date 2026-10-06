#!/usr/bin/env python3
"""Static, deterministic repository checks.

  python3 scripts/release-check.py            hygiene + supply-chain pinning (every CI run)
  python3 scripts/release-check.py --release  also require runtime/restore evidence (tagged releases)
  python3 scripts/release-check.py --clean-only
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JUNK = {".DS_Store", "__MACOSX", "node_modules", "test-results", "playwright-report", "dist", "release", "__pycache__", ".runtime", "secrets", ".env"}
REQUIRED = ["LICENSE", "README.md", "SECURITY.md", "CONTRIBUTING.md", "CODE_OF_CONDUCT.md", "CHANGELOG.md", "docs/SUMMARY.md"]


def tracked_files() -> list[Path]:
    """Files that would be published: git-tracked when in a repository, otherwise everything not ignored."""
    try:
        out = subprocess.run(["git", "ls-files", "-z"], cwd=ROOT, capture_output=True, check=True)
        return [ROOT / name for name in out.stdout.decode().split("\0") if name]
    except (OSError, subprocess.CalledProcessError):
        ignored = {"node_modules", ".git", "secrets", ".runtime", "test-results", "playwright-report", "__pycache__", ".sbx"}
        return [p for p in ROOT.rglob("*") if p.is_file() and not ignored.intersection(p.relative_to(ROOT).parts) and p.name != ".env"]


def hygiene(failures: list[str]) -> None:
    for path in tracked_files():
        parts = path.relative_to(ROOT).parts
        if JUNK.intersection(parts) and not path.name.endswith(".example"):
            failures.append(f"generated or private file would be published: {path.relative_to(ROOT)}")
            if len(failures) >= 20:
                return


def pinning(failures: list[str]) -> None:
    for name in REQUIRED:
        if not (ROOT / name).is_file():
            failures.append(f"required file missing: {name}")
    dockerfile = (ROOT / "Dockerfile").read_text()
    compose = (ROOT / "compose.yaml").read_text()
    if not re.search(r"^FROM php:\d+\.\d+-\S+@sha256:[a-f0-9]{64}", dockerfile, re.M):
        failures.append("Dockerfile base image is not pinned by digest")
    if not re.search(r"image: mariadb:\d+\.\d+@sha256:[a-f0-9]{64}", compose):
        failures.append("MariaDB image is not pinned by digest")
    for file in [ROOT / "Dockerfile", ROOT / "compose.yaml", ROOT / "config/backup.Dockerfile"]:
        for image in re.findall(r"^\s*(?:FROM|image:)\s+([^\s#]+)", file.read_text(), re.M):
            if "@sha256:" not in image and "NBE_APP_IMAGE" not in image:
                failures.append(f"container image is mutable in {file.name}: {image}")
    for workflow in (ROOT / ".github/workflows").glob("*.y*ml"):
        for line_no, line in enumerate(workflow.read_text().splitlines(), 1):
            match = re.search(r"\buses:\s*([^#\s]+)", line)
            if match and not match.group(1).startswith("./") and not re.search(r"@[0-9a-f]{40}$", match.group(1)):
                failures.append(f"{workflow.relative_to(ROOT)}:{line_no}: action is not pinned to a commit SHA")
    for dependency in json.loads((ROOT / "dependencies.lock.json").read_text()):
        if not re.fullmatch(r"[0-9a-f]{64}", dependency.get("sha256", "")) or not dependency.get("url", "").startswith("https://"):
            failures.append(f"dependency is not HTTPS + SHA-256 locked: {dependency.get('slug', '?')}")


def evidence(failures: list[str]) -> None:
    dockerfile = (ROOT / "Dockerfile").read_text()
    compose = (ROOT / "compose.yaml").read_text()
    php = re.search(r"^FROM php:(\d+\.\d+)-", dockerfile, re.M)
    db = re.search(r"image: mariadb:(\d+\.\d+)@", compose)
    try:
        runtime = json.loads((ROOT / "docs/evidence/runtime-versions.json").read_text())
        if runtime.get("capture_status") != "full-runtime":
            failures.append("runtime evidence is not a completed capture (run scripts/runtime-evidence.sh)")
        if php and not str(runtime.get("php", "")).startswith(php.group(1) + "."):
            failures.append("runtime evidence PHP version drifts from the Dockerfile")
        if db and not str(runtime.get("database", "")).startswith(db.group(1) + "."):
            failures.append("runtime evidence MariaDB version drifts from compose.yaml")
    except (OSError, ValueError):
        failures.append("docs/evidence/runtime-versions.json is missing or invalid")
    try:
        restore = json.loads((ROOT / "docs/evidence/restore.json").read_text())
        if not all(restore.get(k) == "passed" for k in ("full_application_boot", "operator_login", "tenant_isolation")):
            failures.append("restore drill evidence is not passed")
    except (OSError, ValueError):
        failures.append("docs/evidence/restore.json is missing or invalid")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--clean-only", action="store_true")
    parser.add_argument("--release", action="store_true")
    args = parser.parse_args()
    failures: list[str] = []
    hygiene(failures)
    if not args.clean_only:
        pinning(failures)
    if args.release:
        evidence(failures)
    if failures:
        print("RELEASE CHECK FAILED", file=sys.stderr)
        for failure in failures:
            print(f" - {failure}", file=sys.stderr)
        return 1
    print("RELEASE CHECK PASSED" + (" (including release evidence)" if args.release else ""))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
