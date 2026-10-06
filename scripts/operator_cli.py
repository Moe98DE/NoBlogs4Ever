"""First-run setup and non-secret operational diagnostics."""
from __future__ import annotations

import argparse
import getpass
import json
import os
from pathlib import Path
import socket
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
VALIDATOR = ROOT / "scripts" / "production-check.py"


def read_env(path: Path) -> dict[str, str]:
    # Reuse the production validator's parser; no second configuration grammar.
    import importlib.util
    spec = importlib.util.spec_from_file_location("nbe_production_check", VALIDATOR)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.read_env(path)


def record(status: str, name: str, detail: str) -> dict[str, str]:
    return {"status": status, "check": name, "detail": detail}


def command(argv: list[str], *, timeout: int = 20, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(argv, cwd=ROOT, env=env, text=True, capture_output=True, timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        return subprocess.CompletedProcess(argv, 127, "", type(exc).__name__)


def config_check(path: Path, *, skip_compose: bool = False) -> dict[str, str]:
    argv = [sys.executable, str(VALIDATOR), "--env-file", str(path)]
    if skip_compose:
        argv.append("--skip-compose")
    result = command(argv, timeout=45)
    if result.returncode:
        details = [line.lstrip(" -") for line in result.stderr.splitlines() if line.startswith(" - ")]
        return record("FAIL", "production configuration", "; ".join(details) or "Validator failed; run scripts/production-check.py for details")
    return record("PASS", "production configuration", "File, local secrets, TLS files, storage and Compose validated")


def dns_check(domain: str) -> list[dict[str, str]]:
    if not domain:
        return [record("FAIL", "DNS", "PLATFORM_DOMAIN is missing")]
    checks = []
    for label, host in (("base DNS", domain), ("wildcard DNS", "nbe-dns-probe." + domain)):
        try:
            addresses = sorted({item[4][0] for item in socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)})
            checks.append(record("PASS", label, f"{host} resolves to {', '.join(addresses[:4])}"))
        except OSError:
            checks.append(record("FAIL", label, f"{host} did not resolve from this host"))
    return checks


def smtp_check(env: dict[str, str]) -> dict[str, str]:
    host = env.get("SMTP_HOST", "")
    try:
        port = int(env.get("SMTP_PORT", "0"))
        if not host or not 1 <= port <= 65535:
            return record("FAIL", "SMTP reachability", "SMTP host/port is missing or invalid")
        with socket.create_connection((host, port), timeout=4):
            return record("WARN", "SMTP reachability", "TCP connection succeeded; authentication and delivery remain untested (use make smtp-test)")
    except OSError:
        return record("FAIL", "SMTP reachability", "TCP connection failed")


def compose_env(path: Path, env: dict[str, str]) -> dict[str, str]:
    return {**os.environ, **env, "NBE_ENV_FILE": str(path.resolve())}


def compose_args(path: Path) -> list[str]:
    return ["docker", "compose", "--env-file", str(path.resolve()), "-f", str(ROOT / "compose.yaml"), "-f", str(ROOT / "compose.production.yaml")]


def backup_check(path: Path, env: dict[str, str]) -> dict[str, str]:
    result = command(compose_args(path) + ["--profile", "backup", "run", "--rm", "backup", "/scripts/backup-freshness.sh"], timeout=90, env=compose_env(path, env))
    return record("PASS" if result.returncode == 0 else "FAIL", "backup freshness", "Repository snapshot and age verified" if result.returncode == 0 else "Repository/freshness probe failed; inspect backup service without printing credentials")


def report(checks: list[dict[str, str]], as_json: bool) -> int:
    overall = "FAIL" if any(c["status"] == "FAIL" for c in checks) else "WARN" if any(c["status"] == "WARN" for c in checks) else "PASS"
    if as_json:
        print(json.dumps({"overall": overall, "checks": checks}, indent=2))
    else:
        for item in checks:
            print(f"{item['status']:4}  {item['check']}: {item['detail']}")
        print(f"OVERALL {overall}")
    return {"PASS": 0, "FAIL": 1, "WARN": 2}[overall]


def check(path: Path, as_json: bool, *, probe_backup: bool = False, skip_external: bool = False) -> int:
    try:
        env = read_env(path)
    except ValueError as exc:
        return report([record("FAIL", "configuration", str(exc))], as_json)
    production = env.get("APP_ENV") == "production"
    checks = [config_check(path) if production else record("WARN", "production configuration", "Development mode; production qualification does not apply")]
    if production and not skip_external:
        checks += dns_check(env.get("PLATFORM_DOMAIN", ""))
        checks.append(smtp_check(env))
        if probe_backup:
            checks.append(backup_check(path, env))
        else:
            checks.append(record("WARN", "backup repository", "Not probed; rerun with --probe-backup after repository initialization"))
        checks.append(record("WARN", "network exposure", "Host firewall and external port reachability require operator review"))
    return report(checks, as_json)


def doctor(path: Path, as_json: bool) -> int:
    try:
        env = read_env(path)
    except ValueError as exc:
        return report([record("FAIL", "configuration", str(exc))], as_json)
    production = env.get("APP_ENV") == "production"
    checks = [config_check(path) if production else record("WARN", "production configuration", "Development installation")]
    base = compose_args(path) if production else ["docker", "compose", "--env-file", str(path.resolve()), "-f", str(ROOT / "compose.yaml")]
    run_env = compose_env(path, env)
    for service in ("db", "app", "worker"):
        result = command(base + ["ps", "-q", service], env=run_env)
        checks.append(record("PASS" if result.returncode == 0 and result.stdout.strip() else "FAIL", service, "Container is present" if result.returncode == 0 and result.stdout.strip() else "Container is unavailable"))
    if all(c["status"] == "PASS" for c in checks[-3:]):
        result = command(base + ["exec", "-T", "app", "php", "/opt/nbe/scripts/health.php", "--operations"], timeout=30, env=run_env)
        try:
            health = json.loads(result.stdout)
            checks.append(record("PASS" if result.returncode == 0 else "FAIL", "application health", "Private health command passed" if result.returncode == 0 else "Private health command reported an unhealthy state"))
            for key, healthy in (
                ("database", health.get("database") is True),
                ("worker_age_seconds", isinstance(health.get("worker_age_seconds"), int) and health["worker_age_seconds"] < 180),
                ("storage_free_bytes", isinstance(health.get("storage_free_bytes"), int) and health["storage_free_bytes"] > 268435456),
                ("storage_writable", health.get("storage_writable") is True),
                ("backup_age_seconds", isinstance(health.get("backup_age_seconds"), int) and health["backup_age_seconds"] <= health.get("backup_max_age_seconds", 0)),
                ("failed_migrations", health.get("failed_migrations") == 0),
                ("stalled_migrations", health.get("stalled_migrations") == 0),
            ):
                checks.append(record("PASS" if healthy else "FAIL", key, str(health.get(key))))
            checks.append(record("WARN" if health.get("readonly") else "PASS", "emergency read-only", str(bool(health.get("readonly")))))
        except (ValueError, TypeError, AttributeError):
            checks.append(record("FAIL", "application health", "Health command failed or did not return JSON"))
        if production:
            app_id = command(base + ["ps", "-q", "app"], env=run_env).stdout.strip()
            expected = command(["docker", "image", "inspect", "--format", "{{.Id}}", env.get("NBE_APP_IMAGE", "")], env=run_env)
            actual = command(["docker", "inspect", "--format", "{{.Image}}", app_id], env=run_env) if app_id else subprocess.CompletedProcess([], 1, "", "")
            drift_ok = expected.returncode == 0 and actual.returncode == 0 and expected.stdout.strip() == actual.stdout.strip()
            checks.append(record("PASS" if drift_ok else "FAIL", "application image drift", "Running image matches configured release digest" if drift_ok else "Running image could not be matched to the configured release digest"))
            checks.append(backup_check(path, env))
    if production:
        checks.append(record("WARN", "TLS expiry", "Local certificate verified by configuration check; live edge handshake not probed"))
        checks.append(record("WARN", "SMTP delivery", "Run make smtp-test with an operator-controlled test recipient"))
    return report(checks, as_json)


def prompt(label: str, default: str = "") -> str:
    suffix = f" [{default}]" if default else ""
    answer = input(f"{label}{suffix}: ").strip()
    return answer or default


def write_private(path: Path, value: str) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as stream:
        stream.write(value)
    os.chmod(path, 0o600)


def setup(path: Path, non_interactive: bool) -> int:
    if path.exists():
        try:
            env = read_env(path)
        except ValueError as exc:
            print(f"FAIL: {exc}", file=sys.stderr)
            return 1
        print(f"Existing configuration preserved: {path}")
        missing = [name for name in ("db_password", "db_root_password", "wp_salt", "admin_password", "backup_password") if not (ROOT / "secrets" / name).is_file()]
        if missing:
            print("FAIL: Existing configuration has missing secrets; restore them from escrow before continuing.", file=sys.stderr)
            return 1
    else:
        if non_interactive:
            print("FAIL: --non-interactive requires an existing --config file; no defaults are invented.", file=sys.stderr)
            return 1
        mode = prompt("Mode (development/production)", "development")
        if mode not in ("development", "production"):
            print("FAIL: unsupported mode", file=sys.stderr)
            return 1
        domain = prompt("Platform domain", "lvh.me" if mode == "development" else "")
        admin = prompt("Operator email", "operator@example.invalid" if mode == "development" else "")
        support = prompt("Support email", admin)
        policy = prompt("Registration policy (invitation/approval/allowlist/unrestricted)", "invitation")
        if policy not in ("invitation", "approval", "allowlist", "unrestricted"):
            print("FAIL: invalid registration policy", file=sys.stderr)
            return 1
        smtp = prompt("SMTP host", "mail" if mode == "development" else "")
        smtp_user = prompt("SMTP user", "") if mode == "production" else ""
        repository = prompt("Restic repository", "/repository" if mode == "development" else "")
        upload = prompt("Maximum upload MB", "32")
        if not upload.isdecimal() or not 1 <= int(upload) <= 1024:
            print("FAIL: upload limit must be 1–1024 MB", file=sys.stderr)
            return 1
        if not domain or not admin or not support or not smtp or not repository:
            print("FAIL: required configuration value is missing", file=sys.stderr)
            return 1
        values = [f"APP_ENV={mode}", f"PLATFORM_DOMAIN={domain}", f"PLATFORM_SCHEME={'https' if mode == 'production' else 'http'}", f"NBE_ADMIN_EMAIL={admin}", f"SUPPORT_EMAIL={support}", f"REGISTRATION_POLICY={policy}", f"SMTP_HOST={smtp}", f"SMTP_USER={smtp_user}", f"SMTP_PORT={'587' if mode == 'production' else '1025'}", f"SMTP_TLS={'tls' if mode == 'production' else ''}", f"SMTP_FROM={admin}", f"RESTIC_REPOSITORY={repository}", f"MAX_UPLOAD_MB={upload}", "ENABLE_EXPERIMENTAL_INTEGRATIONS=no"]
        if mode == "production":
            values += ["NBE_APP_IMAGE=REPLACE_WITH_RELEASE_DIGEST", "BACKUP_REQUIRE_REMOTE=yes", "STORAGE_CHECK_PATH=REPLACE_WITH_ABSOLUTE_VOLUME_PATH", "TLS_DIRECTORY=REPLACE_WITH_ABSOLUTE_TLS_PATH"]
        else:
            values += ["NBE_APP_IMAGE=noblogs4ever:local", "PLATFORM_PORT=8080"]
        print("\nSetup will create a configuration file and preserve any existing secret files. It will not deploy or change DNS.")
        print(f"Configuration: {path}\nDomain: {domain}\nMode: {mode}\nRegistration: {policy}\nExpected DNS: {domain} and *.{domain} to the ingress host")
        if prompt("Create these files? (yes/no)", "no") != "yes":
            return 2
        path.parent.mkdir(parents=True, exist_ok=True)
        write_private(path, "\n".join(values) + "\n")
        env = read_env(path)
    secret_dir = ROOT / "secrets"
    if env.get("APP_ENV") == "production" and not (secret_dir / "smtp_password").is_file():
        if non_interactive:
            print("FAIL: provision secrets/smtp_password with the real SMTP credential; no random provider credential was invented.", file=sys.stderr)
            return 1
        secret_dir.mkdir(mode=0o700, exist_ok=True)
        password = getpass.getpass("SMTP provider password (not echoed): ")
        if not password:
            print("FAIL: SMTP password required", file=sys.stderr)
            return 1
        write_private(secret_dir / "smtp_password", password)
    generated = command([sys.executable, str(ROOT / "scripts" / "generate-secrets.py")])
    if generated.returncode:
        print("FAIL: unable to provision missing secret files", file=sys.stderr)
        return 1
    print("Secret files ready; existing values preserved. Configure DNS, TLS, remote backup credentials and SMTP delivery before deployment.")
    return check(path, False, skip_external=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="noblogs4ever")
    commands = parser.add_subparsers(dest="operation", required=True)
    for name in ("setup", "check", "doctor"):
        p = commands.add_parser(name)
        p.add_argument("--config", type=Path, default=ROOT / ".env", help="Configuration env file")
        if name == "setup":
            p.add_argument("--non-interactive", action="store_true")
        else:
            p.add_argument("--json", action="store_true")
        if name == "check":
            p.add_argument("--probe-backup", action="store_true")
    args = parser.parse_args(argv)
    path = args.config.resolve()
    if args.operation == "setup":
        return setup(path, args.non_interactive)
    if args.operation == "check":
        return check(path, args.json, probe_backup=args.probe_backup)
    return doctor(path, args.json)
