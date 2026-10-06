# Site analytics

Site analytics are **off by default**. An administrator can enable them under **Your platform → Site privacy → Count page views**, then read them under **Dashboard → Site analytics**.

## What is counted

For each day, each page and each coarse visitor category, the platform increments one counter on the server. You see:

* page views per day by people, and approximate automated requests (crawlers, scripts) separately;
* your most viewed posts and pages (home, archive and other pages are grouped together);
* browser family (Chrome, Firefox, Safari, Edge, …) and device class (desktop, mobile, tablet).

## What is not collected

No script runs in readers' browsers and no cookie is set. The platform stores no IP addresses, no full user-agent strings, no referrers, no query strings, no visitor identifiers and no individual visits — only the aggregate counters above. Because there are no identifiers, *unique visitors* are deliberately not measured.

Views by people who are signed in, previews, feeds and 404 pages are not counted. Members-only sites and sites that ask search engines not to index them are never counted.

Counters older than the retention period (14 days by default, set by the operators) are deleted automatically. Analytics of one site are never visible to administrators of another.

The same data is available as JSON to administrators at `/wp-json/nbe/v1/analytics?days=14`.
