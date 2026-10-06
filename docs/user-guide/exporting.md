# Exporting and leaving

Your content is yours. You can take it with you at any time, without asking anyone.

## Standard WordPress export (WXR)

**Tools → Export** produces a WordPress eXtended RSS file with your posts, pages, comments, categories, tags, menus and the list of media. Any WordPress site can import it with *Tools → Import → WordPress*. It does **not** contain the media files themselves.

## Full site archive

**Tools → Full site archive → Create a new archive** builds a ZIP in the background (usually within a minute; you can leave the page):

| File | Contents |
|---|---|
| `publication.xml` | The standard WXR export of all content |
| `uploads/…` | Every file uploaded to this site |
| `site.json` | Theme, Additional CSS, menu locations, front page, reading/comment and date settings — no secrets |
| `manifest.json` | Size and SHA-256 checksum of every file, so you can verify the archive |
| `README.txt` | What is included and how to import it |

Archives can be downloaded by the site's administrators and are deleted automatically after 7 days. The page shows each archive's SHA-256 checksum.

To move to another NoBlogs4Ever platform, upload the ZIP unchanged in *Tools → Import publication*. To move to any WordPress host, import `publication.xml` and copy the `uploads/` folder into `wp-content/uploads/`.

## What exports never contain

Passwords or password hashes, two-factor secrets, sessions, OAuth/API tokens, the encrypted-contact private key (the platform never has it), commenters' IP addresses (never stored), plugin or theme code, and copies held by other services.

## Deleting your site or account

See [Deleting a site](sites.md#deleting-a-site). To delete your account entirely, ask the operators; they can also explain how long backups keep a copy.
