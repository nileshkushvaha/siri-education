# Timezone Architecture

Status: TZ-1 complete (resolution). TZ-2 onward not yet implemented — see
"Known gaps" at the bottom before assuming a surface behaves as described.

## The four concepts

These are permanently separate. Most timezone bugs come from conflating
two of them.

| # | Concept | Question it answers | Owner |
|---|---|---|---|
| 1 | **Resolution** | Which IANA timezone belongs to this user? | `App\Support\UserTimezoneResolver` |
| 2 | **Storage** | What absolute instant occurred / is scheduled? | UTC columns, `immutable_datetime` casts |
| 3 | **Local-calendar calculation** | Whose local day/week/wall-clock owns this rule? | The domain that owns the rule |
| 4 | **Display** | Which timezone should this viewer see it in? | The presentation layer (TZ-4) |

## 1. User timezone resolution

`App\Support\UserTimezoneResolver::resolve(User $user): string`

```
valid explicit UserProfile.timezone
    ↓
user's Country.default_timezone
    ↓
GeneralSettings.default_timezone
    ↓
UTC
```

Rules that hold at every tier:

- **Each tier is validated independently and falls through on failure.**
  A legacy or malformed stored value degrades to the next tier; it never
  throws. A bad `profile.timezone` must not be able to take out a
  dashboard render or a queued notification job.
- **An invalid identifier can never leave the resolver.** The returned
  string is always safe to hand to Carbon.
- **No `auth()` inside.** The caller passes the User. A notification
  recipient is not the authenticated user, a queued job has no session,
  and an admin surface chooses whose timezone it wants. There is
  deliberately no `currentUserTimezone()`.
- **Resolution is a read.** Resolving through the Country tier never
  persists that fallback as the user's explicit choice.

`resolveZone()` returns the same answer as a `DateTimeZone`.
`platformDefault()` exposes tiers 3–4 alone, for the few surfaces with no
user in hand.

### Never write this again

```php
// BANNED — a different fallback from the canonical chain, and no
// validation, so a legacy stored value reaches ->timezone() and throws.
$timezone = $user->profile?->timezone ?: config('app.timezone');
```

`tests/Architecture/UserTimezoneResolutionGuardTest.php` fails the build
if it reappears. The guard targets the *fallback ladder*, not
`config('app.timezone')` as a string — that call remains legitimate for
converting a local boundary to the storage timezone, for platform-owned
arithmetic, and as a provider label default.

Reading the raw stored value on its own is still fine and is used on
purpose: form prefill, API exposure, and "has this user actually chosen
a timezone?" checks.

## Country semantics

`Country.default_timezone` is a **fallback default, never geolocation**.
The United States, Canada and Australia each span several zones, so the
configured value is only "where we start someone who has not told us".

- An explicit profile timezone always wins.
- **Changing country never overwrites an explicit timezone.** People
  travel, relocate, and hold foreign phone numbers.
- Timezone is never inferred from a phone prefix, an IP address, or
  browser geolocation. `+1` covers many zones; there is no
  prefix→timezone map anywhere in this codebase, and there must not be.
- The only Country source is the persisted `user_profiles.country_id`
  relation.

## Identifier validation

`App\Support\Timezone\IanaTimezone` is the single definition of a valid
identifier: PHP's canonical `DateTimeZone::ALL` group (419 zones), not a
hand-maintained list.

It rejects values that parse fine through `new DateTimeZone(...)` but
cannot model DST across the life of a stored profile: `EST`, `GMT`,
`CST6CDT`, `US/Eastern`, `Asia/Calcutta`, `+05:30`, `GMT+5`. This is the
same set Laravel's `timezone` / `timezone:all` rule checks, so request
validation and runtime resolution agree by construction — a guard test
asserts the two sets stay identical.

## Registration and profile updates

**Registration (model A, retained):** `RegisterUserAction` snapshots the
country's configured default as the account's *initial explicit*
timezone, so a new account has a sensible clock before it opens the
profile screen. A country with no configured default inherits the
platform default — so what registration stores and what the resolver
would compute agree.

**Profile update:** a submitted value wins, then the already-stored
value, then the platform default. There is no India-specific literal
anywhere in the runtime path, and `user_profiles.timezone` no longer
carries a DB-level `'Asia/Kolkata'` default (migration
`2026_10_30_100000`, which changed the default only — no row was
rewritten).

## Storage

| Kind | Representation | Examples |
|---|---|---|
| Absolute instant | UTC, `immutable_datetime` | `bookings.starts_at`, `lessons.completed_at`, `student_package_entitlements.expires_at` |
| Local wall-clock rule | local value **+ owning IANA timezone** | `teacher_availability.day_of_week/start_time/end_time` + `timezone` |
| Date-only | `date` / `immutable_date` | `holidays.date`, `user_profiles.date_of_birth` |
| Duration | integer minutes/days | `booking_types.duration_minutes`, `validity_days` |

`config('app.timezone')` is `'UTC'` and is hardcoded, not env-driven, so
it cannot drift per environment.

## Business-owned timezone columns

Two columns legitimately own their own local-calendar semantics and must
**not** be replaced by the resolver:

- `teacher_availability.timezone` — the zone a weekly window was authored
  in. Day-of-week and wall-clock times are evaluated in it.
- `instructor_compensation_agreements.timezone` — NOT NULL. Period
  boundaries (daily/weekly/monthly) are validated and accrued in it.

The resolver is only the *fallback* when such a column is somehow blank.

## Snapshot columns — what they do and do not mean

`bookings.timezone`, `lessons.timezone` and `booking_meetings.timezone`
are **booking-origin interpretation snapshots**. They answer:

> In which timezone was the wall-clock time the student picked
> interpreted when this booking was created?

They do **not** answer "in which timezone should a viewer see this?".
A viewer's timezone is resolved from the *viewer*, never read off the
record being displayed. `booking_meetings.timezone` additionally serves
as the provider label sent to Zoom/Google — neither of which can shift
the meeting, since both receive an unambiguous UTC/offset-bearing
timestamp.

Dropping these columns entirely would change no scheduling behavior;
`starts_at`/`ends_at` are the truth.

## Input trust

A **naive** wall-clock input (`2026-08-17 09:00`) means nothing without a
timezone, so whoever controls the timezone controls the instant.

- `BookingWizard::$timezone` is `#[Locked]`. A client-initiated write is
  rejected outright. The only client influence is `setTimezone()`, which
  is ignored when the account has a valid explicit profile timezone and
  otherwise validates against the canonical list. Browser detection
  shapes the session only — it never persists to the profile.
- The Student Booking API still **accepts** a `timezone` parameter, for
  compatibility, but it is no longer authoritative when the account has
  a valid explicit profile timezone. It keeps its meaning only for
  accounts that have never stated one. `slots()` and `store()` share one
  definition (`StudentBookingController::timezoneFor()`) because they
  must agree — otherwise a student could pick a slot from one zone's list
  and be booked in another.
- Availability re-validation remains the backstop: a forged timezone can
  never book an unavailable slot.

## Reporting

`ReportingTimezoneResolver` is deliberately separate — it answers "which
timezone does this *report* run in?", which has no user, and its
reject-on-invalid-explicit contract is the opposite of the resolver's
degrade-safely contract. It no longer owns validation or the
platform-default lookup; both come from `IanaTimezone` /
`UserTimezoneResolver`.

A caller wanting a specific user's timezone resolves it with
`UserTimezoneResolver` first and passes the (always-valid) result in as
the explicit value.

`ReportingPeriod` converts a local calendar range to a half-open UTC
interval `[startUtc, endUtcExclusive)`. Query code must use those
boundaries, never local dates and never a `23:59:59` end-of-day.

## Known gaps (not yet closed)

TZ-1 covered resolution only. Still open, with phases assigned:

- **TZ-2** — availability holiday exclusion and the daily booking cap are
  bucketed by UTC date rather than instructor-local date; recurrence is
  wall-clock-correct but untested across DST and has no policy for
  nonexistent/ambiguous local times.
- **TZ-3** — three notifications outside the booking family still format
  on the server default rather than the recipient's timezone.
- **TZ-4** — portal list views and all 169 Filament `dateTime()` columns
  still render UTC. **This is why the snapshot-column contract above
  describes intent, not the behavior of every Blade file today.**
- **TZ-5** — admin date filters use `whereDate()` on UTC columns;
  reporting day-grouping uses an offset frozen at period start, which is
  wrong for DST-observing zones.
- **TZ-6** — DST regression coverage.
