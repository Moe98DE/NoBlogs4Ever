# Network directory

The main site of the platform has a **Discover** page showing recent posts from sites that chose to be listed, a list of topics (categories) and a directory of sites.

## Being listed

An administrator enables **Your platform → Site privacy → List this site in the network directory**. Only public sites are listed; members-only sites never are, even if the box is ticked. Only published posts that are not password-protected appear — never drafts, private or scheduled posts. The directory refreshes within a few minutes of a change.

## For operators

The Discover page is an ordinary page on the main site using three shortcodes, which can be placed anywhere on the main site:

* `[nbe_recent count="20"]` — latest posts across listed sites; add `topic="slug"` to filter by category, or link to `?topic=slug`
* `[nbe_topics]` — the most used categories, linking to a filtered view
* `[nbe_directory]` — all listed sites

The same data is available as JSON at `/wp-json/nbe/v1/discover` (optionally `?topic=slug`). The index is rebuilt by the worker and capped at 500 sites × 5 posts, so viewing the directory never queries every tenant.
