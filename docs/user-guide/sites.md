# Creating and managing sites

## Create a site

Open **Your platform** in the dashboard and fill in **Create a site**:

* **Address** — becomes `https://<address>.<platform domain>`. 4–63 lowercase letters, numbers or hyphens, starting with a letter. Names that could be mistaken for infrastructure or staff (for example `www`, `mail`, `admin`, `support`, `security`, `login`) are reserved, as are internationalised (`xn--`) labels.
* **Title** and optional **tagline**.
* **Language** of the dashboard and default texts, from the languages installed on the platform.

You become the site's administrator. The number of sites one person can administer is limited by the platform (3 by default).

New sites start with privacy-friendly defaults: not listed in the directory, no page counting, comments held for moderation, no external avatar service, pretty permalinks (`/2026/10/06/my-post/`).

## Site privacy settings

Under **Your platform → Site privacy** an administrator can choose:

| Setting | Effect |
|---|---|
| **List this site in the network directory** | Site name, tagline and the latest public posts appear on the platform's Discover page. Drafts, private and password-protected posts never do. |
| **Count page views** | Enables [cookie-free aggregate analytics](analytics.md). |
| **Members only** | Only signed-in members of the site can read it — pages, feeds, the REST API and uploaded media. Search engines are asked not to index it, and it is never listed or counted. |

WordPress's own **Settings → Reading → Search engine visibility** remains available for public sites you would rather keep out of search results.

## Appearance

Choose one of the curated themes under **Appearance → Themes**. Block themes are customised in **Appearance → Editor** (Site Editor: templates, patterns, styles, navigation); classic themes in **Appearance → Customize**, **Menus** and **Widgets**. **Additional CSS** is available in both. You cannot upload themes or plugins — the operators maintain the catalog for everyone's security.

## Plugins

**Plugins** shows the plugins the operators allow site administrators to switch on or off for their site (for example Classic Editor settings, or experimental integrations if enabled). Installing other plugins is not possible.

## Deleting a site

**Tools → Delete Site** sends a confirmation link to the administrator's email; the site is deleted only after you open it. Before deleting:

1. **Export first** — a [full site archive](exporting.md) contains everything you need to move elsewhere.
2. Understand what deletion means:
   * the site disappears from the platform immediately;
   * encrypted platform backups keep a copy until they expire (14 days by default, see your operator's policy);
   * if the site federated posts (ActivityPub), other servers may keep their copies — deletion requests are sent but cannot be enforced.
