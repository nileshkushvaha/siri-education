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

## Runtime instability: OPcache interned-strings exhaustion (Phase 24O.2)

After the migration/hash fix above, the live `/pulse` dashboard still
intermittently returned HTTP 500 during real Livewire polling (`POST
/livewire-.../update`), with errors like:

> The script tried to call a method on an incomplete object. Please
> ensure that the class definition "Illuminate\Support\Collection" ...
> was loaded _before_ unserialize() gets called ...

**This was fully independent of the MD5/SHA2 migration work** — it
involves the app's `cache` table (Laravel's own `DatabaseStore`), never
the `pulse_*` tables.

### Evidence classification (Phase 24O.2A revalidation)

Phase 24O.2's report used causal language ("root cause") that ran ahead of
what had actually been demonstrated. Restated precisely, distinguishing
what is proven from what is still a hypothesis pending the Phase 24O.2A
controlled experiment (see below):

- **Proven failure boundary**: Pulse card `Collection` values, serialized
  through the app's database cache (`cache` table, Laravel's
  `DatabaseStore`), intermittently deserialize as `__PHP_Incomplete_Class`
  in the long-running local `php artisan serve` process. Reproduced
  **deterministically** (2nd consecutive Livewire update call for the same
  card, 100% of the time) via real authenticated `update` requests.
- **Proven correlated condition**: at the time of failure, the serving
  process reported `opcache.interned_strings_buffer=8` (MB, PHP's own
  default) with `interned_strings_usage.free_memory: 0` — fully exhausted.
- **Proven mitigation**: routing Pulse's card cache to the `array` store
  (`PULSE_CACHE_DRIVER=array`) avoids serialization entirely and
  eliminated the observed failures (30/30 + 50/50 requests, zero
  failures, against the live server).
- **Unproven hypothesis**: that interned-strings-buffer exhaustion is the
  direct PHP-engine-level cause of the `__PHP_Incomplete_Class` result.
  This was a plausible, evidence-correlated theory, not a demonstrated
  causal mechanism — no controlled experiment isolated the buffer as the
  variable that flips the outcome. Phase 24O.2A's controlled reproduction
  matrix (below) tests this directly.

Also traced end-to-end for completeness: the failing read is
`vendor/laravel/pulse/src/Livewire/Queues.php:35` (`$queues->keys()->...`
— `$queues` comes from `Card::graph()` → `Pulse::graph()` →
`DatabaseStorage::graph()`, cached via Pulse's own
`RemembersQueries::remember()` trait, i.e. plain `Cache::remember()` —
**not** `Cache::flexible()`; that theory was positively excluded: nothing
in this app, Livewire, Filament, or Pulse calls `flexible()`, and the
`illuminate:cache:flexible:created:...` key seen earlier is just
`DatabaseStore::forgetManyIfExpired()`'s routine opportunistic-GC pattern,
applied to every key regardless of whether `flexible()` was ever used for
it). The exact failing cache row's raw bytes were fetched directly from
MySQL and unserialized successfully in an isolated CLI script — the
stored bytes were never malformed. `opcache.enable_cli` is `Off` (PHP's
own CLI SAPI default), so every CLI reproduction attempt and the entire
PHPUnit suite run with OPcache completely disabled, while `php artisan
serve`'s SAPI (`cli-server`) is governed by the regular `opcache.enable`
(`On`) — the only place OPcache is active for this app.

### Controlled causality experiment (Phase 24O.2A) — hypothesis rejected

Four cases, each on its own clean `php -S` process (matching exactly what
`php artisan serve` launches internally) on a separate port, each run 3
times from a fresh process start, with the same bounded workload (1
authenticated `GET /pulse`, 30 sequential Livewire updates on one card, 50
updates distributed across all 10 cards, one bounded 5-way concurrent
`GET /pulse` burst):

| Case | Config | Effective settings (confirmed via live diagnostic) | Result (×3 runs, identical every time) |
|---|---|---|---|
| A | Database Pulse cache, default OPcache config | `opcache.enable=1`, buffer=8MB, `free_memory: 0` | **Fails**: 1/30 sequential ok, 13/50 distributed ok, 18 new incomplete-object errors per run |
| B | Database Pulse cache, `-d opcache.enable=0` (this process only) | `opcache.enable=0` (confirmed disabled) | **Fails identically to A**: 1/30, 13/50, 18 errors per run |
| C | Database Pulse cache, `-d opcache.interned_strings_buffer=32` (this process only) | `opcache.enable=1`, buffer=32MB, `free_memory` 8.25MB→7.86MB (never exhausted, before *and* after the full workload) | **Fails identically to A**: 1/30, 13/50, 18 errors per run |
| D | Array Pulse cache (`PULSE_CACHE_DRIVER=array`), default OPcache config | `opcache.enable=1`, buffer=8MB, `free_memory: 0` (same exhausted buffer as A) | **Succeeds every time**: 30/30, 50/50, 0 errors per run |

**Conclusion: the interned-strings-buffer-exhaustion hypothesis is
rejected by direct controlled experiment.** Per the Phase 24O.2A decision
criteria, causality could only be claimed if B and C succeeded while A
failed — instead, B (OPcache fully off) and C (buffer generously sized
and confirmed never exhausted, even after the full workload) **fail
identically to A** in every one of 3 clean-process replicates each (same
exact 1/30, 13/50, 18-error signature down to the number). The buffer's
exhaustion state has no measurable effect on the outcome. Whatever is
actually happening is specific to the `cli-server` SAPI's
`serialize()`/`unserialize()` handling of `Illuminate\Support\Collection`
under PHP 8.5.7 (this app has not been run against another PHP minor
version to compare) — **not** OPcache in any configuration tested.

The array-store mitigation (Case D) remains independently proven: 3/3
clean runs, 240 total requests (30+50 sequential/distributed ×3, plus
bursts), zero failures, regardless of the (still-exhausted) OPcache
buffer state — its effectiveness comes from avoiding the
serialize/unserialize round-trip entirely, not from anything to do with
OPcache.

**Minimal sanitized reproducer** (for a possible future upstream PHP/Laravel
report — not filed without explicit approval):

```php
// Under PHP's built-in "cli-server" SAPI (php -S host:port router.php),
// with a Laravel 13 app: cache a Collection via the database cache
// driver, then read it back on a second HTTP request to the same
// long-running process. Observed on PHP 8.5.7 (macOS/Homebrew):
// unserialize() intermittently/deterministically (after the first
// request that populates the cache) yields an object whose class
// resolves as __PHP_Incomplete_Class for Illuminate\Support\Collection,
// even though: (a) the stored serialized bytes are provably well-formed
// (verified by unserializing the same bytes successfully in an isolated
// CLI process with the same autoloader loaded), and (b) the failure is
// unaffected by opcache.enable or opcache.interned_strings_buffer in
// either direction (see the 4-case matrix above). Root cause within the
// PHP/Laravel/Livewire stack not yet identified.
Route::get('/repro', function () {
    return Cache::remember('collection-repro', 60, fn () => collect(['a' => 1]));
});
```

### Correction

`config/pulse.php`'s `'cache' => env('PULSE_CACHE_DRIVER')` is Pulse's own,
already-shipped, documented mechanism for pointing its *own* card-level
cache at a **different** store than the app's main `CACHE_STORE`. This
local `.env` now sets `PULSE_CACHE_DRIVER=array` — the `array` store never
serializes/unserializes at all (values are kept as live PHP references for
the request's lifetime only), so it fully sidesteps the exact codepath
that failed. This is:

- **Not** a global PHP/OPcache/system configuration change. The Phase
  24O.2A controlled experiment (below) directly rejected
  `opcache.interned_strings_buffer` as the cause, so raising it would not
  even have fixed the underlying problem — it's excluded on the evidence,
  not merely deferred as an out-of-scope global change.
- **Not** vendor code.
- Scoped only to Pulse's own card caching — the app's main
  `CACHE_STORE=database` (sessions, other caching) is untouched, confirmed
  by test (`test_correction_does_not_purge_unrelated_cache_or_session_records`).
- A one-line, reversible, per-environment `.env` override, consistent with
  how this app already treats Pulse's environment-tunable knobs.

**Trade-off, stated plainly:** Pulse's card values are no longer cached
*across* separate HTTP requests on this machine (the `array` store is
request-scoped) — every poll recomputes fresh. Pulse's underlying queries
are already lightweight/indexed, and for a local dev box this is a
reasonable price for a stable dashboard. **This is a local-environment
workaround, not a default recommendation.** `.env.example` documents
`PULSE_CACHE_DRIVER` (commented out) with guidance not to set it
preemptively — new environments get Pulse's normal caching behavior
(the app's `CACHE_STORE`) by default, and should only switch to `array`
if they actually observe the same "incomplete object" failure under real
Livewire polling.

### Verification

Reproduced the original failure live (2nd consecutive Livewire update
call, 100% of the time), applied the fix, then verified: 30/30 sequential
polls succeeded for one card, and 5/5 sequential polls succeeded across
**all 10** Pulse dashboard cards (50 requests, zero failures) — all
against the real running server, using real authenticated Livewire
update requests. A bounded concurrent burst of 5 parallel `/pulse` loads
also succeeded. `/up` and the frontend home page remained healthy
throughout.

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
| `tests/Feature/Admin/PulseCacheStoreCompatibilityTest.php` | Verifies the `PULSE_CACHE_DRIVER=array` fix's mechanics (round-trip, nested values, real Livewire card lifecycle, no unrelated-cache impact) |
