#!/usr/bin/env python3
"""Fail-closed qualification of one production deployment configuration."""
from __future__ import annotations

import argparse
import json
import ipaddress
import os
import re
import shlex
import shutil
import stat
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
REQUIRED_SECRETS = (
    "db_password", "db_root_password", "wp_salt", "smtp_password",
    "backup_password", "admin_password", "restic_backend.env",
)
PLACEHOLDER_HOSTS = {"localhost", "mail", "mailpit", "example.com", "example.invalid", "lvh.me"}


def read_env(path: Path) -> dict[str, str]:
    # The selected file is authoritative. Ambient variables must not silently
    # turn a development file into an apparently qualified production file.
    values: dict[str, str] = {}
    if not path.is_file():
        raise ValueError(f"environment file does not exist: {path}")
    for number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            raise ValueError(f"{path}:{number}: expected KEY=value")
        key, value = line.split("=", 1)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]*", key):
            raise ValueError(f"{path}:{number}: invalid variable name")
        values.setdefault(key, value.strip().strip("'\""))
    return values


def email_ok(value: str) -> bool:
    return bool(re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", value)) and not value.lower().endswith(("@example.invalid", "@example.com"))


def remote_repository(value: str) -> bool:
    return bool(re.match(r"^(?:s3|rest|azure|b2):", value))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", default=str(ROOT / ".env"))
    parser.add_argument("--compose-command", default="docker compose")
    parser.add_argument("--secrets-directory", default=str(ROOT / "secrets"), help=argparse.SUPPRESS)
    parser.add_argument("--skip-compose", action="store_true", help="Only for unit-testing this validator")
    args = parser.parse_args()
    errors: list[str] = []
    notes: list[str] = []
    try:
        env = read_env(Path(args.env_file).resolve())
    except ValueError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1

    def require(condition: bool, message: str) -> None:
        if not condition:
            errors.append(message)

    require(env.get("APP_ENV") == "production", "APP_ENV must be exactly production")
    require(bool(re.fullmatch(r"[^\s@]+@sha256:[a-f0-9]{64}", env.get("NBE_APP_IMAGE", ""))), "NBE_APP_IMAGE must be an immutable registry reference ending in @sha256:<digest>")
    require(env.get("PLATFORM_SCHEME") == "https", "PLATFORM_SCHEME must be https")
    domain = env.get("PLATFORM_DOMAIN", "").lower().rstrip(".")
    try:
        ipaddress.ip_address(domain)
        domain_is_ip = True
    except ValueError:
        domain_is_ip = False
    require(bool(domain and "." in domain and domain not in PLACEHOLDER_HOSTS and not domain_is_ip and not domain.endswith((".invalid", ".example", ".test"))), "PLATFORM_DOMAIN must be a real non-placeholder DNS name")
    require(email_ok(env.get("SUPPORT_EMAIL", "")), "SUPPORT_EMAIL must be a real non-placeholder address")
    require(email_ok(env.get("NBE_ADMIN_EMAIL", "")), "NBE_ADMIN_EMAIL must be a real non-placeholder address")
    require(email_ok(env.get("SMTP_FROM", "")), "SMTP_FROM must be a real non-placeholder address")
    smtp_host = env.get("SMTP_HOST", "").lower()
    require(bool(smtp_host and smtp_host not in PLACEHOLDER_HOSTS and not smtp_host.endswith((".invalid", ".example", ".test"))), "SMTP_HOST must name the production SMTP service, not Mailpit/example/localhost")
    require(bool(env.get("SMTP_USER")), "SMTP_USER must be configured for authenticated production SMTP")
    require(env.get("SMTP_TLS") in {"tls", "ssl"}, "SMTP_TLS must be tls or ssl")
    try:
        smtp_port = int(env.get("SMTP_PORT", "0"))
    except ValueError:
        smtp_port = 0
    require(1 <= smtp_port <= 65535 and smtp_port != 1025, "SMTP_PORT must be a valid non-Mailpit port")

    secret_dir = Path(args.secrets_directory).resolve()
    require(secret_dir.is_dir(), "secrets directory is missing; run make init and provision production values")
    if secret_dir.is_dir():
        mode = stat.S_IMODE(secret_dir.stat().st_mode)
        require(mode & 0o077 == 0, f"secrets directory permissions are {mode:o}; require 0700 or stricter")
    for name in REQUIRED_SECRETS:
        path = secret_dir / name
        require(path.is_file() and not path.is_symlink(), f"required regular secret file missing: secrets/{name}")
        if path.is_file():
            mode = stat.S_IMODE(path.stat().st_mode)
            require(mode & 0o077 == 0, f"secrets/{name} permissions are {mode:o}; require 0600 or stricter")
            if name != "restic_backend.env":
                require(path.stat().st_size >= 32, f"secrets/{name} is unexpectedly short")

    tls_dir = Path(env.get("TLS_DIRECTORY", ""))
    fullchain, private_key = tls_dir / "fullchain.pem", tls_dir / "privkey.pem"
    require(tls_dir.is_absolute(), "TLS_DIRECTORY must be an absolute path")
    require(os.access(fullchain, os.R_OK), "TLS fullchain.pem is not readable")
    require(os.access(private_key, os.R_OK), "TLS privkey.pem is not readable")
    if private_key.is_file():
        require(stat.S_IMODE(private_key.stat().st_mode) & 0o077 == 0, "TLS privkey.pem must not be accessible to group/other")
    if fullchain.is_file() and domain:
        try:
            days = max(7, int(env.get("TLS_MIN_VALID_DAYS", "21")))
        except ValueError:
            days = 21
            errors.append("TLS_MIN_VALID_DAYS must be an integer")
        for command, message in [
            (["openssl", "x509", "-checkend", str(days * 86400), "-noout", "-in", str(fullchain)], f"TLS certificate expires within {days} days"),
            (["openssl", "x509", "-checkhost", domain, "-noout", "-in", str(fullchain)], "TLS certificate does not cover the base domain"),
            (["openssl", "x509", "-checkhost", f"tenant.{domain}", "-noout", "-in", str(fullchain)], "TLS certificate does not cover wildcard tenant names"),
            (["openssl", "pkey", "-check", "-noout", "-in", str(private_key)], "TLS private key is invalid"),
        ]:
            try:
                result = subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
                require(result.returncode == 0, message)
            except FileNotFoundError:
                require(False, "openssl is required to verify TLS certificate and key")
                break

    policy = env.get("REGISTRATION_POLICY", "")
    require(policy in {"unrestricted", "allowlist", "invitation", "approval"}, "REGISTRATION_POLICY must be explicitly set to a supported value")
    if policy == "unrestricted":
        require(env.get("NBE_ACK_OPEN_REGISTRATION") == "yes", "unrestricted registration requires NBE_ACK_OPEN_REGISTRATION=yes")
    if policy == "allowlist":
        require(bool(env.get("REGISTRATION_ALLOWLIST")), "allowlist registration requires REGISTRATION_ALLOWLIST")

    repository = env.get("RESTIC_REPOSITORY", "")
    require(bool(repository), "RESTIC_REPOSITORY must be configured")
    backend_keys: set[str] = set()
    backend_file = secret_dir / "restic_backend.env"
    if backend_file.is_file():
        for line in backend_file.read_text(encoding="utf-8").splitlines():
            if line and not line.startswith("#") and "=" in line:
                backend_keys.add(line.split("=", 1)[0])
    required_backend_keys: set[str] = set()
    if repository.startswith("s3:"):
        required_backend_keys = {"AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY"}
    elif repository.startswith("rest:"):
        required_backend_keys = {"RESTIC_REST_USERNAME", "RESTIC_REST_PASSWORD"}
    elif repository.startswith("b2:"):
        required_backend_keys = {"B2_ACCOUNT_ID", "B2_ACCOUNT_KEY"}
    elif repository.startswith("azure:"):
        required_backend_keys = {"AZURE_ACCOUNT_NAME", "AZURE_ACCOUNT_KEY"}
    if required_backend_keys:
        require(required_backend_keys <= backend_keys or env.get("RESTIC_AUTH_EXTERNAL_ACK") == "yes", "remote Restic authentication keys are missing; external workload identity requires RESTIC_AUTH_EXTERNAL_ACK=yes")
    if repository and not remote_repository(repository):
        require(env.get("BACKUP_LOCAL_OFFHOST_ACK") == "yes", "a local Restic repository requires BACKUP_LOCAL_OFFHOST_ACK=yes confirming independent off-host replication")
        notes.append("Restic repository is local; the operator has explicitly attested off-host replication.")
    if env.get("BACKUP_REQUIRE_REMOTE", "yes") == "yes":
        require(remote_repository(repository), "BACKUP_REQUIRE_REMOTE=yes requires a supported remote Restic repository URL")

    try:
        min_free = max(1, int(env.get("MIN_FREE_GB", "5"))) * 1024**3
    except ValueError:
        min_free = 5 * 1024**3
        errors.append("MIN_FREE_GB must be an integer")
    storage_value = env.get("STORAGE_CHECK_PATH", "")
    storage_path = Path(storage_value)
    require(storage_path.is_absolute(), "STORAGE_CHECK_PATH must be an absolute writable path on the Docker volume filesystem")
    for path, label in [(storage_path, "Docker volume/storage filesystem"), (secret_dir, "secrets filesystem")]:
        if path.exists():
            require(os.access(path, os.W_OK) if path == storage_path else True, f"{label} is not writable as required")
            require(shutil.disk_usage(path).free >= min_free, f"{label} has less than MIN_FREE_GB available")
        else:
            require(False, f"{label} path does not exist")

    require(env.get("ENABLE_EXPERIMENTAL_INTEGRATIONS", "no") == "no", "experimental integrations must be disabled for the initial production qualification")
    require(env.get("SMTP_HOST") != "mail" and env.get("PLATFORM_DOMAIN") != "lvh.me", "development/demo configuration is active")

    if not args.skip_compose:
        env["NBE_ENV_FILE"] = str(Path(args.env_file).resolve())
        command = shlex.split(args.compose_command) + ["--env-file", str(Path(args.env_file).resolve()), "-f", str(ROOT / "compose.yaml"), "-f", str(ROOT / "compose.production.yaml"), "config", "--format", "json"]
        try:
            result = subprocess.run(command, cwd=ROOT, env={**os.environ, **env}, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
        except FileNotFoundError:
            errors.append("Docker Compose command is unavailable")
            result = subprocess.CompletedProcess(command, 127, "", "")
        require(result.returncode == 0, "production Compose does not validate: " + result.stderr.strip().splitlines()[-1] if result.stderr.strip() else "production Compose does not validate")
        if result.returncode == 0:
            try:
                services = json.loads(result.stdout)["services"]
                require("mail" not in services, "Mailpit is present in rendered production services")
                require(not services.get("app", {}).get("build"), "production app must use the immutable image, not a local build context")
                require(services.get("app", {}).get("image") == env.get("NBE_APP_IMAGE"), "production app image differs from the configured release digest")
                for name in ("edge", "app", "worker"):
                    service = services.get(name, {})
                    require(service.get("read_only") is True, f"{name} read-only root is not rendered")
                    require("no-new-privileges:true" in service.get("security_opt", []), f"{name} no-new-privileges is not rendered")
                for name in ("db", "app", "worker"):
                    require(not services.get(name, {}).get("ports"), f"{name} must not publish host ports")
                require(set(services.get("edge", {}).get("networks", {})) == {"ingress"}, "edge must join only the ingress network")
                require(set(services.get("db", {}).get("networks", {})) == {"data"}, "database must join only the internal data network")
            except (ValueError, KeyError, TypeError):
                errors.append("production Compose JSON is invalid")

    if errors:
        print("PRODUCTION QUALIFICATION FAILED", file=sys.stderr)
        for error in errors:
            print(f" - {error}", file=sys.stderr)
        print("See docs/operator-guide/production-checklist.md for the full per-instance checklist.", file=sys.stderr)
        return 1
    print("PRODUCTION CONFIGURATION QUALIFIED")
    for note in notes:
        print(f"NOTE: {note}")
    print("This validates configuration and local prerequisites; it does not certify DNS, delivery, off-host recovery, capacity, or provider availability.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
