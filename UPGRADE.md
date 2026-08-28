# Upgrade Guide

Torque keeps upgrades boring: the master records the installed version in Redis (`{prefix}version`) and, on the first `torque:start` after a deploy, runs the data steps for every version between the recorded one and the installed one. Nothing is required from you unless a section below says so. `torque:status` shows the recorded data version next to the installed one.

## 0.15.x to 0.16.0

### Automatic

- One-off cleanup of leftovers the old code never expired: per-job event streams without a terminal expiry, `jobs:active` / `jobs:recent` members pointing at streams that are gone, the dead-letter stream trimmed to the new TTL and cap, consumer names of exited workers, and legacy metric keys. Runs once, logs a count per category through the master logger.
- Ongoing housekeeping every `dead_letter.prune_interval` seconds (default 300), so `dead_letter.ttl` is finally enforced without a scheduler.

Preview it before restarting the fleet if you like:

```bash
php artisan torque:prune --deep --dry-run   # report only
php artisan torque:prune --deep             # clean up now
```

### New config keys (all optional, defaults apply when absent)

Republish the config to get the documented blocks, or add only what you want to change:

```bash
php artisan vendor:publish --tag=torque-config --force
```

| Key | Default | Purpose |
|-----|---------|---------|
| `dead_letter.max_entries` | 100000 | Hard cap on the dead-letter stream, enforced on every write (`0` = TTL only) |
| `dead_letter.prune_interval` | 300 | Seconds between the master's housekeeping runs (`0` = off) |
| `circuit_breaker.*` | enabled | Pause a stream whose jobs fail permanently at a high rate; per-stream override via `streams.<queue>.circuit_breaker`, or `false` to opt out |
| `metrics.retention` | 86400 | Was 3600. Seconds of per-minute history |
| `metrics.rollups.hourly_days` / `daily_days` | 90 / 730 | Long-term rollups, cluster-wide, per stream and per job class |
| `dashboard.gauge_max` | null | Fixed gauge scale in jobs/min; `null` auto-fits the busiest minute of the last hour |

### Behaviour changes

- A stream whose permanent-failure ratio crosses `circuit_breaker.threshold` (default 0.9 over the last 100 outcomes) is paused for `cooldown` seconds. If a stream is expected to fail that hard and you still want it processed, set `'circuit_breaker' => false` on that stream.
- Dead-letter entries beyond `max_entries` are evicted oldest first. They remain in the framework's `failed_jobs` table, which the cap does not touch: schedule `queue:prune-failed --hours=168` for that side.
- The overview gauge shows a damped five-minute jobs/min figure instead of the instantaneous per-second rate times 60. The `throughput` field in the aggregate metrics hash is unchanged; `throughput_1m`, `throughput_5m`, `throughput_smoothed` and `jobs_last_hour` were added next to it.

### API changes (only relevant if you construct these classes yourself)

- `MetricsPublisher::__construct()` takes an optional third argument `settings` (the `torque.metrics` config block). Without it the package defaults apply.
- `MetricsCollector::recordJobCompleted()` / `recordJobFailed()` accept an optional queue name and job class.
- `DeadLetterHandler::__construct()` takes an optional `maxEntries`.

### Recommended on the Redis side

Set `maxmemory` and `maxmemory-policy noeviction` on the Redis instance Torque uses, so a runaway writer gets `OOM command not allowed` instead of the kernel killing Redis.
