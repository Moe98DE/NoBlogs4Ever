#!/usr/bin/env python3
"""Create missing local secret files without ever rotating existing values."""
import os
import pathlib
import secrets

root = pathlib.Path(__file__).resolve().parents[1] / 'secrets'
root.mkdir(mode=0o700, exist_ok=True)
if os.name == 'posix':
    root.chmod(0o700)


def create_missing(name: str, value: str) -> None:
    path = root / name
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise RuntimeError(f'secrets/{name} is not a regular file')
    if path.exists():
        return
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as stream:
        stream.write(value)


for name in ('db_password', 'db_root_password', 'wp_salt', 'smtp_password', 'backup_password', 'admin_password', 'demo_password'):
    create_missing(name, secrets.token_urlsafe(48))
create_missing('restic_backend.env', '# Remote Restic credentials: KEY=value, one per line.\n')
print('Secret files ready; existing values preserved.')
