# Upgrade Guide

Torque keeps upgrades boring: the master records the installed version in Redis (`{prefix}version`) and, on the first `torque:start` after a deploy, runs the data steps for every version between the recorded one and the installed one. Nothing is required from you unless a section below says so. `torque:status` shows the recorded data version next to the installed one.

## 0.16.x to 0.17.0

### Automatic

- Nothing to migrate. The per-host rollups are new keys, so an installation simply starts collecting them on the first `torque:start` after the deploy; until then the workers screen shows its live columns and an empty history.
- The dashboard's time range moves into the topbar and is remembered per session. Nothing persists server-side beyond the session, so there is no stored state to convert.

### New config keys (all optional, defaults apply when absent)

| Key | Default | What it does |
| --- | --- | --- |
| `dashboard.default_range` | `'1h'` | Range the dashboard opens on: `1h`, `24h`, `7d` or `90d` (no env var; it is a per-session choice in the topbar) |

### Behaviour changes

- **One range for the whole dashboard.** The 1h / 24h / 7d / 90d control used to sit in two card heads (the overview throughput chart and the jobs table) and drove only that card. It is now a topbar picker next to the refresh interval, remembered for the session, and every screen reads it: the throughput chart and its sparklines, the jobs table, the queues counters, the workers cards, the live feed and the dead-letter list.
- **The queues screen is range-scoped.** `processedToday` / `failedToday` are now `processed` / `failed` over the selected range, `throughput` is jobs per minute across that range instead of a fixed five-minute window, and the per-stream sparkline is finally real: it reads the `metrics:rollup:{tier}:{queue}` history that has always been written but never read, so it survives a reload.
- **The workers screen is grouped by host.** A card per machine instead of a card per worker process, with the live processes listed inside it. Worker ids are re-minted on every start, so a per-process row has no history to range over; the host does. `processed` / `failed` / `throughput` on a host row are range-scoped, while the per-process rows keep the lifetime counters straight off the heartbeat hash. A host that ran inside the range but has no live worker now shows as `gone` with its last-seen time rather than disappearing.
- **The live feed and the dead-letter list honour the range**, applied in Redis (`ZREVRANGEBYSCORE` on the job index, a millisecond-epoch low id on the dead-letter stream). Both sources are bounded by their own retention, so a 90d range shows what retention holds, not a guaranteed 90 days. The dead-letter header shows both the in-range count and the stream total.
- The job inspector has no fleet-wide time dimension, so the picker is not rendered there.

### API changes

- `WorkersData::get(string $range = '1h')` returns `['hosts' => ...]` instead of `['workers' => ...]`; each host row carries its live processes under `workers`.
- `QueuesData::get(string $range = '1h')`; the `processedToday` / `failedToday` keys are now `processed` / `failed`.
- `DeadLetterHandler::list()` and `listBefore()` take an optional `$sinceMs`; new `countSince(int $sinceMs)`.
- `JobStream::recentJobs()` and `JobsData::list()` take an optional `$since` (unix timestamp).
- New on `MetricsPublisher`: `recordHostOutcomes()`, `recordHostGauges()`, `hostsSeen()`, `hostSeriesMulti()`, `hostGaugeSeriesMulti()`, `normaliseHost()`, `tierSeconds()`.
- New `Webpatser\Torque\Dashboard\Support\Range`; `OverviewData::isValidRange()` and `JobMetricsData::isValidRange()` delegate to it.

### Redis side

Three new keys per tier plus an index: `metrics:rollup:{tier}:host` (field `{bucket}:{host}`), `metrics:gauge:{tier}:host` (field `{bucket}:{host}:{metric}`) and the `metrics:hosts` sorted set. Every host shares one hash per tier, so a publish tick costs seven extra round trips whatever the fleet size, but storage grows with the number of distinct hostnames seen inside the retention window: roughly 3 MB per host at the defaults.

On Kubernetes `gethostname()` is the pod name, which is re-minted on every rollout, so that window can hold far more hosts than you have machines. The workers screen stays readable either way (the index is scored by last-seen, so only hosts active inside the selected range are listed), but if that footprint matters, shorten `metrics.rollups.daily_days`.

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
