# Writing and publishing

NoBlogs4Ever is WordPress, so everything in the [WordPress user documentation](https://wordpress.org/documentation/) applies. This page covers what is specific to the platform.

## Editors

The **block editor** is the default. If you prefer the **classic editor**:

* administrators can change the default for the whole site under **Settings → Writing**;
* each person can choose their own default in their profile and switch per post from the post list ("Edit (classic editor)").

## Posts and pages

Drafts, pending review (for contributors), scheduling, private posts, password protection, sticky posts, categories, tags, excerpts, featured images, revisions (the last 30 per post) and RSS/Atom feeds all work as in WordPress. Scheduled posts are published by the platform's background worker within about 30 seconds of their time.

## Media

Upload images, documents and audio/video under **Media**. The platform checks that each file's content matches its extension, and refuses executable or script-capable files (PHP, HTML, SVG…).

Default allowed types: JPEG, PNG, GIF, WebP, PDF, plain text, MP3, MP4, OpenDocument text (`.odt`) and spreadsheets (`.ods`). Operators can change the list, the maximum file size (32 MB by default) and each site's storage quota (1 GB by default).

Very large photos are stored with a web-friendly "scaled" copy (2560 px on the long edge by default); the original is kept and exported.

Media attached to drafts or private posts — and all media of members-only sites — is only delivered to people allowed to read that content.

## Embeds

Paste a link to YouTube, Vimeo, SoundCloud, Mastodon and many other services on its own line (or use an Embed block) and WordPress shows it inline.

Raw `<iframe>` and `<script>` code is removed when you save, because it could be used to attack readers. Operators can allow iframes from specific, trusted HTTPS hosts (for example a map provider); those iframes are sandboxed and send no referrer.

Remember that embedded content is hosted elsewhere: it can disappear, it is not included in backups or exports, and the other service can see that a reader viewed it.

## Comments

Comments can be turned on or off per site (**Settings → Discussion**) and per post. New comments from unknown people wait for moderation by default, and comments containing two or more links are held. The platform never stores commenters' IP addresses or browser details, and limits how quickly one connection can post.

## Reader privacy

Pages published on the platform load no tracking scripts, no external fonts and no third-party avatars or emoji images. If you want readers to use Gravatar avatars, ask the operators — it is a network-wide choice because it makes readers' browsers contact a third party.
