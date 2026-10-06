import pathlib
import os
import subprocess
import tempfile
import unittest
import sys
import shutil
import importlib.util
from unittest.mock import patch
import json


class ProductionCheck(unittest.TestCase):
    def test_production_overlay_uses_image_without_build_context(self):
        if not shutil.which("docker"):
            self.skipTest("Docker Compose CLI unavailable")
        root = pathlib.Path(__file__).resolve().parents[1]
        with tempfile.TemporaryDirectory() as temporary:
            env = {**os.environ, "TLS_DIRECTORY": temporary}
            result = subprocess.run(
                ["docker", "compose", "-f", str(root / "compose.yaml"), "-f", str(root / "compose.production.yaml"), "config", "--format", "json"],
                cwd=root, env=env, text=True, capture_output=True, check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            services = json.loads(result.stdout)["services"]
            self.assertNotIn("build", services["app"])
            self.assertNotIn("mail", services)
            self.assertFalse(services["db"].get("ports"))

    def test_selected_file_overrides_ambient_values(self):
        root = pathlib.Path(__file__).resolve().parents[1]
        spec = importlib.util.spec_from_file_location("production_check", root / "scripts/production-check.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temporary:
            path = pathlib.Path(temporary) / "config.env"
            path.write_text("APP_ENV=development\n", encoding="utf-8")
            with patch.dict(os.environ, {"APP_ENV": "production"}):
                self.assertEqual(module.read_env(path)["APP_ENV"], "development")

    def test_example_configuration_is_rejected(self):
        root = pathlib.Path(__file__).resolve().parents[1]
        result = subprocess.run(
            [sys.executable, str(root / "scripts/production-check.py"), "--env-file", str(root / ".env.example"), "--skip-compose"],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("APP_ENV must be exactly production", result.stderr)
        self.assertIn("development/demo configuration is active", result.stderr)

    def test_complete_synthetic_configuration_is_accepted(self):
        if os.name != "posix":
            self.skipTest("POSIX 0600/0700 permission enforcement requires a Linux operator host")
        if not shutil.which("openssl"):
            self.skipTest("OpenSSL executable required for certificate fixture")
        root = pathlib.Path(__file__).resolve().parents[1]
        with tempfile.TemporaryDirectory() as temporary:
            fixture = pathlib.Path(temporary)
            secrets = fixture / "secrets"
            tls = fixture / "tls"
            storage = fixture / "storage"
            secrets.mkdir(mode=0o700)
            tls.mkdir()
            storage.mkdir()
            for name in (
                "db_password", "db_root_password", "wp_salt", "smtp_password",
                "backup_password", "admin_password",
            ):
                path = secrets / name
                path.write_text("x" * 40, encoding="utf-8")
                path.chmod(0o600)
            backend = secrets / "restic_backend.env"
            backend.write_text("AWS_ACCESS_KEY_ID=test\nAWS_SECRET_ACCESS_KEY=test-secret\n", encoding="utf-8")
            backend.chmod(0o600)

            subprocess.run(
                [
                    "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                    "-days", "30", "-subj", "/CN=blogs.example.org",
                    "-addext", "subjectAltName=DNS:blogs.example.org,DNS:*.blogs.example.org",
                    "-keyout", str(tls / "privkey.pem"), "-out", str(tls / "fullchain.pem"),
                ],
                check=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            (tls / "privkey.pem").chmod(0o600)
            env_file = fixture / "production.env"
            env_file.write_text(
                "\n".join(
                    [
                        "APP_ENV=production",
                        f"NBE_APP_IMAGE=registry.example.org/nbe@sha256:{'a' * 64}",
                        "PLATFORM_SCHEME=https",
                        "PLATFORM_DOMAIN=blogs.example.org",
                        "SUPPORT_EMAIL=support@blogs.example.org",
                        "NBE_ADMIN_EMAIL=admin@blogs.example.org",
                        "SMTP_FROM=noreply@blogs.example.org",
                        "SMTP_HOST=smtp.example.org",
                        "SMTP_USER=nbe-smtp",
                        "SMTP_TLS=tls",
                        "SMTP_PORT=587",
                        f"TLS_DIRECTORY={tls}",
                        "TLS_MIN_VALID_DAYS=7",
                        "REGISTRATION_POLICY=approval",
                        "RESTIC_REPOSITORY=s3:https://s3.example.org/nbe",
                        "BACKUP_REQUIRE_REMOTE=yes",
                        "RESTIC_AUTH_EXTERNAL_ACK=no",
                        "MIN_FREE_GB=1",
                        f"STORAGE_CHECK_PATH={storage}",
                        "ENABLE_EXPERIMENTAL_INTEGRATIONS=no",
                    ]
                ) + "\n",
                encoding="utf-8",
            )
            result = subprocess.run(
                [
                    sys.executable, str(root / "scripts/production-check.py"),
                    "--env-file", str(env_file),
                    "--secrets-directory", str(secrets),
                    "--skip-compose",
                ],
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                check=False,
                env={"PATH": os.environ.get("PATH", "")},
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("PRODUCTION CONFIGURATION QUALIFIED", result.stdout)


if __name__ == "__main__":
    unittest.main()
