# Why NoBlogs4Ever

## Where this comes from

For nearly two decades, NoBlogs was a home for blogs that didn't fit anywhere else: activist collectives, social centres, zines, artists, translators, people writing about their neighbourhoods and their struggles. It was free, it carried no ads, and it was run by people who took their readers' privacy seriously — no tracking, no data sold, no profiles built.

In September 2026 NoBlogs closed. Readers could still see old posts for a short while, but the dashboards were gone, and writers were left with whatever backups they managed to make.

## What this project is

NoBlogs4Ever is a **spiritual successor**: software that recreates what made NoBlogs valuable, packaged so that *other people* can run it.

It started as a plan to host a successor privately. But running a blog service with real privacy — no request logs, encrypted off-site backups, careful security, someone on call — costs more than one person can sustain. So rather than one new host that might also disappear, NoBlogs4Ever gives everything away: a complete, tested platform, documentation for running it, and an importer built specifically to bring old backups back to life.

The idea is simple:

> **If you have the means, you can bring NoBlogs backups back onto the internet.**
> If you have a backup, you can find someone running NoBlogs4Ever — or become that someone.

Many small hosts are harder to silence and less likely to all vanish at once than one big one.

## What we keep

* **Privacy first** — readers aren't logged, tracked, profiled or exposed to third parties by default. What *is* stored is documented honestly, without "we log nothing" marketing.
* **Writers own their work** — import from a backup, export everything again whenever you want, move between hosts freely.
* **Collectives, not customers** — sites are shared by groups with proper roles; nobody needs to share a password.
* **Hosts that small crews can sustain** — one Docker host, tested backups and restores, an emergency read-only switch, clear runbooks.
* **WordPress compatibility** — the editor and export format people already know, so nothing is locked in.

## Made in the EU

NoBlogs4Ever is developed in Germany, with the GDPR in mind from the start. What that does and doesn't mean:

* **Data minimisation by default.** No request logs, no stored IP addresses of readers or commenters, aggregate-only analytics, and short, configurable retention for logs, imports and backups. The [privacy page](../architecture/privacy.md) lists exactly what is stored and for how long.
* **No outside services built in.** There is no telemetry, licence server or vendor cloud, and pages make no third-party requests by default. An instance only talks to the services its operators configure: an SMTP provider and backup storage.
* **Data stays where you put it.** Choose an EU server, EU email provider and EU backup storage, and the instance's data stays in the EU.
* **Self-service export.** Writers can export all their content themselves, at any time, without asking an operator.

It is not a compliance certificate. Each installation's operators are the data controllers for it and remain responsible for its GDPR obligations: a privacy notice, data-processing agreements with their hosting, email and backup providers, answering requests from the people whose data they hold, and reporting breaches (see [Incident response](../operator-guide/incident-response.md)).

The project's own infrastructure is not all in the EU either: the source code, CI and release images are hosted on GitHub, a US service, and WordPress and the other components come from their upstream projects.

## What we are not

NoBlogs4Ever is an independent community project. It is not affiliated with, operated by, or endorsed by the former NoBlogs operators, and it does not host anything itself. Each installation is run by its own operators, under their own responsibility and local law.

## How you can help

* **Run an instance** for your community and tell people former NoBlogs writers are welcome.
* **Share what your backup looks like** (anonymised) so the importer handles it — especially unusual ones.
* **Translate** the interface and the docs.
* **Contribute code** — see [Project status and roadmap](project-status.md).
