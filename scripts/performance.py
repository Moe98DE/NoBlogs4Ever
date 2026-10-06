#!/usr/bin/env python3
"""Modest reproducible concurrency probe; deliberately not a capacity or SLA claim."""
from __future__ import annotations

import concurrent.futures
import http.cookiejar
import json
import os
import platform
import statistics
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def percentile(samples: list[float], percentile_value: float) -> float:
    return sorted(samples)[max(0, min(len(samples) - 1, round((len(samples) - 1) * percentile_value)))]


def scenario(name: str, opener: urllib.request.OpenerDirector, url: str, requests: int, concurrency: int) -> dict:
    def one(_: int) -> float:
        start = time.perf_counter()
        request = urllib.request.Request(url, headers={"User-Agent": "NoBlogs4Ever qualification/1.0"})
        with opener.open(request, timeout=20) as response:
            if response.status != 200:
                raise RuntimeError(f"{name} returned HTTP {response.status}")
            response.read()
        return (time.perf_counter() - start) * 1000

    started = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
        samples = list(pool.map(one, range(requests)))
    elapsed = time.perf_counter() - started
    return {
        "requests": requests,
        "concurrency": concurrency,
        "elapsed_seconds": round(elapsed, 3),
        "observed_requests_per_second": round(requests / elapsed, 2),
        "p50_ms": round(statistics.median(samples), 2),
        "p95_ms": round(percentile(samples, 0.95), 2),
        "max_ms": round(max(samples), 2),
    }


def main() -> int:
    base = os.environ.get("NBE_PERF_BASE_URL", "").rstrip("/")
    media = os.environ.get("NBE_PERF_MEDIA_URL", "")
    admin = os.environ.get("NBE_PERF_ADMIN_URL", base + "/wp-admin/")
    username = os.environ.get("NBE_PERF_USERNAME", "")
    password_file = Path(os.environ.get("NBE_PERF_PASSWORD_FILE", ""))
    if not base or not media or not username or not password_file.is_file():
        print("Set NBE_PERF_BASE_URL, NBE_PERF_MEDIA_URL, NBE_PERF_USERNAME, and NBE_PERF_PASSWORD_FILE.", file=sys.stderr)
        return 2
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    opener.open(base + "/wp-login.php", timeout=20).read()
    body = urllib.parse.urlencode({"log": username, "pwd": password_file.read_text().strip(), "testcookie": "1", "redirect_to": admin, "wp-submit": "Log In"}).encode()
    with opener.open(urllib.request.Request(base + "/wp-login.php", data=body), timeout=20) as response:
        response.read()
    if not any("wordpress_logged_in" in cookie.name for cookie in jar):
        raise RuntimeError("Authenticated performance setup failed; credentials were not logged.")
    archive = subprocess.run(["php", str(ROOT / "tests/large-archive-performance.php")], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    evidence = {
        "captured_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "environment": {
            "host": platform.platform(),
            "python": platform.python_version(),
            "cpu_count": os.cpu_count(),
            "compose_project": os.environ.get("COMPOSE_PROJECT_NAME", "operator-supplied"),
            "note": "Record host CPU/RAM, container limits, image IDs, database size, and network placement with this artifact.",
        },
        "scenarios": {
            "concurrent_public_reads": scenario("public", opener, base + "/", 60, 8),
            "authenticated_admin_reads": scenario("admin", opener, admin, 20, 2),
            "media_reads": scenario("media", opener, media, 40, 8),
            "large_archive_parser": json.loads(archive.stdout),
        },
        "scope": "modest qualification workload; observed results are environment-specific and are not throughput, uptime, capacity, or SLA guarantees",
    }
    print(json.dumps(evidence, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
