# HTTP and REST surface

## Platform endpoints (`/wp-json/nbe/v1/…`)

| Method & route | Authentication | Purpose |
|---|---|---|
| `GET /live` | none | Liveness: database reachable and job storage writable. `{"ok":bool,"time":…}`, HTTP 200/503, `no-store`. |
| `GET /health` | cookie + nonce; `manage_network_options` (operators) | Full operational status (see [Monitoring](../operator-guide/monitoring.md)). |
| `GET /analytics?days=14` | cookie + nonce; `manage_options` on the current site | That site's aggregate counts. |
| `GET /discover?topic=slug` | none | Public directory index: listed sites and recent posts. |
| `POST /contact` | none (cookies are not used); `Origin` must equal the site's home URL | Accepts an encrypted contact envelope (≤ 64 KB JSON: `version`, `fingerprint`, `iv`, `key`, `ciphertext`). Rate-limited 5/h per client and 100/h per site. Returns 409 when the recipient key changed. |

## WordPress core REST API

The core API (`/wp-json/wp/v2/…`) is available with WordPress's normal permission model:

* **Unauthenticated reads** of public content on public sites (posts, pages, media, categories, tags, comments, search, settings that WordPress exposes publicly). User listing routes (`/wp/v2/users…`) are hidden from visitors who cannot edit content or list users.
* **Members-only sites** reject every REST request from non-members with HTTP 403.
* **Writes** require cookie authentication with the `X-WP-Nonce` header (as used by the block editor). **Application passwords are disabled** and there is no OAuth, so the API cannot be used with long-lived tokens. Capabilities follow the user's role on that site; operator-only capabilities are denied to everyone else.
* **CORS**: WordPress core defaults (no cross-origin credentials are granted by the platform).
* **Read-only mode**: every non-GET request returns HTTP 503.
* **Rate limits**: no general REST rate limit is applied by the platform; put one at the edge if you expose the API to heavy automated use.

## Other entry points

| Path | Behaviour |
|---|---|
| `/wp-login.php` | Sign-in (rate-limited, generic errors), lost password (uniform response), two-factor validation. Never cached. |
| `/wp-signup.php`, `/wp-activate.php` | Registration and activation according to the registration policy. |
| `/wp-admin/…`, `/wp-admin/admin-post.php`, `/wp-admin/admin-ajax.php` | WordPress admin; platform forms are nonce-protected. |
| `/wp-content/uploads/…` | Rewritten to `nbe-media.php` for authorised delivery. |
| `/xmlrpc.php` | 403 at the edge; XML-RPC is also disabled in WordPress. |
| `/wp-cron.php` | 403 at the edge and in the application; the worker runs cron. |
| `/feed/`, `/comments/feed/`, `/wp-sitemap.xml`, `/robots.txt` | WordPress defaults; disabled or `Disallow: /` for members-only sites. |
