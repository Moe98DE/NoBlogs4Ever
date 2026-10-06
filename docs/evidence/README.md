# Evidence files

Machine-generated records of what was actually run, kept so that claims in the documentation can be checked. They describe one environment at one point in time; regenerate them rather than editing by hand.

| File | Produced by | Contents |
|---|---|---|
| `runtime-versions.json` | `bash scripts/runtime-evidence.sh` | Exact WordPress, PHP, MariaDB, plugin and theme versions read from the running images, plus image IDs |
| `restore.json` | `make restore-drill` | Result of the last isolated restore drill |
| `performance.json` | `make performance` | Observational latency/throughput sample (not a capacity claim) |

CI uploads fresh copies of these files as the `evidence` artifact of every full-stack run. `make release-check-strict` requires the committed runtime and restore evidence to be complete and consistent with the Dockerfile and Compose versions.
