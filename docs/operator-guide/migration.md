# Large imports and migration validation

Site administrators import archives up to 256 MiB themselves ([Importing](../user-guide/importing.md)). This page covers what operators can do beyond that, and how to verify that a migrated site no longer depends on its old host.

## Intake limits

| Limit | Browser default | Hard ceiling (operator path) |
|---|---|---|
| Uploaded file | 256 MiB | 2 GiB |
| Total extracted size | 1 GiB | 8 GiB |
| Archive entries | 10,000 | 100,000 |
| Single entry | 64 MiB | 1 GiB |
| One WXR file | 32 MiB | 128 MiB |
| Items across WXR files | 20,000 | 200,000 |

Entries with a compression ratio above 200:1 (and larger than 1 MiB) are treated as decompression bombs. Paths with `..`, absolute paths, symlinks, device files and duplicate names abort the whole intake before anything is written. XML with a DTD or entity declarations is refused. WXR is parsed with SimpleXML and `LIBXML_NONET`, which needs memory roughly proportional to file size — raise PHP's memory for big archives as shown below.

## Operator import

1. Review the archive offline, then place it in a host directory, e.g. `/srv/imports/old-blog.zip`. Optionally add an author map `/srv/imports/old-blog.authors.json`: `{"old-login": 42, "other-login": 42}` (destination user IDs, who must already be members of the site).
2. Find the destination site ID and an administrator's user ID (`wp site list`, `wp user list --url=…`).
3. Run:

```sh
IMPORT_DIRECTORY=/srv/imports \
NBE_OPERATOR_IMPORT_ACK=reviewed-offline-archive \
NBE_IMPORT_ARCHIVE=/imports/old-blog.zip \
NBE_IMPORT_SITE_ID=12 NBE_IMPORT_USER_ID=42 \
NBE_IMPORT_AUTHOR_MAP=/imports/old-blog.authors.json \
NBE_IMPORT_MAX_INPUT_BYTES=1073741824 \
OPERATOR_IMPORT_PHP_MEMORY=2048M \
make large-import
```

Optional limits: `NBE_IMPORT_MAX_EXTRACTED_BYTES`, `NBE_IMPORT_MAX_FILES`, `NBE_IMPORT_MAX_FILE_BYTES`, `NBE_IMPORT_MAX_XML_BYTES`, `NBE_IMPORT_MAX_ITEMS` (each capped by the hard ceiling). A storage preflight requires free space for the archive, its maximum expansion and 1 GiB of working room.

The job then appears in the site's **Tools → Import publication** page like any other import; without an author map it waits for the site administrator to map authors. The same protections apply as for browser uploads.

To record resource usage while a big job runs: `NBE_PERF_MIGRATION_JOB_ID=<uuid> make migration-performance`.

## Validating a migrated site

After the import finished and the site looks right:

```sh
docker compose run --rm \
  -e NBE_VALIDATION_SITE_ID=12 \
  -e NBE_VALIDATION_SOURCE_HOST=old-blog.example \
  -e NBE_VALIDATION_ALLOWED_EMBEDS=www.youtube.com,player.vimeo.com \
  cli wp --allow-root eval-file /opt/nbe/scripts/validate-migration.php
```

The validator renders every published post and page (up to `NBE_VALIDATION_MAX_PAGES`, default 100) through the local application — never fetching third-party URLs — and reports as JSON:

* remaining references to the old host in stored and rendered content;
* legacy `/files/` references;
* attachments whose file is missing or whose URL is not on the new host;
* broken internal links, pages that fail to render, missing or foreign canonical URLs;
* the post and comment feeds' status;
* third-party embeds, split into the reviewed allowlist and everything else;
* import jobs of this site that need attention.

It exits non-zero when anything needs attention. The browser test suite additionally blocks the old host entirely and checks that imported pages still render with all images.

## Retention

Import workspaces (the original archive, the parsed data and the report) of finished jobs are deleted after `MIGRATION_RETENTION_DAYS` (30). The mapping from source IDs to new IDs is kept, so importing the same or a corrected archive later still never duplicates content. Run `wp eval-file /opt/nbe/scripts/purge-jobs.php` to apply retention immediately.
