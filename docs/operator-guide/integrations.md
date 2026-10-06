# Optional integrations

Polylang 3.8.9 (multilingual content) and ActivityPub 9.3.1 (federation with Mastodon and the fediverse) are included in the image, checksum-locked, but **experimental and hidden** from site administrators unless `ENABLE_EXPERIMENTAL_INTEGRATIONS=yes`. `make production-check` refuses that setting until you have qualified the integrations for your installation.

When enabled, site administrators see them in **Plugins** and can activate them for their own site; the platform keeps federation off for members-only sites and during read-only mode, and the Plugins screen explains federation's limits.

## Before enabling in production — staging matrix

Test with your curated themes and real-world content:

* **Polylang:** language switcher, translated posts/pages/categories/menus, feeds per language, export and re-import (translations links are plugin metadata and are *not* carried by WXR), behaviour in the directory and analytics.
* **ActivityPub:** follow from a Mastodon account, publish, edit and delete; reply and like handling and moderation; what happens to followers when the site address changes; members-only sites never federating; read-only mode; backup/restore of keys.

Things to tell your users:

* Federated posts are copied to followers' servers. Deleting locally sends delete requests, but **remote copies cannot be guaranteed to disappear**.
* A site's federated identity is tied to its address; moving to another domain is not seamless.
* Migration does not carry federation identity or followers.

## Not implemented

* Mastodon/social **auto-posting** with OAuth (spec §20) — see the roadmap.
* Fetching media from an old host during migration.
