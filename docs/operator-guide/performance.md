# Performance qualification

No capacity or throughput figure is claimed for NoBlogs4Ever: results depend entirely on your hardware, data and traffic. These tools let you measure your own installation.

## Request probe

```sh
NBE_PERF_BASE_URL=https://example.org \
NBE_PERF_MEDIA_URL=https://site.example.org/wp-content/uploads/sites/2/2026/10/image.jpg \
NBE_PERF_USERNAME=perf-test-user \
NBE_PERF_PASSWORD_FILE=/root/perf-password \
make performance > perf-$(date +%F).json
```

It runs 60 concurrent public page reads, 20 authenticated dashboard reads, 40 media reads and a 2,000-item WXR parse, and reports requests per second and latency percentiles. Credentials are read from a file and never printed. Record the host's CPU, RAM, disk type, Compose resource limits, database size and whether the client ran on the same host.

## Import workload

For a representative queued import: `NBE_PERF_MIGRATION_JOB_ID=<uuid> make migration-performance` samples CPU, memory and I/O of app, worker and database every five seconds until the job finishes.

## Design notes

* Pages are rendered by PHP on each request; there is no shared page cache yet (see roadmap). Media responses for public files are cacheable by browsers (`max-age=86400`, ETag, range requests).
* Imports, archives and directory rebuilds run in the worker, bounded by `MIGRATION_TIME_BUDGET` per pass, never in a visitor's request.
* The directory index is capped at 500 sites × 5 posts; analytics writes one upsert per counted view.
* Image processing is limited to 40 megapixels per image and GD only.

Increase load gradually and repeat measurements after changing hardware, limits or software versions.
