# NoBlogs4Ever — Product specification

> **About this document.** This is the original product specification NoBlogs4Ever was built from. It was written as a build brief for the engineer(s) implementing the platform, which is why it is phrased as instructions. It remains the reference for the intended scope; see [Project status](../getting-started/project-status.md) for what is implemented today.

You are the principal engineer responsible for building **NoBlogs4Ever**, a secure, privacy-oriented, multi-tenant website and publishing platform.

You are not producing a prototype, mockup, landing page, or architectural exercise. You are responsible for constructing the functioning product, its deployment architecture, its migration system, its security boundaries, its tests, and its operator documentation.

Work autonomously.

Do not stop after scaffolding.

Do not declare the project complete merely because the application starts.

Do not ask for approval between ordinary engineering steps. When a detail is unspecified, choose the safest, simplest, maintainable solution consistent with this specification and document that decision.

If the repository already contains code, inspect and understand it before changing architecture. Preserve sound existing work where possible.

If the repository is empty, initialize the project yourself.

---

# 1. Product objective

Build a centrally managed publishing service that allows individuals and small groups to create and operate websites without administering servers, databases, PHP installations, plugins, certificates, or operating systems themselves.

The platform must emphasize:

- ease of publishing;
- recognizable WordPress workflows;
- safe multi-user collaboration;
- strong privacy defaults;
- centrally managed software;
- constrained tenant privileges;
- straightforward site creation;
- reliable export and migration;
- robust import of existing WordPress/NoBlogs-style archives;
- minimal operational data collection;
- secure administration;
- recoverability;
- maintainability by a small operator team.

A normal user should be able to:

1. create an account;
2. create a site;
3. select a presentation theme;
4. publish posts and pages;
5. upload media;
6. organize content using categories and tags;
7. invite collaborators;
8. assign appropriate editorial roles;
9. manage comments;
10. customize presentation without receiving arbitrary server-side code execution;
11. export their content;
12. import an existing WordPress-compatible publication;
13. continue publishing after migration with minimal manual reconstruction.

The service must feel like a managed publishing platform, not a VPS control panel.

---

# 2. Architectural foundation

Use **WordPress Multisite as the compatibility and publishing core** unless an already-existing repository makes that technically unreasonable.

This is an intentional requirement.

Do not replace the publishing core with a custom CMS merely because building one is architecturally interesting.

The reasons are:

- WordPress-compatible content semantics are part of the target behavior.
- WXR import/export compatibility is critical.
- WordPress roles are part of the target collaboration model.
- Gutenberg/block editing is part of the target UX.
- Classic editing must remain possible.
- WordPress feeds, comments, taxonomies, revisions, scheduling, themes, and media behavior are desirable compatibility surfaces.
- Existing migration archives are expected to originate from WordPress Multisite environments.

Use Multisite in **subdomain mode**.

The default tenant URL model must be:

`https://<site-slug>.<platform-domain>`

The base platform domain must be configurable and never hard-coded.

Development environments may use an appropriate local domain convention.

---

# 3. Deployment architecture

Produce a reproducible deployment.

At minimum the deployed system should contain:

- TLS-capable reverse proxy;
- WordPress Multisite;
- PHP runtime;
- MySQL or MariaDB;
- persistent media storage;
- transactional email integration;
- background/scheduled task execution;
- backup tooling;
- centralized but privacy-preserving application diagnostics;
- health checks;
- deployment configuration;
- secrets management;
- automated testing.

Use containers where useful for reproducibility.

Provide a local development environment that can be launched with a small number of documented commands.

Prefer a conventional and maintainable architecture over exotic infrastructure.

Do not introduce Kubernetes solely for perceived sophistication.

Production configuration must keep secrets separate from source-controlled generic configuration.

Pin runtime and application dependencies to supported versions and make upgrades deliberate and reproducible.

Record actual deployed component versions rather than making undocumented assumptions about them.

---

# 4. Centralized software-management model

Tenants must not receive arbitrary server-side code execution.

Ordinary site administrators must NOT be able to:

- install arbitrary WordPress plugins;
- upload PHP plugins;
- install arbitrary themes containing executable server-side code;
- obtain SSH;
- obtain SFTP access to application code;
- obtain shell access;
- access the underlying SQL database;
- invoke unrestricted WP-CLI;
- change network configuration;
- modify other tenants;
- access secrets belonging to the platform or another site.

Software is centrally curated by the network operator.

Implement operator-controlled:

- plugin availability;
- theme availability;
- must-use plugins;
- network settings;
- security configuration;
- upgrade workflow.

Site administrators receive substantial control over their own content and presentation but not the execution environment.

---

# 5. Tenant boundaries

Treat every site as a distinct security tenant even though the application runtime is shared.

A tenant boundary must exist around:

- posts;
- pages;
- drafts;
- comments;
- media metadata;
- uploaded files;
- settings;
- menus;
- theme configuration;
- analytics;
- collaborators;
- integration credentials;
- site-specific API permissions.

Explicitly test for cross-site authorization failures.

A user who administers Site A must never gain Site B privileges merely because both sites exist in the same Multisite network.

Network super-administration is an operator role and must remain separate from normal site administration.

Document the implications of application-level multi-tenancy in the threat model.

---

# 6. Account model

Support a shared network identity capable of belonging to one or more sites.

A user must be able to:

- register an account;
- create a site during registration where permitted;
- register an account without immediately creating a site;
- create additional sites later where policy permits;
- belong to multiple sites;
- hold different roles on different sites;
- update profile details;
- change password;
- configure recovery email;
- enable multi-factor authentication;
- generate and regenerate recovery codes;
- securely terminate active sessions.

Username validation should use conservative WordPress-compatible semantics.

Do not hard-code a historical list of acceptable email providers.

Instead implement a configurable registration policy supporting:

- allowlisted domains;
- denylisted domains;
- unrestricted registration;
- invitation-only registration;
- administrator approval.

The operator must be able to change policy without changing application code.

---

# 7. Authentication security

Implement secure authentication suitable for a privacy-sensitive publishing service.

At minimum support:

- password authentication;
- TOTP;
- WebAuthn/passkeys or hardware security keys;
- one-time backup/recovery codes.

Email-based verification may exist as a recovery mechanism, but hardware-backed or TOTP authentication should be preferred for MFA.

Requirements:

- secure password hashing using the supported WordPress mechanism;
- strong session-cookie settings;
- HTTPS-only production sessions;
- SameSite protections appropriate to WordPress functionality;
- CSRF protection;
- login rate limiting;
- enumeration resistance where practical;
- secure password-reset tokens;
- configurable session expiration;
- ability to revoke sessions;
- MFA recovery flow;
- MFA enforcement option for network administrators.

Never log:

- plaintext passwords;
- MFA secrets;
- recovery codes;
- password-reset tokens;
- authentication cookies.

Secrets must never be committed to source control.

---

# 8. Roles and collaboration

Preserve conventional WordPress editorial roles:

## Network Super Administrator

Controls:

- network software;
- network configuration;
- plugins;
- themes;
- global policy;
- tenant lifecycle;
- security-sensitive operator features.

## Site Administrator

Controls the assigned site within network restrictions.

Must not receive network software control.

## Editor

Can publish and manage editorial content including content created by other authors.

## Author

Can publish and manage their own posts.

## Contributor

Can prepare their own posts but cannot independently publish them.

## Subscriber

Receives basic profile/site access appropriate to the site.

Maintain least privilege.

The UI should make role scope understandable.

Do not encourage collective users to share a single password.

---

# 9. Site creation

Implement a simple site-creation flow.

Users with permission should be able to provide:

- site slug;
- site title;
- optional description/tagline;
- preferred interface language.

Validate site slugs carefully.

Protect:

- reserved hostnames;
- infrastructure names;
- misleading operator names;
- malformed Unicode;
- invalid DNS labels.

Site provisioning must be automated.

Creating a site should provision its WordPress tenant state without requiring operator shell commands.

Provide abuse/rate controls around automated site creation.

---

# 10. Administration experience

The site administration experience should remain recognizably WordPress.

Users should have conventional areas for:

- Dashboard;
- Posts;
- Pages;
- Media;
- Comments;
- Appearance;
- Users;
- Tools;
- Settings;
- Analytics where enabled.

Do not unnecessarily redesign established WordPress concepts.

The goal is familiarity and low migration friction.

Operator-specific functionality should be integrated cleanly into the dashboard rather than requiring an entirely separate administration product unless there is a strong security reason.

---

# 11. Editorial features

Support standard publishing concepts:

- posts;
- pages;
- drafts;
- pending review;
- scheduled publishing;
- published content;
- revisions;
- categories;
- tags;
- comments;
- featured images;
- custom fields where WordPress compatibility requires them;
- author attribution;
- publication timestamps;
- post slugs;
- excerpts;
- RSS/Atom feeds.

Preserve WordPress URL behavior where practical.

Do not silently change publication timestamps during migration.

---

# 12. Block and Classic editing

Use the WordPress block editor as the default editing interface.

Also provide a supported way for a site to use the Classic Editor where needed.

The block editor must support ordinary:

- text;
- headings;
- lists;
- links;
- images;
- galleries;
- quotations;
- code/preformatted content;
- media;
- embeds;
- tables;
- reusable/pattern-like structures available in the deployed WordPress version.

Do not expose arbitrary JavaScript execution to ordinary authors merely because an HTML-oriented block exists.

Sanitize untrusted markup according to role and WordPress security semantics.

---

# 13. Themes and presentation

Provide a curated theme catalog.

Include both:

- a modern block-theme experience;
- at least one conventional/classic WordPress theme.

Users should be able to customize sites through the workflows supported by the selected theme.

Where applicable support:

- Site Editor;
- template editing;
- patterns;
- navigation;
- Customizer-style settings;
- widgets for legacy themes;
- menus;
- site icon;
- logos;
- typography/theme settings exposed by the theme;
- Additional CSS.

Additional CSS must be sanitized appropriately.

Tenants may customize presentation.

Tenants may not upload arbitrary executable PHP themes.

---

# 14. Media system

Support conventional WordPress media workflows.

At minimum:

- image upload;
- document attachment;
- audio attachment;
- video attachment subject to configured resource policy;
- gallery creation;
- featured images;
- image derivatives/thumbnails;
- alt text;
- captions;
- attachment metadata.

ODS files should be accepted as ordinary downloadable media attachments if permitted by the configured MIME policy.

Treat office files as attachments, not collaborative editors.

Upload limits must be configurable.

Do not invent a historical quota.

Define operator-configurable limits for:

- maximum file size;
- allowed MIME types;
- total site storage where enabled;
- image-processing constraints.

Validate MIME type and extension.

Protect against malformed uploads and executable-file abuse.

---

# 15. External embeds

Permit safe external embedding where supported by WordPress.

Support common media URLs.

Provide controlled iframe support for approved use cases such as map embeds where safe.

Never interpret "HTML block" as permission for unrestricted script execution.

External resources must be clearly understood as externally hosted dependencies.

Document that externally embedded resources:

- can disappear independently;
- can disclose reader metadata to third parties;
- are not backed up automatically;
- may have their own privacy policies.

---

# 16. Multilingual publishing

Provide editorial multilingual support.

This means separate language versions of content and a language-switching mechanism.

Do not implement automatic machine translation as a substitute.

Use a centrally curated and maintained WordPress multilingual solution compatible with Multisite and the selected editing experience.

Support per-site language configuration.

---

# 17. Comments and moderation

Implement conventional WordPress comment moderation.

Provide:

- comments enabled/disabled per site;
- per-post controls;
- moderation queue;
- spam handling;
- commenter identity fields according to site settings;
- rate limiting;
- abuse protections.

Do not add third-party tracking-based comment services by default.

---

# 18. Feeds and discovery

Public sites must expose conventional syndication feeds.

Create a modest network-level discovery layer for public sites.

Provide:

- recent public activity;
- topic/category-oriented discovery;
- site directory where appropriate.

Do not expose:

- private sites;
- drafts;
- restricted content;
- unlisted tenant information.

Do not assume a historical search implementation.

Build the discovery layer using documented, maintainable application behavior.

---

# 19. ActivityPub

Provide optional ActivityPub integration through a centrally maintained implementation.

A site administrator should be able to choose whether the site participates.

Support an appropriate:

- site/collective identity;
- author-oriented identity where supported;
- follower model;
- inbound interaction/reaction handling;
- moderation controls.

The user interface must explain an important limitation:

Federated public content can be copied to remote servers.

Local deletion cannot guarantee deletion of remote copies.

Do not imply otherwise.

Treat federation identity as tied to hostname and actor identity.

Design configuration with future hostname migration in mind where technically possible, but do not promise seamless federation identity transfer where the protocol or remote implementations do not permit it.

---

# 20. Mastodon/social autopost integrations

Treat outbound social-account posting as separate from ActivityPub federation.

If implemented, use an explicit OAuth-style authorization model.

Requirements:

- tokens encrypted at rest where practical;
- tokens never exposed in logs;
- user-visible connected-account list;
- explicit disconnect/revoke operation;
- graceful handling of expired authorization;
- least-privilege scopes.

Deleting a local post must not be represented as automatically revoking an external application authorization.

---

# 21. Encrypted contact forms

Provide an optional encrypted-contact workflow.

The design goal is that a site can publish a contact form whose message content is encrypted for the intended recipient.

Do not make unsupported security claims.

If describing the feature as end-to-end encrypted, ensure the deployed design actually encrypts content in the visitor's browser using a recipient-controlled public key before the message reaches application infrastructure.

Otherwise describe it more narrowly.

Requirements:

- recipient public-key configuration;
- key fingerprint display;
- validation of malformed keys;
- protection against accidental plaintext logging;
- clear key replacement/rotation workflow;
- safe handling of message metadata;
- abuse/rate controls;
- CSRF protections where applicable;
- no plaintext content in application logs.

Document exactly which metadata the platform can still observe.

---

# 22. Privacy model

Privacy is a core product property.

Minimize data collection.

Do not install third-party advertising or behavioral analytics.

Avoid persistent storage of ordinary reader IP addresses.

Avoid persistent storage of author IP addresses solely for routine publishing.

Where IP-based abuse controls are required, prefer short-lived in-memory or privacy-preserving rate-limit state.

Operational diagnostics must be designed to avoid turning the service into a request-history database.

Document exactly what is collected.

---

# 23. Privacy-preserving analytics

Provide optional site analytics derived from server-side data rather than a browser tracking script.

The analytics design should prioritize aggregate information such as:

- page views;
- approximate unique-visit measures only if they can be implemented without persistent identifying profiles;
- URL/path;
- broad browser class;
- broad device class;
- human versus automated request classification.

Do not create persistent cross-site visitor identifiers.

Do not fingerprint users.

Do not use advertising IDs.

Do not load third-party analytics JavaScript.

Make retention configurable and short.

Analytics belonging to Site A must not be visible to Site B.

---

# 24. Logging

Distinguish:

1. security/application event logs;
2. operational diagnostics;
3. aggregate metrics;
4. user-facing analytics.

They are not the same thing.

Implement structured logging.

Redact or exclude sensitive fields.

Never log:

- passwords;
- password hashes unnecessarily;
- session cookies;
- API bearer tokens;
- OAuth secrets;
- MFA seeds;
- recovery codes;
- PGP private keys;
- full contact-form plaintext.

Provide log-retention configuration.

Default to short retention.

Document the retention policy.

---

# 25. Security headers and edge behavior

Production HTTPS responses should implement appropriate modern HTTP security headers.

At minimum evaluate and correctly configure:

- HSTS;
- X-Content-Type-Options;
- Content-Security-Policy;
- Referrer-Policy;
- framing policy via CSP `frame-ancestors` or appropriate equivalent;
- secure cache behavior for authentication/admin responses.

Authentication and private administrative pages must not be publicly cached.

Do not blindly copy headers without verifying that they are compatible with WordPress administration, embeds, federation, and required site functionality.

---

# 26. Caching

Public sites may use reverse-proxy/application caching.

Administrative and authenticated traffic must remain correctly isolated.

Never allow cached authenticated content to leak between users.

Document cache keys and bypass rules where relevant.

Provide cache invalidation after content publication/update.

Do not assume public HTTP 200 responses imply a healthy backend.

Expose useful health checks separately from public page caching.

---

# 27. REST/API behavior

WordPress REST interfaces may be exposed only according to explicit security policy.

Document:

- unauthenticated endpoints;
- authenticated endpoints;
- write permissions;
- CORS policy;
- authentication methods;
- rate limits.

Do not automatically enable powerful Application Password or XML-RPC functionality without evaluating the threat model.

If they are enabled, document why and test their authorization boundaries.

---

# 28. No arbitrary application hosting

This service is a publishing platform.

It is not a general application-hosting environment.

Tenant users must not receive:

- arbitrary PHP execution;
- arbitrary cron workers;
- arbitrary daemon processes;
- custom containers;
- unrestricted filesystem execution;
- direct SQL access;
- Git deployment;
- general-purpose shell access.

Client-side presentation customization is not equivalent to server-side code deployment.

---

# 29. Migration system — critical feature

Migration is a first-class subsystem, not an afterthought.

Implement a dedicated migration workflow for WordPress and NoBlogs-style exports.

Support at minimum:

1. standard WordPress WXR/XML;
2. an uploaded archive containing WXR plus media;
3. reasonable variations of archive directory layout.

Do not assume one undocumented archive structure.

Build archive discovery.

---

# 30. Migration intake safety

Uploaded migration archives are untrusted input.

Before extraction:

- calculate a strong checksum;
- store the untouched original;
- record file size;
- create a migration record;
- inspect archive format.

Defend against:

- path traversal/Zip Slip;
- absolute paths;
- symlinks escaping extraction directories;
- decompression bombs;
- excessive file counts;
- oversized files;
- malformed XML;
- entity-expansion attacks;
- dangerous MIME mismatches.

Never execute code contained in an imported archive.

Theme or plugin PHP contained in an archive must not become executable merely because it was uploaded.

---

# 31. Migration inventory

Before transforming imported data, create an inventory.

Record:

- archive checksum;
- XML files discovered;
- media directories discovered;
- file count;
- aggregate extracted size;
- post counts;
- page counts;
- comment counts;
- author identities referenced;
- categories;
- tags;
- custom post types;
- custom fields;
- attachment records;
- media files;
- references to unavailable remote media;
- detected legacy paths;
- detected shortcodes;
- detected custom blocks;
- detected source-domain URLs.

Present this inventory to the user as part of the migration report.

---

# 32. WXR import behavior

Import WordPress content deliberately.

Preserve wherever technically possible:

- post titles;
- post bodies;
- page bodies;
- slugs;
- authorship mappings;
- publication dates;
- modification dates;
- draft/published status;
- categories;
- tags;
- comments;
- comment hierarchy;
- custom fields;
- attachment relationships;
- featured images;
- supported custom post types.

Unknown custom post types must be reported.

Do not silently discard them.

---

# 33. Author mapping

Do not assume imported authors correspond to existing platform accounts.

Provide a mapping step allowing:

- map imported author to existing account;
- create/invite a new account;
- map several imported authors to one account where intentionally requested;
- assign content temporarily to the importing administrator.

Do not import old password hashes as usable credentials unless a future explicit migration design safely supports that.

Imported users should establish fresh authentication and recovery credentials.

---

# 34. Media migration

Media migration must verify actual binary availability.

Do not consider an attachment migrated merely because the XML contains an attachment record.

Handle:

- bundled media;
- referenced remote media;
- image derivatives;
- galleries;
- featured images;
- attachment links;
- source-domain URLs.

Where the archive contains files, prefer those local copies.

If remote fetching is supported, make it explicit and record whether it succeeded.

Never make successful migration depend indefinitely on the source host remaining online.

---

# 35. Legacy `/files/` URL compatibility

Detect legacy WordPress Multisite media references resembling:

`/files/...`

and historical layouts corresponding to site-specific files directories.

Provide a migration compatibility layer or deterministic URL rewrite.

Only rewrite a source URL after confirming the replacement asset exists locally.

Preserve an auditable mapping between old and new paths.

Test old-style attachment URLs in migration fixtures.

---

# 36. Migration of presentation state

Attempt to preserve:

- active theme identity where that theme exists in the curated destination catalog;
- custom CSS;
- menus;
- widgets;
- Site Editor patterns where export data permits;
- supported theme settings.

Do not fabricate missing configuration.

If an old theme is unavailable, select no replacement silently.

Instead:

1. report the unavailable theme;
2. offer a safe curated fallback;
3. preserve recoverable content;
4. identify presentation differences.

---

# 37. Shortcodes and custom blocks

Scan imported content for shortcodes and custom blocks.

For every unsupported dependency, include it in the migration report.

Where feasible, provide compatibility renderers for commonly expected centrally supported functionality.

Never display raw shortcode markup to users without warning if it can be detected during migration.

Do not install unknown plugins from an import archive.

---

# 38. Integration migration boundaries

Content import does NOT imply secure migration of external credentials.

Treat these separately:

- Mastodon/social tokens;
- API credentials;
- PGP private keys;
- contact-form key configuration;
- federation identity;
- application passwords;
- OAuth connections;
- external-service passwords.

Never assume these are contained in WXR.

Require users to reconnect external accounts.

Where public PGP keys or non-secret configuration can be safely imported, make the behavior explicit.

Never import executable secrets blindly from an archive.

---

# 39. Source independence test

A migrated site is NOT considered complete until it functions without access to the source host.

Implement a migration validation phase that identifies requests still depending on the old source domain.

Check:

- images;
- gallery assets;
- CSS references where applicable;
- internal links;
- attachment URLs;
- feeds;
- canonical URLs;
- embeds;
- legacy `/files/` paths.

External resources intentionally hosted elsewhere should be distinguished from accidental dependencies on the old publication host.

---

# 40. Idempotent migration

Imports must be resumable and safe to retry.

Do not duplicate hundreds of posts because a migration job was restarted.

Maintain import state.

Use deterministic source identifiers where possible.

Provide:

- queued/background processing;
- progress state;
- resumability;
- failure reporting;
- partial-import visibility;
- retry of failed media;
- final validation.

Large imports must not depend on a single HTTP request remaining open.

---

# 41. Migration report

Every completed migration should produce a report containing:

- archive checksum;
- imported post/page count;
- imported comment count;
- imported taxonomy count;
- media discovered;
- media imported;
- media missing;
- author mappings;
- unsupported post types;
- unsupported blocks;
- unsupported shortcodes;
- theme compatibility;
- URL rewrites;
- unresolved source-domain references;
- external integrations requiring reconnection;
- warnings;
- errors.

Allow the operator/user to retain or download the report.

---

# 42. Export

Preserve ordinary WordPress export functionality.

Users must be able to export their publication without operator intervention.

Do not create artificial lock-in.

Document what a WXR export includes and what it does not include.

Where practical, provide an enhanced site-backup export containing:

- WXR content;
- uploaded media;
- safe site configuration;
- custom CSS;
- menu information;
- migration manifest;
- checksums.

Do not put credentials, password hashes, private keys, OAuth tokens, or application secrets into ordinary portable exports.

---

# 43. Deletion

Provide an intentional site-deletion workflow.

Requirements:

- explicit user confirmation;
- appropriate authorization;
- prevent accidental deletion through CSRF;
- recommend export before deletion;
- clearly explain retention implications;
- distinguish deletion from immediate erasure of backups or remote federated copies.

Do not claim that deleting a federated post guarantees its disappearance from remote servers.

---

# 44. Backups

Implement production backup capability.

At minimum include:

- database backups;
- uploaded-media backups;
- configuration needed to recreate the service;
- encrypted storage of backup material;
- retention policy;
- restoration documentation.

Do not merely create backup scripts.

Create a restore procedure.

Test the restore procedure in an automated or reproducible environment.

Record expected RPO/RTO only after the implemented backup process can support those claims.

Never invent guarantees.

---

# 45. Recovery

Document recovery from:

- database corruption;
- accidental site deletion;
- failed upgrade;
- lost application node;
- lost media storage;
- compromised network administrator account.

Where possible make deployment infrastructure replaceable rather than manually reconstructed.

---

# 46. Updates and dependency management

The network operator owns application updates.

Provide a controlled workflow for:

- WordPress core;
- PHP;
- database;
- themes;
- plugins;
- Composer dependencies;
- container images;
- operating-system packages where relevant.

Use lockfiles/version pinning.

Generate an identifiable release.

Record deployed versions.

Do not represent a dependency manifest alone as proof of what production is running.

Provide rollback instructions.

---

# 47. Security engineering

Maintain a threat model covering at least:

- compromised site administrator;
- compromised author;
- compromised network administrator;
- vulnerable plugin;
- vulnerable theme;
- malicious upload;
- cross-site tenant access;
- CSRF;
- XSS;
- SSRF;
- SQL injection;
- privilege escalation;
- credential stuffing;
- password reset abuse;
- session theft;
- migration archive attacks;
- malicious XML;
- dependency compromise;
- leaked OAuth token;
- leaked backup;
- server compromise.

Where WordPress provides a secure standard mechanism, use it instead of inventing cryptography or authorization systems.

---

# 48. Operator-security separation

Sensitive network-administration functionality must be difficult to reach accidentally from ordinary tenant accounts.

Require MFA for production network super administrators.

Prefer separate named operator accounts.

Do not use shared super-admin credentials.

Audit security-sensitive network actions without unnecessarily recording reader identities.

---

# 49. Abuse controls

Implement controls appropriate to public registration and publishing.

Consider:

- registration rate limiting;
- login rate limiting;
- password-reset rate limiting;
- site-creation rate limiting;
- comment abuse controls;
- contact-form abuse controls;
- upload limits;
- email-send limits.

Do not build these controls around indefinite storage of identifiable IP histories.

---

# 50. Email

Abstract outbound transactional email through configuration.

Support:

- account verification where used;
- password reset;
- invitations;
- administrative notifications;
- site deletion confirmation if used;
- migration completion/failure notices where useful.

Development must have a safe local email sink rather than sending accidental production mail.

Do not couple the service permanently to one email provider.

---

# 51. Accessibility

Use accessible defaults.

Ensure custom platform UI supports:

- keyboard operation;
- proper labels;
- semantic headings;
- visible focus;
- reasonable contrast;
- descriptive validation errors.

Do not claim full WCAG conformance unless it has actually been evaluated.

Preserve WordPress accessibility improvements rather than replacing them with inaccessible custom components.

---

# 52. Responsive behavior

Normal administration and site usage should function on contemporary mobile and desktop browsers.

Do not assume complex site design will be equally convenient on a phone, but basic editorial tasks must remain usable.

Test critical flows at multiple viewport sizes.

---

# 53. Performance

Do not invent historical performance targets.

Instead establish engineering targets for this implementation and document them.

Ensure:

- public cached pages are efficient;
- admin actions are not incorrectly cached;
- large imports run asynchronously;
- image processing cannot trivially exhaust workers;
- expensive discovery/search queries are bounded;
- federation jobs do not block user-facing HTTP requests where avoidable.

Add basic load/performance tests around critical paths.

Do not claim an RPS capacity that has not been measured.

---

# 54. Observability

Provide operator visibility without excessive surveillance.

At minimum provide:

- application health;
- database health;
- queue/background-job health;
- disk/storage pressure;
- error rate;
- latency aggregates;
- failed migrations;
- failed scheduled tasks;
- backup status.

Metrics should avoid unnecessary user-identifying dimensions.

---

# 55. Development environment

Create a documented local environment capable of testing:

- wildcard/subdomain Multisite routing;
- tenant creation;
- HTTPS or a documented development equivalent;
- email;
- media upload;
- background jobs;
- migration;
- federation-related code where feasible;
- backups.

Include seed/demo data.

Create at least:

- one network administrator;
- two independent sites;
- multiple site roles;
- representative posts/media/comments.

This is necessary for tenant-isolation testing.

---

# 56. Testing requirements

Build a serious automated test suite.

Include:

## Unit tests

For custom application logic.

## Integration tests

For:

- account lifecycle;
- site creation;
- role assignments;
- media;
- migration;
- URL rewriting;
- analytics isolation;
- external-integration credential handling.

## End-to-end tests

For critical browser workflows:

- registration;
- login;
- MFA setup where automation is reasonable;
- site creation;
- publish article;
- edit article;
- upload media;
- add collaborator;
- export;
- import;
- verify migrated content.

## Security regression tests

Especially:

- cross-tenant access;
- role escalation;
- unauthorized network-admin endpoints;
- archive path traversal;
- malicious XML;
- XSS in imported content;
- unsafe HTML;
- CSRF-sensitive actions;
- credential leakage.

---

# 57. Migration fixtures

Create realistic migration fixtures covering:

- posts;
- pages;
- drafts;
- scheduled posts;
- multiple authors;
- comments;
- nested comments;
- categories;
- tags;
- custom fields;
- featured images;
- galleries;
- Unicode;
- non-English content;
- filenames containing spaces;
- legacy `/files/` URLs;
- missing media;
- remote media;
- unsupported shortcode;
- unsupported block;
- old source-domain internal links.

Expected import outcomes must be asserted.

---

# 58. CI

Add continuous integration that performs at minimum:

- formatting/lint checks for custom code;
- static analysis where appropriate;
- unit tests;
- integration tests;
- security-sensitive migration tests;
- build/package validation.

Do not permit a green CI status if critical test suites were silently skipped.

---

# 59. Documentation

Create and maintain:

`README.md`

`docs/architecture.md`

`docs/development.md`

`docs/deployment.md`

`docs/security-model.md`

`docs/threat-model.md`

`docs/privacy.md`

`docs/migration.md`

`docs/backup-restore.md`

`docs/operator-runbook.md`

`docs/upgrades.md`

`docs/incident-response.md`

The exact filenames can differ if the repository has an established convention, but the subject matter must exist.

---

# 60. Architecture documentation

Document:

- request path;
- reverse proxy;
- PHP/application layer;
- database;
- media storage;
- queue/background work;
- caching;
- email;
- backups;
- secrets;
- tenant model;
- trust boundaries.

Clearly distinguish logical architecture from assumptions about physical infrastructure.

---

# 61. Privacy documentation

State clearly:

- what request data is collected;
- what logs exist;
- what is intentionally not collected;
- log retention;
- analytics behavior;
- backup retention;
- external embeds;
- federation privacy implications;
- email metadata implications;
- contact-form encryption boundaries.

Never use absolute marketing phrases such as "we log nothing" if the application retains operational data.

Make narrower claims that are technically true.

---

# 62. Incident response

Create an operator procedure for a suspected application compromise.

Cover:

- read-only emergency mode;
- preservation of evidence;
- credential reset;
- network-administrator credential rotation;
- session invalidation;
- OAuth token review;
- affected-user notification workflow;
- dependency investigation;
- restoring from known-good state;
- verifying remediation before restoring writes.

Do not represent returning HTTP 200 responses as evidence that the publication system is healthy.

---

# 63. Read-only emergency mode

Implement a network-level emergency read-only mode.

When active:

- public cached/site content remains readable where safe;
- ordinary publication writes are blocked;
- admin UI clearly explains the state;
- sensitive administrative remediation remains available only where appropriate;
- background jobs that produce public changes are controlled.

This mode is for incident handling and maintenance.

Test it.

---

# 64. Product branding

The product name is:

**NoBlogs4Ever**

Keep branding configuration separate from application internals where practical.

Avoid scattering hard-coded product/domain strings throughout custom code.

Base domain, support addresses, registration policy, retention values, quotas, and operator-facing URLs must be configurable.

---

# 65. Explicit non-goals for initial completion

Do not block the core product on features whose expected historical behavior is unknown.

The following are not mandatory for initial completion unless already present:

- arbitrary custom domains;
- tenant-controlled server code;
- paid subscriptions;
- commercial billing;
- guaranteed uptime SLA;
- guaranteed fixed storage quota;
- geographically distributed database replication;
- a specific database-sharding algorithm;
- Kubernetes;
- direct database exports;
- general-purpose application hosting.

Architect so reasonable extensions remain possible.

Do not invent historical compatibility claims for these features.

---

# 66. Implementation sequence

Work in this order unless the existing repository provides a strong reason not to.

## Phase 1 — Repository and architecture

- inspect repository;
- establish implementation plan;
- initialize reproducible environment;
- create dependency management;
- establish Multisite;
- configure wildcard development routing;
- establish database and persistent media;
- create CI skeleton.

## Phase 2 — Identity and tenant isolation

- account lifecycle;
- site creation;
- role behavior;
- network-admin separation;
- authentication hardening;
- MFA;
- tenant-isolation tests.

## Phase 3 — Publishing baseline

- posts/pages;
- block editor;
- Classic Editor option;
- comments;
- taxonomy;
- revisions;
- scheduling;
- media;
- feeds.

## Phase 4 — Presentation

- curated themes;
- block theme;
- classic theme;
- Site Editor/Customizer behavior;
- custom CSS;
- menus;
- widgets/patterns as applicable.

## Phase 5 — Privacy and operations

- logging policy;
- analytics;
- rate limiting;
- security headers;
- caching;
- health checks;
- backup;
- restore;
- read-only mode.

## Phase 6 — Migration

- archive intake;
- checksum/inventory;
- secure extraction;
- WXR import;
- author mapping;
- media restoration;
- `/files/` compatibility;
- URL rewriting;
- migration report;
- source-independence validation;
- large-job resume/retry behavior.

## Phase 7 — Integrations

- multilingual publishing;
- ActivityPub;
- external social autopost where included;
- encrypted contact forms;
- safe embeds.

## Phase 8 — Hardening

- security regression suite;
- restore test;
- migration fixtures;
- performance checks;
- accessibility review;
- documentation completion.

---

# 67. Engineering workflow

Maintain an implementation plan in the repository.

For each major milestone:

1. inspect current implementation;
2. implement the smallest coherent vertical slice;
3. run relevant tests;
4. fix failures;
5. run broader regression tests;
6. update documentation;
7. continue.

Do not accumulate a huge untested change set.

Do not solve failing tests by deleting meaningful assertions.

Do not disable security functionality merely to make tests pass.

---

# 68. Decision-making rules

When this specification leaves a detail open:

Prefer, in order:

1. security;
2. user data preservation;
3. compatibility;
4. privacy;
5. operational simplicity;
6. maintainability;
7. performance;
8. feature novelty.

Document meaningful architectural decisions.

Do not invent compatibility behavior merely to avoid making a decision.

---

# 69. No false completion

You may not call the project complete when any of the following are true:

- migration is only a placeholder;
- imports lose media without reporting it;
- tenant-isolation tests do not exist;
- backups exist but restore has never been tested;
- arbitrary users can install server-side plugins;
- secrets are committed;
- MFA is represented in the UI but does not function;
- critical tests are failing;
- the deployment cannot be reproduced from documentation;
- imported sites still unknowingly depend on the source host;
- major security TODOs remain in production-critical paths;
- documentation claims properties the implementation does not have.

---

# 70. Definition of done

The product is ready for an initial production deployment only when all of the following are true.

A new operator can follow the documentation and deploy the service.

A user can create an account.

A user can create a site.

The site receives a tenant subdomain.

The user can publish using the block editor.

The user can use the supported Classic Editor workflow.

The user can upload media.

The user can choose from curated themes.

The user can change supported presentation settings and CSS.

The user can invite another account and assign an editorial role.

Role boundaries work.

Tenant boundaries have automated tests.

MFA functions.

Network administration is separated from site administration.

Ordinary users cannot install arbitrary executable code.

Public site caching does not leak authenticated content.

Operational logs follow the documented privacy policy.

Site analytics do not require third-party browser tracking.

WXR import functions.

Archive-plus-media import functions.

Migration intake is hardened against malicious archives.

Legacy `/files/` URLs are handled or explicitly reported.

Author mapping functions.

Media existence is validated.

Unsupported migration dependencies are reported.

Imports are resumable or safely retryable.

The final migration report is meaningful.

A migrated site can be tested with the source publication host unavailable.

Users can export their content again.

Backups run.

A restore procedure has been executed successfully.

Read-only emergency mode functions.

Critical CI tests pass.

Security documentation matches actual behavior.

Deployment documentation matches actual behavior.

No production secret exists in the repository.

---

# 71. Final completion report

When implementation is complete, provide a concise engineering report containing:

## Implemented

Major functional areas completed.

## Architecture

Actual deployed/runtime architecture and pinned versions.

## Security

Security boundaries, MFA, tenant isolation, logging policy, secrets handling, and hardening performed.

## Migration

Supported formats, validation performed, known compatibility cases, and migration fixtures tested.

## Tests

Tests executed and their results.

## Operations

Deployment, backups, restores, health monitoring, and read-only mode.

## Remaining limitations

Anything genuinely not implemented or not fully verified.

Do not hide limitations.

Do not label something "production ready" merely because it runs locally.

The final state must be a functioning, tested, reproducible publishing platform rather than a design proposal.