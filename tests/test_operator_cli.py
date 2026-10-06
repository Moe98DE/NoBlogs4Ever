import contextlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from scripts import operator_cli


class OperatorCli(unittest.TestCase):
    def test_machine_readable_status_and_exit_codes(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            code = operator_cli.report([
                operator_cli.record("PASS", "local", "verified"),
                operator_cli.record("WARN", "external", "not probed"),
            ], True)
        self.assertEqual(code, 2)
        parsed = json.loads(output.getvalue())
        self.assertEqual(parsed["overall"], "WARN")
        self.assertEqual(parsed["checks"][1]["status"], "WARN")

    def test_development_check_is_not_production_pass(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "dev.env"
            config.write_text("APP_ENV=development\nPLATFORM_DOMAIN=lvh.me\n", encoding="utf-8")
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                code = operator_cli.check(config, True)
            self.assertEqual(code, 2)
            self.assertEqual(json.loads(output.getvalue())["overall"], "WARN")

    def test_missing_config_fails_without_traceback(self):
        with tempfile.TemporaryDirectory() as directory:
            output = io.StringIO()
            with contextlib.redirect_stdout(output):
                code = operator_cli.check(Path(directory) / "missing.env", True)
            self.assertEqual(code, 1)
            self.assertEqual(json.loads(output.getvalue())["overall"], "FAIL")

    def test_setup_preserves_existing_config_and_secrets(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "scripts").mkdir()
            (root / "secrets").mkdir()
            config = root / ".env"
            config.write_text("APP_ENV=development\nPLATFORM_DOMAIN=lvh.me\n", encoding="utf-8")
            for name in ("db_password", "db_root_password", "wp_salt", "admin_password", "backup_password", "smtp_password"):
                (root / "secrets" / name).write_text(name, encoding="utf-8")
            before = {p.name: p.read_bytes() for p in (root / "secrets").iterdir()}
            with patch.object(operator_cli, "ROOT", root), patch.object(operator_cli, "read_env", return_value={"APP_ENV": "development"}), patch.object(operator_cli, "command", return_value=operator_cli.subprocess.CompletedProcess([], 0, "", "")), patch.object(operator_cli, "check", return_value=2):
                code = operator_cli.setup(config, True)
            self.assertEqual(code, 2)
            self.assertEqual(config.read_text(encoding="utf-8"), "APP_ENV=development\nPLATFORM_DOMAIN=lvh.me\n")
            self.assertEqual(before, {p.name: p.read_bytes() for p in (root / "secrets").iterdir()})

    def test_doctor_reports_emergency_mode_as_warning(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "dev.env"
            config.write_text("APP_ENV=development\n", encoding="utf-8")
            health = {"database": True, "worker_age_seconds": 10, "storage_free_bytes": 999999999,
                      "storage_writable": True, "backup_age_seconds": 30, "backup_max_age_seconds": 3600,
                      "failed_migrations": 0, "stalled_migrations": 0, "readonly": True}
            def fake_command(argv, **kwargs):
                if "ps" in argv:
                    return operator_cli.subprocess.CompletedProcess(argv, 0, "container-id\n", "")
                return operator_cli.subprocess.CompletedProcess(argv, 0, json.dumps(health), "")
            output = io.StringIO()
            with patch.object(operator_cli, "command", side_effect=fake_command), contextlib.redirect_stdout(output):
                code = operator_cli.doctor(config, True)
            self.assertEqual(code, 2)
            checks = json.loads(output.getvalue())["checks"]
            self.assertIn({"status": "WARN", "check": "emergency read-only", "detail": "True"}, checks)


if __name__ == "__main__":
    unittest.main()
