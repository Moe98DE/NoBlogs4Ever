#!/usr/bin/env python3
"""Maintain dependencies.lock.json.

  python3 scripts/lock.py list                     show locked dependencies
  python3 scripts/lock.py verify                   download everything and check SHA-256
  python3 scripts/lock.py set KIND SLUG VERSION URL
                                                   add or update one entry (computes SHA-256)

Review upstream release notes and security advisories before changing a
version. One dependency per pull request, please; see
docs/contributing/dependencies.md.
"""
from __future__ import annotations

import hashlib
import json
import sys
import urllib.request
from pathlib import Path

LOCK = Path(__file__).resolve().parents[1] / "dependencies.lock.json"
KINDS = ("core", "plugin", "theme", "language")


def load() -> list[dict]:
    return json.loads(LOCK.read_text(encoding="utf-8"))


def save(entries: list[dict]) -> None:
    order = {kind: i for i, kind in enumerate(KINDS)}
    entries.sort(key=lambda e: (order.get(e["kind"], 9), e["slug"]))
    LOCK.write_text(json.dumps(entries, indent=2) + "\n", encoding="utf-8")


def digest(url: str) -> str:
    if not url.startswith("https://"):
        raise SystemExit(f"refusing non-HTTPS URL: {url}")
    sha = hashlib.sha256()
    with urllib.request.urlopen(url, timeout=120) as response:  # noqa: S310 - HTTPS enforced above
        for chunk in iter(lambda: response.read(1 << 20), b""):
            sha.update(chunk)
    return sha.hexdigest()


def main(argv: list[str]) -> int:
    if not argv or argv[0] in ("-h", "--help"):
        print(__doc__)
        return 0
    command, args = argv[0], argv[1:]
    entries = load()
    if command == "list":
        for e in entries:
            print(f"{e['kind']:9} {e['slug']:32} {e['version']}")
        return 0
    if command == "verify":
        failures = 0
        for e in entries:
            actual = digest(e["url"])
            ok = actual == e["sha256"]
            failures += not ok
            print(f"{'OK  ' if ok else 'FAIL'} {e['kind']} {e['slug']} {e['version']}" + ("" if ok else f" (got {actual})"))
        return 1 if failures else 0
    if command == "set" and len(args) == 4:
        kind, slug, version, url = args
        if kind not in KINDS:
            raise SystemExit(f"kind must be one of {', '.join(KINDS)}")
        sha = digest(url)
        entries = [e for e in entries if not (e["kind"] == kind and e["slug"] == slug)]
        entries.append({"kind": kind, "slug": slug, "version": version, "url": url, "sha256": sha})
        save(entries)
        print(f"{kind} {slug} {version} {sha}")
        return 0
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
