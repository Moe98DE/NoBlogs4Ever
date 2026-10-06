# NoBlogs4Ever — Product specification

> **About this document.** This specification defines the intended scope, behaviour and quality bar of NoBlogs4Ever. It is the reference for what the platform is meant to do; see [Project status](../getting-started/project-status.md) for what is implemented today. Section numbers are stable and are referenced elsewhere in the documentation (e.g. "spec §20").

**NoBlogs4Ever** is a secure, privacy-oriented, multi-tenant website and publishing platform. The deliverable is a functioning product, not a prototype: the application, its deployment architecture, its migration system, its security boundaries, its automated tests and its operator documentation are all in scope.

## Conventions

* **must / must not** — a requirement; the product is not complete without it.
* **should** — expected behaviour; deviations are documented with a reason.
* **may** — optional behaviour.

Where this specification leaves a detail open, the safest, simplest and most maintainable option consistent with it is chosen, and the decision is documented (see §68).

---

# 1. Product objective

NoBlogs4Ever is a centrally managed publishing service that lets individuals and small groups create and run websites without administering servers, databases, PHP installations, plugins, certificates or operating systems themselves.

The platform emphasizes:

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

A normal user can:

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

The service should feel like a managed publishing platform, not a VPS control panel.

---

# 2. Architectural foundation

The compatibility and publishing core is **WordPress Multisite**. This is a deliberate choice; a custom CMS is out of scope. The reasons are:

- WordPress-compatible content semantics are part of the target behaviour.
- WXR import/export compatibility is critical.
- WordPress roles are part of the target collaboration model.
- Gutenberg/block editing is part of the target UX.
- Classic editing must remain possible.
- WordPress feeds, comments, taxonomies, revisions, scheduling, themes and media behaviour are desirable compatibility surfaces.
- Existing migration archives are expected to originate from WordPress Multisite environments.

Multisite runs in **subdomain mode**. The default tenant URL model is:

`https://<site-slug>.<platform-domain>`

The base platform domain is configurable and never hard-coded. Development environments may use a local domain convention.

---

# 3. Deployment architecture

The deployment is reproducible. At minimum it comprises:

- a TLS-capable reverse proxy;
- WordPress Multisite;
- a PHP runtime;
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

Containers are used where they aid reproducibility. A local development environment can be launched with a small number of documented commands.

The architecture is conventional and maintainable; exotic infrastructure (e.g. Kubernetes) is not introduced without a concrete need.

Production secrets are kept separate from source-controlled generic configuration. Runtime and application dependencies are pinned to supported versions, upgrades are deliberate and reproducible, and the versions actually deployed are recorded rather than assumed.

---

# 4. Centralized software-management model

Tenants do not receive arbitrary server-side code execution. Ordinary site administrators cannot:

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

Software is curated centrally by the network operator, who controls:

- plugin availability;
- theme availability;
- must-use plugins;
- network settings;
- security configuration;
- the upgrade workflow.

Site administrators have substantial control over their own content and presentation, but not over the execution environment.

---

# 5. Tenant boundaries

Every site is a distinct security tenant, even though the application runtime is shared. The tenant boundary covers:

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

A user who administers Site A never gains Site B privileges merely because both sites exist in the same Multisite network. Cross-site authorization failures are covered by explicit automated tests.

Network super-administration is an operator role, separate from normal site administration. The implications of application-level multi-tenancy are documented in the threat model.

---

# 6. Account model

A single network identity can belong to one or more sites. A user can:

- register an account;
- create a site during registration, where permitted;
- register an account without immediately creating a site;
- create additional sites later, where policy permits;
- belong to multiple sites;
- hold different roles on different sites;
- update profile details;
- change password;
- configure a recovery email;
- enable multi-factor authentication;
- generate and regenerate recovery codes;
- securely terminate active sessions.

Username validation uses conservative, WordPress-compatible semantics.

Registration is governed by a configurable policy rather than a hard-coded list of email providers. Supported modes:

- allowlisted domains;
- denylisted domains;
- unrestricted registration;
- invitation-only registration;
- administrator approval.

The operator can change the policy without changing application code.

---

# 7. Authentication security

Authentication is suitable for a privacy-sensitive publishing service. Supported factors:

- password;
- TOTP;
- WebAuthn/passkeys or hardware security keys;
- one-time backup/recovery codes.

Email-based verification may exist as a recovery mechanism; TOTP or hardware-backed factors are preferred for MFA.

Requirements:

- secure password hashing using the supported WordPress mechanism;
- strong session-cookie settings;
- HTTPS-only production sessions;
- SameSite protections appropriate to WordPress functionality;
- CSRF protection;
- login rate limiting;
- account-enumeration resistance where practical;
- secure password-reset tokens;
- configurable session expiration;
- session revocation;
- an MFA recovery flow;
- an MFA enforcement option for network administrators.

The following are never logged:

- plaintext passwords;
- MFA secrets;
- recovery codes;
- password-reset tokens;
- authentication cookies.

Secrets are never committed to source control.

---

# 8. Roles and collaboration

The platform uses the conventional WordPress editorial roles.

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

Controls the assigned site within network restrictions. Has no control over network software.

## Editor

Publishes and manages editorial content, including content created by other authors.

## Author

Publishes and manages their own posts.

## Contributor

Prepares their own posts but cannot publish them independently.

## Subscriber

Basic profile and site access appropriate to the site.

Roles follow least privilege, and the UI makes the scope of each role understandable. The product steers groups towards individual accounts rather than a single shared password.

---

# 9. Site creation

Site creation is a simple, self-service flow. Users with permission provide:

- site slug;
- site title;
- optional description/tagline;
- preferred interface language.

Site slugs are validated carefully, rejecting:

- reserved hostnames;
- infrastructure names;
- misleading operator names;
- malformed Unicode;
- invalid DNS labels.

Provisioning is automated: creating a site sets up its WordPress tenant state without operator shell commands. Site creation is subject to abuse and rate controls.

---

# 10. Administration experience

Site administration remains recognizably WordPress, with the conventional areas:

- Dashboard;
- Posts;
- Pages;
- Media;
- Comments;
- Appearance;
- Users;
- Tools;
- Settings;
- Analytics, where enabled.

Established WordPress concepts are not redesigned without reason; the goal is familiarity and low migration friction.

Operator-specific functionality is integrated into the WordPress dashboard rather than shipped as a separate administration product, unless a strong security reason requires otherwise.

---

# 11. Editorial features

The platform supports the standard publishing concepts:

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

WordPress URL behaviour is preserved where practical. Publication timestamps are never silently changed during migration.

---

# 12. Block and Classic editing

The WordPress block editor is the default editing interface. Sites that need it have a supported way to use the Classic Editor.

The block editor supports ordinary:

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

The existence of an HTML-oriented block does not give ordinary authors arbitrary JavaScript execution. Untrusted markup is sanitized according to role and WordPress security semantics.

---

# 13. Themes and presentation

The platform offers a curated theme catalog that includes:

- a modern block-theme experience;
- at least one conventional/classic WordPress theme.

Users customize sites through the workflows supported by the selected theme. Where applicable this includes:

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

Additional CSS is sanitized. Tenants can customize presentation but cannot upload executable PHP themes.

---

# 14. Media system

Conventional WordPress media workflows are supported, at minimum:

- image upload;
- document attachments;
- audio attachments;
- video attachments, subject to the configured resource policy;
- gallery creation;
- featured images;
- image derivatives/thumbnails;
- alt text;
- captions;
- attachment metadata.

ODS files are accepted as ordinary downloadable attachments when the configured MIME policy permits. Office files are treated as attachments, not as collaborative documents.

Upload limits are operator-configurable; there is no fixed historical quota. Configurable limits cover:

- maximum file size;
- allowed MIME types;
- total site storage, where enabled;
- image-processing constraints.

Both MIME type and extension are validated, and uploads are protected against malformed files and executable-file abuse.

---

# 15. External embeds

Safe external embedding is permitted where WordPress supports it, including common media URLs. Controlled iframe support is available for approved use cases, such as map embeds, where safe. An "HTML block" is never treated as permission for unrestricted script execution.

The documentation explains that externally embedded resources:

- can disappear independently;
- can disclose reader metadata to third parties;
- are not backed up automatically;
- may have their own privacy policies.

---

# 16. Multilingual publishing

The platform supports editorial multilingual publishing: separate language versions of content and a language-switching mechanism. Automatic machine translation is not a substitute for this.

Multilingual support comes from a centrally curated and maintained WordPress solution compatible with Multisite and the selected editing experience. Language is configurable per site.

---

# 17. Comments and moderation

Conventional WordPress comment moderation is provided:

- comments enabled/disabled per site;
- per-post controls;
- moderation queue;
- spam handling;
- commenter identity fields according to site settings;
- rate limiting;
- abuse protections.

No third-party, tracking-based comment service is enabled by default.

---

# 18. Feeds and discovery

Public sites expose conventional syndication feeds.

A modest network-level discovery layer for public sites provides:

- recent public activity;
- topic/category-oriented discovery;
- a site directory where appropriate.

Discovery never exposes:

- private sites;
- drafts;
- restricted content;
- unlisted tenant information.

Discovery is built on documented, maintainable application behaviour rather than on assumptions about any historical search implementation.

---

# 19. ActivityPub

Optional ActivityPub integration is provided through a centrally maintained implementation. Each site administrator chooses whether the site participates.

Supported:

- a site/collective identity;
- author-oriented identities where supported;
- a follower model;
- inbound interaction/reaction handling;
- moderation controls.

The user interface explains an important limitation: federated public content can be copied to remote servers, and local deletion cannot guarantee deletion of remote copies.

Federation identity is tied to hostname and actor identity. Configuration is designed with future hostname migration in mind where technically possible, without promising seamless identity transfer where the protocol or remote implementations do not allow it.

---

# 20. Mastodon/social autopost integrations

Outbound posting to social accounts is separate from ActivityPub federation. If implemented, it uses an explicit OAuth-style authorization model with:

- tokens encrypted at rest where practical;
- tokens never exposed in logs;
- a user-visible list of connected accounts;
- an explicit disconnect/revoke operation;
- graceful handling of expired authorization;
- least-privilege scopes.

Deleting a local post is not presented as revoking an external application's authorization.

---

# 21. Encrypted contact forms

An optional encrypted-contact workflow lets a site publish a contact form whose message content is encrypted for the intended recipient.

The feature is described as end-to-end encrypted only if content is encrypted in the visitor's browser with a recipient-controlled public key before it reaches application infrastructure; otherwise it is described more narrowly.

Requirements:

- recipient public-key configuration;
- key fingerprint display;
- validation of malformed keys;
- protection against accidental plaintext logging;
- a clear key replacement/rotation workflow;
- safe handling of message metadata;
- abuse/rate controls;
- CSRF protection where applicable;
- no plaintext content in application logs.

The documentation states exactly which metadata the platform can still observe.

---

# 22. Privacy model

Privacy is a core product property.

- Data collection is minimized.
- No third-party advertising or behavioural analytics are installed.
- Ordinary reader IP addresses are not stored persistently.
- Author IP addresses are not stored persistently for routine publishing.
- Where IP-based abuse controls are required, short-lived in-memory or privacy-preserving rate-limit state is used.
- Operational diagnostics are designed so that the service does not become a request-history database.

The documentation states exactly what is collected.

---

# 23. Privacy-preserving analytics

Optional site analytics are derived from server-side data, not from a browser tracking script. They focus on aggregate information such as:

- page views;
- approximate unique-visit measures, only if achievable without persistent identifying profiles;
- URL/path;
- broad browser class;
- broad device class;
- human versus automated request classification.

Analytics never:

- create persistent cross-site visitor identifiers;
- fingerprint users;
- use advertising IDs;
- load third-party analytics JavaScript.

Retention is configurable and short. Analytics belonging to Site A are not visible to Site B.

---

# 24. Logging

Four kinds of data are distinguished and handled separately:

1. security/application event logs;
2. operational diagnostics;
3. aggregate metrics;
4. user-facing analytics.

Logging is structured, with sensitive fields redacted or excluded. The following are never logged:

- passwords;
- password hashes (unless strictly necessary);
- session cookies;
- API bearer tokens;
- OAuth secrets;
- MFA seeds;
- recovery codes;
- PGP private keys;
- full contact-form plaintext.

Log retention is configurable, defaults to short periods, and the retention policy is documented.

---

# 25. Security headers and edge behaviour

Production HTTPS responses carry appropriate modern security headers, configured and verified for compatibility with WordPress administration, embeds, federation and other required functionality:

- HSTS;
- X-Content-Type-Options;
- Content-Security-Policy;
- Referrer-Policy;
- a framing policy via CSP `frame-ancestors` or an equivalent;
- secure cache behaviour for authentication/admin responses.

Authentication and private administrative pages are never publicly cached.

---

# 26. Caching

Public sites may use reverse-proxy/application caching. Administrative and authenticated traffic is isolated so that cached authenticated content can never leak between users.

- Cache keys and bypass rules are documented.
- Caches are invalidated after content is published or updated.
- Health checks are exposed separately from public page caching, since a public HTTP 200 response does not imply a healthy backend.

---

# 27. REST/API behaviour

WordPress REST interfaces are exposed only according to an explicit security policy. The documentation covers:

- unauthenticated endpoints;
- authenticated endpoints;
- write permissions;
- CORS policy;
- authentication methods;
- rate limits.

Powerful features such as Application Passwords and XML-RPC are not enabled without evaluation against the threat model. If enabled, the reason is documented and their authorization boundaries are tested.

---

# 28. No arbitrary application hosting

NoBlogs4Ever is a publishing platform, not a general application-hosting environment. Tenant users do not receive:

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

# 29. Migration system

Migration is a first-class subsystem. A dedicated workflow imports WordPress and NoBlogs-style exports, supporting at minimum:

1. standard WordPress WXR/XML;
2. an uploaded archive containing WXR plus media;
3. reasonable variations of archive directory layout.

The importer discovers the archive's structure rather than assuming a single undocumented layout.

---

# 30. Migration intake safety

Uploaded migration archives are untrusted input. Before extraction, the platform:

- calculates a strong checksum;
- stores the untouched original;
- records the file size;
- creates a migration record;
- inspects the archive format.

Intake defends against:

- path traversal/Zip Slip;
- absolute paths;
- symlinks escaping extraction directories;
- decompression bombs;
- excessive file counts;
- oversized files;
- malformed XML;
- entity-expansion attacks;
- dangerous MIME mismatches.

Code contained in an imported archive is never executed. Theme or plugin PHP in an archive does not become executable by being uploaded.

---

# 31. Migration inventory

Before any imported data is transformed, an inventory is built recording:

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

The inventory is shown to the user as part of the migration report.

---

# 32. WXR import behaviour

The importer preserves, wherever technically possible:

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

Unknown custom post types are reported, never silently discarded.

---

# 33. Author mapping

Imported authors are not assumed to correspond to existing platform accounts. A mapping step allows:

- mapping an imported author to an existing account;
- creating/inviting a new account;
- mapping several imported authors to one account when intentionally requested;
- temporarily assigning content to the importing administrator.

Old password hashes are not imported as usable credentials. Imported users establish fresh authentication and recovery credentials.

---

# 34. Media migration

Media migration verifies that the actual binary is available; an attachment record in the XML alone does not count as a migrated attachment.

The importer handles:

- bundled media;
- referenced remote media;
- image derivatives;
- galleries;
- featured images;
- attachment links;
- source-domain URLs.

Local copies in the archive take precedence. If remote fetching is supported, it is explicit and its outcome is recorded. A successful migration never depends on the source host remaining online.

---

# 35. Legacy `/files/` URL compatibility

The importer detects legacy WordPress Multisite media references of the form `/files/...` and historical site-specific files-directory layouts, and handles them with a compatibility layer or a deterministic URL rewrite.

A source URL is rewritten only after the replacement asset is confirmed to exist locally. An auditable mapping between old and new paths is kept. Old-style attachment URLs are covered by the migration fixtures.

---

# 36. Migration of presentation state

The importer attempts to preserve:

- the active theme, where it exists in the curated catalog;
- custom CSS;
- menus;
- widgets;
- Site Editor patterns, where the export data permits;
- supported theme settings.

Missing configuration is never fabricated. If the original theme is unavailable, no replacement is chosen silently; instead the platform:

1. reports the unavailable theme;
2. offers a safe curated fallback;
3. preserves recoverable content;
4. identifies presentation differences.

---

# 37. Shortcodes and custom blocks

Imported content is scanned for shortcodes and custom blocks, and every unsupported dependency appears in the migration report.

Where feasible, compatibility renderers are provided for commonly expected, centrally supported functionality. Raw shortcode markup detected during migration is not shown to readers without a warning to the site owner. Unknown plugins from an import archive are never installed.

---

# 38. Integration migration boundaries

Importing content does not imply migrating external credentials. The following are handled separately:

- Mastodon/social tokens;
- API credentials;
- PGP private keys;
- contact-form key configuration;
- federation identity;
- application passwords;
- OAuth connections;
- external-service passwords.

These are not assumed to be part of a WXR file, and users reconnect external accounts after migration. Where public PGP keys or other non-secret configuration can be imported safely, the behaviour is explicit. Secrets in an archive are never imported blindly.

---

# 39. Source independence

A migrated site is complete only when it functions without access to the source host. A validation phase identifies anything still depending on the old source domain, checking:

- images;
- gallery assets;
- CSS references, where applicable;
- internal links;
- attachment URLs;
- feeds;
- canonical URLs;
- embeds;
- legacy `/files/` paths.

Resources intentionally hosted elsewhere are distinguished from accidental dependencies on the old publication host.

---

# 40. Idempotent migration

Imports are resumable and safe to retry; restarting a job never duplicates content. Import state is persisted, and deterministic source identifiers are used where possible.

The migration pipeline provides:

- queued/background processing;
- progress state;
- resumability;
- failure reporting;
- partial-import visibility;
- retry of failed media;
- final validation.

Large imports do not depend on a single HTTP request remaining open.

---

# 41. Migration report

Every completed migration produces a report containing:

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

The operator and the user can retain or download the report.

---

# 42. Export

Standard WordPress export remains available, and users can export their publication without operator intervention. The platform creates no artificial lock-in. The documentation states what a WXR export does and does not include.

Where practical, an enhanced site-backup export contains:

- WXR content;
- uploaded media;
- safe site configuration;
- custom CSS;
- menu information;
- a migration manifest;
- checksums.

Portable exports never contain credentials, password hashes, private keys, OAuth tokens or application secrets.

---

# 43. Deletion

Site deletion is an intentional workflow with:

- explicit user confirmation;
- appropriate authorization;
- CSRF protection against accidental deletion;
- a recommendation to export first;
- a clear explanation of retention implications;
- a distinction between deletion and immediate erasure from backups or remote federated copies.

The platform does not claim that deleting a federated post guarantees its removal from remote servers.

---

# 44. Backups

Production backups cover at minimum:

- the database;
- uploaded media;
- configuration needed to recreate the service;
- encrypted storage of backup material;
- a retention policy;
- restoration documentation.

A backup capability includes a restore procedure, and that procedure is tested in an automated or reproducible environment. RPO/RTO figures are stated only once the implemented backup process has been shown to support them.

---

# 45. Recovery

Documented recovery procedures cover:

- database corruption;
- accidental site deletion;
- failed upgrade;
- lost application node;
- lost media storage;
- compromised network administrator account.

Deployment infrastructure is designed to be replaceable rather than manually reconstructed.

---

# 46. Updates and dependency management

The network operator owns application updates, through a controlled workflow for:

- WordPress core;
- PHP;
- the database;
- themes;
- plugins;
- Composer dependencies;
- container images;
- operating-system packages where relevant.

Versions are pinned through lockfiles, each release is identifiable, and deployed versions are recorded — a dependency manifest alone is not treated as proof of what production runs. Rollback instructions are provided.

---

# 47. Security engineering

The threat model covers at least:

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
- password-reset abuse;
- session theft;
- migration archive attacks;
- malicious XML;
- dependency compromise;
- leaked OAuth token;
- leaked backup;
- server compromise.

Where WordPress provides a secure standard mechanism, it is used rather than custom cryptography or authorization systems.

---

# 48. Operator-security separation

Sensitive network-administration functionality is hard to reach accidentally from ordinary tenant accounts.

- MFA is required for production network super administrators.
- Operators use separate, named accounts; shared super-admin credentials are not used.
- Security-sensitive network actions are audited without recording reader identities unnecessarily.

---

# 49. Abuse controls

Controls appropriate to public registration and publishing include:

- registration rate limiting;
- login rate limiting;
- password-reset rate limiting;
- site-creation rate limiting;
- comment abuse controls;
- contact-form abuse controls;
- upload limits;
- email-send limits.

These controls do not rely on indefinite storage of identifiable IP histories.

---

# 50. Email

Outbound transactional email is abstracted through configuration, so the service is not tied to one provider. It covers:

- account verification, where used;
- password reset;
- invitations;
- administrative notifications;
- site-deletion confirmation, if used;
- migration completion/failure notices, where useful.

Development uses a safe local email sink so that production mail cannot be sent by accident.

---

# 51. Accessibility

Defaults are accessible. Custom platform UI supports:

- keyboard operation;
- proper labels;
- semantic headings;
- visible focus;
- reasonable contrast;
- descriptive validation errors.

WordPress's own accessibility work is preserved rather than replaced by custom components. Full WCAG conformance is claimed only if it has actually been evaluated.

---

# 52. Responsive behaviour

Administration and site usage work on contemporary mobile and desktop browsers. Complex site design may be less convenient on a phone, but basic editorial tasks remain usable. Critical flows are tested at multiple viewport sizes.

---

# 53. Performance

Performance targets are engineering targets set and documented for this implementation, not historical figures. The platform ensures that:

- public cached pages are efficient;
- admin actions are not incorrectly cached;
- large imports run asynchronously;
- image processing cannot trivially exhaust workers;
- expensive discovery/search queries are bounded;
- federation jobs do not block user-facing HTTP requests where avoidable.

Basic load/performance tests cover critical paths. Throughput figures are published only once measured.

---

# 54. Observability

Operators have visibility without excessive surveillance. At minimum:

- application health;
- database health;
- queue/background-job health;
- disk/storage pressure;
- error rate;
- latency aggregates;
- failed migrations;
- failed scheduled tasks;
- backup status.

Metrics avoid unnecessary user-identifying dimensions.

---

# 55. Development environment

The documented local environment can exercise:

- wildcard/subdomain Multisite routing;
- tenant creation;
- HTTPS or a documented development equivalent;
- email;
- media upload;
- background jobs;
- migration;
- federation-related code, where feasible;
- backups.

Seed/demo data includes at least one network administrator, two independent sites, multiple site roles, and representative posts, media and comments — the minimum needed for tenant-isolation testing.

---

# 56. Testing requirements

The automated test suite includes:

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
- MFA setup, where automation is reasonable;
- site creation;
- publishing an article;
- editing an article;
- uploading media;
- adding a collaborator;
- export;
- import;
- verifying migrated content.

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

Realistic migration fixtures cover:

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
- an unsupported shortcode;
- an unsupported block;
- old source-domain internal links.

Tests assert the expected import outcomes, not merely the absence of errors.

---

# 58. CI

Continuous integration runs at minimum:

- formatting/lint checks for custom code;
- static analysis where appropriate;
- unit tests;
- integration tests;
- security-sensitive migration tests;
- build/package validation.

CI cannot report success if critical test suites were silently skipped.

---

# 59. Documentation

The project maintains documentation covering:

- an overview (`README.md`);
- architecture;
- development;
- deployment;
- the security model;
- the threat model;
- privacy;
- migration;
- backup and restore;
- the operator runbook;
- upgrades;
- incident response.

File names and layout follow the repository's documentation structure (see [SUMMARY.md](../SUMMARY.md)).

---

# 60. Architecture documentation

The architecture documentation describes:

- the request path;
- the reverse proxy;
- the PHP/application layer;
- the database;
- media storage;
- queue/background work;
- caching;
- email;
- backups;
- secrets;
- the tenant model;
- trust boundaries.

It distinguishes logical architecture from assumptions about physical infrastructure.

---

# 61. Privacy documentation

The privacy documentation states clearly:

- what request data is collected;
- what logs exist;
- what is intentionally not collected;
- log retention;
- analytics behaviour;
- backup retention;
- external embeds;
- federation privacy implications;
- email metadata implications;
- contact-form encryption boundaries.

It makes narrow claims that are technically true and avoids absolute statements such as "we log nothing" when operational data is retained.

---

# 62. Incident response

An operator procedure for a suspected application compromise covers:

- read-only emergency mode;
- preservation of evidence;
- credential reset;
- network-administrator credential rotation;
- session invalidation;
- OAuth token review;
- the affected-user notification workflow;
- dependency investigation;
- restoring from a known-good state;
- verifying remediation before restoring writes.

HTTP 200 responses are not treated as evidence that the publication system is healthy.

---

# 63. Read-only emergency mode

A network-level emergency read-only mode exists for incident handling and maintenance. When active:

- public cached/site content remains readable where safe;
- ordinary publication writes are blocked;
- the admin UI clearly explains the state;
- sensitive administrative remediation remains available only where appropriate;
- background jobs that produce public changes are controlled.

The mode is covered by automated tests.

---

# 64. Product branding

The product name is **NoBlogs4Ever**.

Branding configuration is kept separate from application internals where practical, and product/domain strings are not scattered through custom code. The base domain, support addresses, registration policy, retention values, quotas and operator-facing URLs are configurable.

---

# 65. Non-goals for the initial release

The following are not required for the initial release unless already present:

- arbitrary custom domains;
- tenant-controlled server code;
- paid subscriptions;
- commercial billing;
- a guaranteed uptime SLA;
- a guaranteed fixed storage quota;
- geographically distributed database replication;
- a specific database-sharding algorithm;
- Kubernetes;
- direct database exports;
- general-purpose application hosting.

The architecture leaves room for reasonable extensions, but no compatibility claims are made for these features.

---

# 66. Delivery phases

The product is delivered in the following phases.

## Phase 1 — Repository and architecture

- implementation plan;
- reproducible environment;
- dependency management;
- Multisite setup;
- wildcard development routing;
- database and persistent media;
- CI skeleton.

## Phase 2 — Identity and tenant isolation

- account lifecycle;
- site creation;
- role behaviour;
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
- Site Editor/Customizer behaviour;
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
- large-job resume/retry behaviour.

## Phase 7 — Integrations

- multilingual publishing;
- ActivityPub;
- external social autopost, where included;
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

# 67. Engineering practices

Each milestone is delivered as a small, coherent vertical slice: implemented, covered by relevant tests, checked against the broader regression suite, and documented before the next one begins. Large untested change sets are avoided.

Failing tests are fixed in the code, not by removing meaningful assertions, and security controls are never disabled to make tests pass.

---

# 68. Design priorities

Where this specification leaves a detail open, decisions are made in this order of priority:

1. security;
2. user data preservation;
3. compatibility;
4. privacy;
5. operational simplicity;
6. maintainability;
7. performance;
8. feature novelty.

Meaningful architectural decisions are documented. Where compatibility behaviour is unknown, it is documented as such rather than invented.

---

# 69. Release blockers

The product is not considered complete while any of the following is true:

- migration is only a placeholder;
- imports lose media without reporting it;
- tenant-isolation tests do not exist;
- backups exist but restore has never been tested;
- arbitrary users can install server-side plugins;
- secrets are committed;
- MFA appears in the UI but does not function;
- critical tests are failing;
- the deployment cannot be reproduced from the documentation;
- imported sites still unknowingly depend on the source host;
- major security TODOs remain in production-critical paths;
- the documentation claims properties the implementation does not have.

---

# 70. Release criteria

The product is ready for an initial production deployment when all of the following hold:

- A new operator can deploy the service by following the documentation.
- A user can create an account.
- A user can create a site, which receives a tenant subdomain.
- The user can publish with the block editor.
- The user can use the supported Classic Editor workflow.
- The user can upload media.
- The user can choose from curated themes.
- The user can change supported presentation settings and CSS.
- The user can invite another account and assign an editorial role.
- Role boundaries work.
- Tenant boundaries have automated tests.
- MFA functions.
- Network administration is separated from site administration.
- Ordinary users cannot install arbitrary executable code.
- Public site caching does not leak authenticated content.
- Operational logs follow the documented privacy policy.
- Site analytics do not require third-party browser tracking.
- WXR import functions.
- Archive-plus-media import functions.
- Migration intake is hardened against malicious archives.
- Legacy `/files/` URLs are handled or explicitly reported.
- Author mapping functions.
- Media existence is validated.
- Unsupported migration dependencies are reported.
- Imports are resumable or safely retryable.
- The final migration report is meaningful.
- A migrated site works with the source publication host unavailable.
- Users can export their content again.
- Backups run.
- A restore procedure has been executed successfully.
- Read-only emergency mode functions.
- Critical CI tests pass.
- Security documentation matches actual behaviour.
- Deployment documentation matches actual behaviour.
- No production secret exists in the repository.

---

# 71. Release reporting

Each release is accompanied by an engineering summary, maintained in [Project status](../getting-started/project-status.md) and the [changelog](../../CHANGELOG.md), covering:

- **Implemented** — major functional areas completed.
- **Architecture** — the actual runtime architecture and pinned versions.
- **Security** — security boundaries, MFA, tenant isolation, logging policy, secrets handling and hardening.
- **Migration** — supported formats, validation performed, known compatibility cases and fixtures tested.
- **Tests** — tests executed and their results.
- **Operations** — deployment, backups, restores, health monitoring and read-only mode.
- **Remaining limitations** — anything not implemented or not fully verified.

Limitations are stated openly, and nothing is labelled "production ready" merely because it runs locally.
