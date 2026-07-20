# Pulse Monitoring (GAP-033)

## Overview

`laravel/pulse` (v1.7.4, installed) provides an operational dashboard —
requests, slow queries/jobs/outgoing requests, exceptions, cache hit rate,
per-user usage, and server resource metrics. It is a diagnostics tool for
operational admins, not an alerting system: it shows current/recent state
when someone looks at it. Automated alerts on thresholds are a separate,
not-yet-built gap (GAP-035) and are intentionally out of scope here.

Pulse does not replace [Queue Monitor](queue-monitor.md) (job-level
inspection and governed retry) or [Scheduler Monitor](scheduler.md)
(scheduled-task run history) — both remain the tools for those tasks.

## MySQL MD5 incompatibility and the SHA2-based `key_hash` replacement

This local MySQL install (Homebrew MySQL 9.7.1) has no working `MD5()` **or**
`SHA1()`/`SHA()` SQL builtins — `SELECT MD5('test')`, `SELECT SHA1('test')`,
and `SELECT SHA('test')` all fail with "FUNCTION ... does not exist", while
`SHA2()` and `CRC32()` work fine. This isn't a config toggle (confirmed:
`log_bin_trust_function_creators` and OpenSSL's legacy-provider theory were
both tried and ruled out) — MySQL 9.7 appears to have dropped this whole
family of weak hash builtins outright. `laravel/pulse`'s own repository has
an unreleased `dev-mysql-9` branch (visible via `composer show -a
laravel/pulse`), confirming this is a known upstream gap, not specific to
this app.

**This is safe to work around, because the hash is opaque to Pulse's own
runtime.** A full read of `vendor/laravel/pulse/src/Storage/DatabaseStorage.php`
confirms `key_hash` (on `pulse_values`, `pulse_entries`, `pulse_aggregates`)
is:

- Written by PHP only for **sqlite** (`requiresManualKeyHash()` returns true
  only when the driver is `sqlite`) — for MySQL/Postgres it is written
  exclusively by the database's own generated-column expression.
- Read only via `GROUP BY key_hash`, `whereColumn('keys.key_hash',
  'aggregated.key_hash')` (a self-join against the *same* table, both sides
  computed by the same expression), and as an `upsert()` conflict-target
  column list — **never** compared against an independently-computed
  literal hash value in a `where('key_hash', ...)` clause. Reads that need
  the plaintext key always filter on the `key`/`type` columns instead.

So the algorithm doesn't need to match MD5 — it only needs to be
deterministic, collision-resistant, and produce the same 16-byte binary
shape the schema expects. `database/migrations/2026_07_20_150026_create_pulse_tables.php`
therefore replaces the mysql/mariadb branch's `unhex(md5(`key`))` with:

```sql
unhex(left(sha2(`key`, 256), 32))
```

— the first 128 bits of a SHA-256 digest, still exactly 16 bytes
(`char(16)` compatible). Verified directly against this MySQL: accepted as
a `VIRTUAL` generated column, deterministic (same key → same hash every
time), different keys → different hashes, and the `UNIQUE` constraint
correctly rejects a duplicate logical key — identical observable behavior
to stock MD5. Confirmed end-to-end with real writes: `pulse:check --once`
recorded a genuine server heartbeat (CPU/memory/storage snapshot +
aggregate buckets) through this schema with no errors.

The pgsql and sqlite branches are stock/unmodified — nothing indicates
either needs the same fix.

### Package-upgrade resilience

**Before upgrading `laravel/pulse`, re-review this customization.**
`php artisan vendor:publish --tag=pulse-migrations` (without `--force`) will
**not** overwrite `database/migrations/2026_07_20_150026_create_pulse_tables.php`,
since Laravel's publisher skips files that already exist at the target path
— an ordinary upgrade will not silently clobber it. Only an explicit
`--force` republish would; do not run that blindly on this file. If a
future Pulse version changes how `key_hash` is read or written (e.g. starts
comparing it against a computed literal, or assumes MD5 specifically),
`tests/Unit/PulseMigrationHashCompatibilityTest.php`'s
`test_pulse_storage_never_compares_key_hash_against_a_computed_literal`
test is designed to fail loudly and flag that this substitution needs
re-evaluation before the upgrade is safe to ship.

## Enabling Pulse

`.env.example` still ships `PULSE_ENABLED=false` — activation is a
deliberate per-environment decision, never an implicit side effect of
deploying this code. This local dev environment's real `.env` now has
`PULSE_ENABLED=true` (migration applied, verified working end-to-end — see
above). To enable in any environment:

1. Set `PULSE_ENABLED=true` in that environment's `.env`.
2. Run `php artisan migrate` (creates `pulse_values`, `pulse_entries`,
   `pulse_aggregates` — additive only).
3. Optionally override any of the keys documented in `.env.example`
   (`PULSE_PATH`, `PULSE_STORAGE_KEEP`, `PULSE_INGEST_KEEP`, the
   `PULSE_SLOW_*_THRESHOLD` keys, `PULSE_SERVER_NAME`,
   `PULSE_SERVER_DIRECTORIES`).

No provider credentials are required — Pulse's `database` storage/ingest
drivers use the application's existing database connection.

## Dashboard access

- Route: `/pulse` (Pulse's own auto-registered route, name `pulse`) — never
  rewritten as a Filament page or embedded in an iframe.
- Protected by Pulse's own `Authorize` middleware, which calls
  `Gate::authorize('viewPulse')`.
- `viewPulse` is defined in `AppServiceProvider::boot()` as
  `Gate::define('viewPulse', [PulsePolicy::class, 'view'])`.
- `PulsePolicy::view()` mirrors `QueueMonitorPolicy`/`SchedulerMonitorPolicy`:
  `super_admin` bypasses via the global `Gate::before()`; everyone else needs
  the dedicated `pulse.view` permission (seeded by
  `PulsePermissionSeeder`, granted to `manager` by default).
- An "Application Performance" link appears in the admin panel's System
  navigation group, visible only when `auth()->user()?->can('viewPulse')` —
  this is a convenience link only; the route's own gate is what actually
  enforces access, so hiding the link is never the security boundary.

## Privacy and data filtering

- **Sensitive routes are excluded from `SlowRequests` and `UserRequests`**
  (config/pulse.php): login, password reset/forgot-password (signed
  tokens in the URL), email verification (signed links), all
  `api/webhooks/*` provider callbacks, the Resend webhook, private
  instructor document downloads, signed Filament export/import download
  links, the platform health check (`/up`), and Pulse's/Telescope's own
  dashboard routes. Metrics for all other routes remain fully visible.
- **User identity is minimized.** Pulse's default user resolver exposes the
  user's email in the Usage card (`Users::find()`'s `'extra'` field). This is
  overridden in `AppServiceProvider::configurePulse()` via `Pulse::user()`
  to return only `name` (falling back to `"User #<id>"` if unset) — no
  email, phone, billing, or KYC data. No `$user->profile` relation is
  loaded, to avoid N+1 queries per Usage-card row. Pulse's own resolver
  already falls back to a safe `"ID: $key"` label when the user is
  null/deleted, so no additional null-handling was needed.
- **No raw query bindings/bodies are stored.** Confirmed from Pulse's own
  `SlowQueries` recorder source: it records `$event->sql` (the parameterized
  template with `?` placeholders) and never `$event->bindings`.
- Recorder exceptions are routed through `Pulse::handleExceptionsUsing()` to
  the app's normal `report()` pipeline — Pulse already wraps all recorder
  logic in its own internal `rescue()`, so a recorder failure can never break
  a request or job; this just makes swallowed recorder failures visible in
  normal application logs instead of silently disappearing.

## Recorders, thresholds, and retention

Conservative v1 defaults (all environment-overridable, see `.env.example`):

| Recorder | Threshold/rate |
|---|---|
| SlowRequests / SlowQueries / SlowJobs / SlowOutgoingRequests | 1000ms |
| CacheInteractions / Exceptions / Queues / SlowJobs / SlowOutgoingRequests / SlowQueries / SlowRequests / UserJobs / UserRequests | sample_rate 1 (no sampling) |
| Storage trim (`pulse_*` tables) | 7 days (`PULSE_STORAGE_KEEP`) |
| Ingest trim | 7 days (`PULSE_INGEST_KEEP`) |

Retention is handled entirely by Pulse's own built-in trim lottery — no
custom purge job was added. `pulse:clear`/`pulse:purge` (destructive, wipes
all Pulse data) must never be run as part of normal operation.

## Server metrics: `pulse:check`

`pulse:check` is a long-running process (like a queue worker) that emits
server-level metrics (CPU/memory/storage) for the Servers card. A single
`php artisan pulse:check --once` was run during Phase 24O.1 to verify the
schema handles real server-shaped writes end-to-end (confirmed: a real
heartbeat with CPU/memory/storage values was recorded and read back
successfully). No daemon or Supervisor process was started — activating it
continuously is a
deployment-time decision for each server you want represented in that card.

A version-controlled Supervisor template is provided at
[docs/deployment/pulse-check.conf.example](deployment/pulse-check.conf.example)
with placeholders only (`<APP_PATH>`, `<APP_USER>`, `<LOG_PATH>`) — copy it to
the real server's Supervisor config directory and fill in real values there.

## Production deployment sequence

Pulse must never be enabled before its storage migration has succeeded —
the sequence below keeps those ordered explicitly rather than relying on
migrate/enable happening to run in a safe order:

1. Keep `PULSE_ENABLED=false` for this deploy (don't flip it yet).
2. Deploy the code — the migration is a normal, discoverable file at
   `database/migrations/2026_07_20_150026_create_pulse_tables.php`, nothing
   special required to ship it.
3. Run `php artisan migrate`.
4. Verify all three Pulse tables/indexes exist (`migrate:status`; see
   `tests/Unit/PulseMigrationHashCompatibilityTest.php` for the exact
   checks used in CI/locally).
5. Configure this environment's Pulse values in `.env` (`PULSE_PATH`,
   retention keys, thresholds, `PULSE_SERVER_NAME`/`DIRECTORIES` — see
   `.env.example`).
6. Set `PULSE_ENABLED=true`.
7. Run `php artisan config:cache` (and `route:cache`/`view:cache` per your
   normal deploy process).
8. Verify an authorized operational admin can load `/pulse` and an
   unauthorized user still gets denied.
9. Install/start the supervised `pulse:check` process (see the Supervisor
   template below) if this server should appear in the Servers card.
10. Verify a heartbeat/benign metric appears (e.g. `pulse:check --once`
    once, or wait for the supervised process's first beat).
11. On later deploys, run `php artisan pulse:restart` if `pulse:check` is
    running on this server — this writes a cache-based signal that
    `pulse:check` polls for and exits cleanly on; Supervisor's
    `autorestart=true` brings it back up with the new code/config.
    Confirm the cache driver (`CACHE_STORE`) persists across processes —
    `database`, `redis`, `memcached`, and `file` all work; `array` does not
    (harmless in tests, since no daemon runs there).

## Key files

| File | Purpose |
|---|---|
| `config/pulse.php` | Recorders, thresholds, storage/ingest, sensitive-route filters |
| `app/Policies/PulsePolicy.php` | `view()` — backs the `viewPulse` gate |
| `database/seeders/PulsePermissionSeeder.php` | Seeds `pulse.view`, grants to `manager` |
| `app/Providers/AppServiceProvider.php` | `Gate::define('viewPulse', ...)`, `configurePulse()` (user resolver + exception routing) |
| `app/Providers/Filament/AdminPanelProvider.php` | Permission-gated "Application Performance" nav link |
| `docs/deployment/pulse-check.conf.example` | Supervisor template for `pulse:check` (placeholders only, never started) |
| `database/migrations/2026_07_20_150026_create_pulse_tables.php` | Customized migration (SHA2-based `key_hash` for mysql/mariadb — see above) |
| `tests/Unit/PulseMigrationHashCompatibilityTest.php` | Verifies the hash substitution's safety + schema/index correctness |
| `tests/Feature/Admin/PulseStorageCompatibilityTest.php` | Exercises the real Pulse storage API (record/aggregate/trim) with benign data |
