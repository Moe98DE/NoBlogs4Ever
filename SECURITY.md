# Security policy

## Supported versions

| Version | Supported |
|---|---|
| Latest release (`v1.x`) | ✅ security fixes |
| `main` | ✅ fixed before the next release |
| Older releases | ❌ upgrade to the latest release |

## Reporting a vulnerability

**Please do not open a public issue.** Use GitHub's private vulnerability reporting: [Security → Report a vulnerability](https://github.com/Moe98DE/noblogs4ever/security/advisories/new).

Include:

* the affected version, commit or image digest;
* what an attacker can achieve (e.g. cross-tenant read, privilege escalation, code execution, information disclosure);
* step-by-step reproduction, using synthetic data only;
* whether credentials or real users' data may already be exposed.

We aim to acknowledge reports within 7 days, agree a disclosure timeline with you (normally up to 90 days), and credit you in the advisory if you wish. There is no bug bounty.

## Scope

In scope: the code in this repository — the platform plugin, configuration, container setup, scripts and the way they combine with the curated WordPress components.

Out of scope here (report upstream instead): vulnerabilities in WordPress core, the curated plugins and themes, PHP, MariaDB, Caddy or Restic themselves — unless our configuration makes them exploitable.

## If a secret was exposed

Rotate it first ([incident response](docs/operator-guide/incident-response.md)); removing it from Git history is not enough. Read the [security model](docs/architecture/security-model.md) and [threat model](docs/architecture/threat-model.md) for the platform's guarantees and limits.
