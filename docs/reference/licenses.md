# Licenses

## NoBlogs4Ever

The original code in this repository (the platform plugin, scripts, configuration, tests and documentation) is licensed under the **GNU General Public License, version 2 or (at your option) any later version** (`GPL-2.0-or-later`), the same license as WordPress. See [`LICENSE`](https://github.com/Moe98DE/noblogs4ever/blob/main/LICENSE).

Copyright © 2026 Moe98DE and NoBlogs4Ever contributors. Contributions are accepted under the same license (inbound = outbound); see `CONTRIBUTING.md`.

## Bundled and used components

| Component | License | How it is used |
|---|---|---|
| WordPress 7.1 | GPL-2.0-or-later | Downloaded at build time, in the image |
| Two Factor, Two Factor WebAuthn provider, Classic Editor, Polylang, ActivityPub | GPL-2.0-or-later (per their headers) | In the image |
| Twenty Twenty-Five, Twenty Twenty-One | GPL-2.0-or-later | In the image |
| WordPress core translations | GPL-2.0-or-later | In the image |
| WP-CLI | MIT | In the image |
| PHP | PHP License 3.01 | Base image |
| Apache httpd | Apache-2.0 | Base image |
| Debian packages | various (see SBOM) | Base image |
| MariaDB | GPL-2.0 | Separate container |
| Caddy | Apache-2.0 | Separate container |
| Restic | BSD-2-Clause | Separate container |
| Mailpit | MIT | Development container |
| Playwright, PHPStan, PHP-CS-Fixer | Apache-2.0 / MIT / MIT | Development tools, not distributed |

Each upstream archive keeps its own license notices. The SBOM attached to every release (CycloneDX) lists exact package versions and declared licenses; review it rather than relying on this summary, which is not legal advice.
