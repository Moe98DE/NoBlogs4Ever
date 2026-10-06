# Migration engine

Code: `app/mu-plugins/nbe/Archive.php` (pure intake and parsing), `Migration.php` (engine), `MigrationAdmin.php` (UI). Tests: `tests/unit.php`, `tests/integration/10-core.php`, `30-presentation-migration.php`, `40-export-roundtrip.php`.

## Job lifecycle

```
upload ─► intake ─► (inventory) ─► awaiting_author_mapping ─► queued ─► running ─► complete
                                                                              └──► needs_attention
                         any error ─► failed ─(Run again)─► intake/queued
```

Jobs live in `wp_nbe_jobs` (`id`, `site_id`, `user_id`, `checksum`, `state`, `cursor`, `total`, `report`). The pair (`site_id`, `checksum`) is unique: uploading the same file to the same site returns the existing job. Each job has a private directory under `NBE_JOB_DIR/<uuid>/` containing `original` (untouched upload), `extracted/`, `parsed.json`, `authors.json`, `limits.json` and marker files.

The worker calls `Migration::runNext()` repeatedly within its time budget. `run()` takes a MariaDB advisory lock (`GET_LOCK('nbe-<id>')`), so two workers never process the same job.

## Intake safety

`Archive::extract()` handles a bare WXR file or a ZIP:

1. Size of the upload checked against the limit; SHA-256 recorded.
2. **Every ZIP entry is preflighted before anything is written**: rejected if its path is absolute, contains `..`, `.` or backslashes, if it is a symlink, device or FIFO (Unix mode bits), if it duplicates another entry case-insensitively, if the running total exceeds the extracted-size limit, if one entry exceeds the per-entry limit, or if an entry over 1 MiB compresses better than 200:1.
3. Entries are streamed (never `ZipArchive::extractTo()`), created with `fopen(…, 'x')`, chmod `0600`, and their real size must match the declared size.
4. Files are classified: WXR (`.xml`), media (allowed extensions), configuration (`site.json`, `manifest.json` from a NoBlogs4Ever archive), and **ignored** (everything else — e.g. theme or plugin PHP, which is never executed or served).

`Archive::wxr()` refuses any document containing `<!DOCTYPE` or `<!ENTITY`, parses with `LIBXML_NONET`, requires the WordPress export namespace 1.0–1.2 and a valid source URL, requires unique numeric post IDs and enforces the item limit. `wxrSet()` merges several WXR files from the same source and skips non-WXR XML.

Operator-raised limits require `operator_ack` and are capped by `Archive::CEILINGS`.

## Inventory

Before anything is written to the site, `inventory()` records: checksum and sizes, file counts by class, WXR files, post type counts (posts, pages, attachments, custom types), comment count, authors, categories and tags (with term IDs), custom field names, blocks and shortcodes used, number of references to the source host and legacy `/files/` paths, remote media URLs, and whether a site configuration was found. The job then stops at `awaiting_author_mapping` unless a complete author map was supplied.

## Import

Items are imported in batches (20 by default), each inside a database transaction together with the cursor update, so a crash loses at most one uncommitted item.

* **Idempotency** — every imported object is recorded in `wp_nbe_map` under `sha256(source URL | source ID)` per site. Re-running, retrying or importing a corrected archive skips anything already mapped. Content edited after import is detected by a stored hash and not rewritten again.
* **Types** — `post`, `page`, `attachment`, `wp_block`, `wp_navigation` are imported; `wp_template`, `wp_template_part`, `wp_global_styles` only when their `wp_theme` term is a curated theme; `nav_menu_item` and `custom_css` are rebuilt after all content exists; anything else is reported as unsupported (and stays in the retained original).
* **Fields** — title, content (KSES-sanitised), excerpt, slug, author (mapped), dates and modification dates (written back directly so WordPress does not overwrite them), status (`publish`, `draft`, `pending`, `private`, `future`; anything else becomes `draft`), password, sticky flag, menu order, comment and ping status, categories/tags (hierarchy restored), comments (threading, approval state; IP and agent blanked), alt text, featured image, `public_*` custom fields and a few portable WordPress keys. Other metadata is reported, not imported, because plugin data may contain credentials or executable configuration.
* **Media** — an attachment is imported only if its binary is in the archive, located by `_wp_attached_file`, the URL path below `/files/` or `/uploads/`, or a unique basename (ambiguous matches count as missing). Files go through the normal upload checks, `media_handle_sideload()` generates sizes, and the stored original must match the archive byte for byte (SHA-256). Missing media is listed by source ID and URL.

## Finish: links and presentation

After the last item:

1. **URL rewriting** — a map of old → new URLs is built from imported items' permalinks and attachments. Content is rewritten only to targets that exist locally: full URLs, protocol-relative URLs, root-relative `/files/…` and `/wp-content/blogs.dir/<n>/files/…` paths, image derivatives (`name-300x200.jpg` → the local size with the same dimensions, or the full image), gallery shortcode IDs, block attributes (`id`, `mediaId`, `ref`, `featuredImage`) and `wp-image-<id>` classes. Every rewrite is recorded.
2. **Relationships** — post parents (pages, attachments) and featured images.
3. **Menus** — `nav_menu` terms become menus; items pointing to posts/pages/terms are re-targeted to the imported objects; custom links are rewritten through the URL map; hierarchy is restored in two passes.
4. **Additional CSS** — applied for curated themes when the site has none yet and the CSS contains no markup or script-capable constructs; otherwise reported.
5. **Site configuration** from a NoBlogs4Ever archive: curated theme, Additional CSS, menu locations, static front page.
6. **Validation** — remaining references to the source host and `/files/` paths are listed per post. The job ends `complete`, or `needs_attention` when media is missing, source references remain or unsupported types were found. The importing user gets an email.

## Report

The JSON report (stored in the job row, downloadable) contains: checksum, sizes, inventory, author mappings, counts imported by type, comments, taxonomies, menus, media discovered/imported/missing, unsupported post types/taxonomies/blocks/shortcodes, URL rewrites, unresolved source references, theme compatibility, presentation notes, integrations to reconnect, warnings, errors and the validation timestamp.

## Extending

* A new importable post type: add it to `CONTENT_TYPES` (or handle it in `item()`), add fixture items and integration assertions.
* A compatibility renderer for a common shortcode: register the shortcode in a new module; the importer will then stop reporting it as unsupported.
* Remote media fetching is intentionally absent; if you propose it, include an explicit opt-in, SSRF protections (public IPs only, size/time limits, no redirects to private ranges) and per-file reporting.
