# Importing an existing site

Bringing writing back online is what NoBlogs4Ever was built for. You can import a backup from NoBlogs, WordPress.com, any self-hosted WordPress or WordPress Multisite, or another NoBlogs4Ever instance. Import into a new, empty site where possible.

Coming from NoBlogs? Start with [Coming from NoBlogs](coming-from-noblogs.md), which explains what your backup probably contains and how to package it.

## 1. Export from the old site

* **WordPress / WordPress.com:** *Tools → Export → All content* produces a WXR `.xml` file. Large WordPress.com sites may give you several XML files — that is fine.
* **Media:** WXR files only *reference* media. To bring the files, add your uploads folder to a ZIP next to the XML: `wp-content/uploads/…` for a normal site, `wp-content/blogs.dir/<id>/files/…` or `wp-content/uploads/sites/<id>/…` for older Multisite networks. Folder names inside the ZIP do not need to match exactly; files are matched by their path or, failing that, a unique file name.
* **From NoBlogs4Ever:** download a [full site archive](exporting.md) and upload it unchanged; theme, Additional CSS, menu locations and front page settings come along too.

## 2. Upload

Open **Tools → Import publication**, choose the `.xml` or `.zip`, and select **Upload and take inventory**. The browser limit is 256 MB; operators can import bigger archives for you.

The platform first keeps an untouched copy of your file and checks it carefully (nothing inside is ever executed). Then, in the background, it takes an **inventory**: posts, pages, attachments, comments, authors, categories and tags, custom post types, custom fields, shortcodes, blocks, media files and links that still point to the old site. Refresh the page after a few seconds to see it.

## 3. Map the authors

For every author found in the archive choose who should own their posts here:

* any member of this site (several old authors can map to the same person), or
* **Invite by email…** — a new account is created and added to your site as an Author; the person gets an email to set their own password.

Old passwords and roles are never imported. When you save, the import starts.

## 4. Watch it run

Content is imported in small batches by the background worker; progress is shown on the page and you receive an email when it finishes. You can close the browser. If anything fails, **Run again** is always safe: items that already exist are skipped, never duplicated.

## 5. Read the report

Each import has a report (also downloadable as JSON). It tells you:

* what was found and what was imported (posts and pages, media, comments, categories and tags, menus);
* **media files missing from the archive** — add them to a ZIP and import again; only the missing pieces are added;
* **content still linking to the old site** — links the importer could not safely rewrite because no local copy exists;
* **content types, taxonomies, blocks and shortcodes** this platform cannot display. Raw shortcodes such as `[contact-form]` would otherwise appear as text in your posts;
* **presentation**: whether menus, Additional CSS, theme settings and Site Editor templates were restored, and what you need to redo by hand;
* things you must **reconnect manually**: social accounts, API keys, application passwords, federation identity and the encrypted-contact key are never part of an export.

## What is preserved

Titles, content, excerpts, slugs, publication and modification dates, status (published, draft, pending, private, scheduled), password protection, sticky posts, authors (as mapped), categories (with hierarchy), tags, comments (with threading and approval state, without IP addresses), featured images, image alt text, galleries, synced patterns, attachment relationships, page hierarchy, menus, Additional CSS and Site Editor templates for themes available here, plus custom fields whose name starts with `public_`.

Links are rewritten to the new site only when the target exists here — including image sizes (`photo-300x200.jpg`), legacy `/files/…` paths and links between your own posts. Each rewrite is listed in the report.

## What is not preserved

Plugin data and settings, widgets, theme options of themes not available here, user passwords and two-factor settings, private custom fields, and anything hosted on other services. Media that was not inside the archive is never downloaded from the old site.

## Before you switch your domain or close the old site

Check a few posts with images, your menus and your feeds. Ask the operators to run the **migration validator**, which renders every page with the old host blocked and lists any remaining dependency.
