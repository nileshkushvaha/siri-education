# Booking Engine

The booking foundation supporting the two current appointment types (Free Demo, Paid Lesson — single or recurring) behind one lifecycle, one registry, and one set of contracts.

Every booking participant is an authenticated, verified platform user. There is no unauthenticated guest-booking path anywhere in this domain — not a data shape, not an API surface, not a UI path. All booking creation goes through the authenticated `/book` wizard or the student dashboard's explicit-teacher-choice flow (both described below). `tests/Architecture/BookingGuestRemovalGuardTest.php` fails the build if any guest-booking class, table, column, or route reappears, or if `attendee`/`host`-style identifiers creep back into the domain's own source.

## Precondition: complete student profile

A student may not enter the wizard (`GET /book`, middleware
`student.profile.complete`) or submit a booking
(`WizardBookingService::book()` / `bookRecurring()` → `BookingException`)
until `StudentProfileCompletenessService::isComplete()` holds: a name, an
active country, a mobile number and accepted terms. Form-registered students
always satisfy it; students created from a Google sign-in are sent to
`/account/complete-profile` first. See `docs/security/authentication.md`
("Google sign-up").

## Teacher Availability Engine

Slots are never stored — `AvailabilityService::slots()` derives them
on demand from, in order: weekly windows (`teacher_availability`) →
bookable window (`BookingSettings` lead/advance limits) → holidays
(org-wide, `holidays` table) → leave (`teacher_unavailability`) →
existing bookings padded by the type's `buffer_minutes` → the
teacher's daily cap (`BookingSettings::max_daily_bookings_per_teacher`).

- **SlotGenerator** (`app/Booking/Services/SlotGenerator.php`) holds
  the pure interval math (candidate slicing, buffered conflict
  detection) — no persistence, no clock; unit-tested in
  `tests/Unit/Booking/SlotGeneratorTest.php`.
- **Buffer** is per booking type (`booking_types.buffer_minutes`):
  consecutive slots are spaced by duration + buffer, and existing
  bookings block a padded range on both sides. Intervals are
  half-open — touching does not conflict.
- **Timezones**: weekly availability windows are entered as local
  instructor wall-clock times and carry a timezone, defaulting from
  `user_profiles.timezone`. Slot generation expands those windows in
  the instructor timezone, converts candidate instants to UTC for
  conflict checks, and returns slots in `AvailabilityQueryData::timezone`.
  Leave / blackout periods are stored in UTC with the source timezone
  retained for display and audit context.
- **DST**: weekly windows expand using Carbon's per-instant timezone
  offset (via `AvailabilityRepository::windowsFor()`), so the same
  local wall-clock window (e.g. 09:00–11:00) resolves to a different
  UTC instant on either side of a daylight-saving transition — never a
  fixed UTC delta. Covered by
  `tests/Feature/Instructor/InstructorAvailabilityHardeningTest::test_slot_generation_handles_dst_spring_forward_transition`,
  which locates the next real spring-forward transition for
  `Australia/Sydney` dynamically (rather than a hardcoded date) so it
  keeps exercising a live transition indefinitely; it self-skips
  (non-blocking) on the rare run where no transition falls inside the
  booking engine's max-advance window. Leave/time-off ranges are
  parsed in the source timezone and stored as UTC instants, so they
  are DST-transition-safe by construction.
- **Missing instructor timezone**: publishing (`is_active = true`)
  weekly availability never silently falls back to the app timezone.
  If `user_profiles.timezone` is empty, the create/update call must
  explicitly pass a `timezone`, or `InstructorAvailabilityService`
  throws a `ValidationException` on the `timezone` field. Draft
  windows (`is_active = false`) may still be saved without a profile
  timezone or explicit choice at the service layer — they fall back to
  `config('app.timezone')` until published, matching
  `docs/architecture/phase-6-instructor-availability-foundation.md`.
  The instructor availability page (`/dashboard/instructor/availability`)
  shows a warning banner and links to profile settings whenever the
  profile timezone is missing, and its timezone `<select>` starts
  blank (no default value) so the instructor must explicitly choose
  one instead of unknowingly submitting a browser-preselected option.
  The page's "Add window" form always creates active (published)
  windows and requires a timezone unconditionally (Livewire validation),
  so the draft-without-timezone path is only reachable through the
  service directly (e.g. a future admin/draft UI), not through this
  form today — the banner text is scoped accordingly.
- `ensureAvailable()` applies the same checks for a single slot and
  is re-run under the host lock on create/reschedule.
- **Student-facing display timezone** (Phase 3.1 §23-§30 audit): the
  student always sees dates/slots in their OWN timezone, never the
  instructor's or the server's. `App\Support\UserTimezoneResolver::resolve()`
  (student's stored `user_profiles.timezone` → `GeneralSettings::default_timezone`
  → `UTC`) feeds `BookingWizard::$timezone`, which flows unchanged
  through `WizardBookingService::availableDates()/availableSlots()` into
  `AvailabilityQueryData::$timezone` — the same parameter
  `AvailabilityService::slots()` already converts into at the final
  step above. Instructor timezone is used only to expand weekly
  windows into UTC instants; it never becomes the student's display
  timezone. `Booking::timezone` freezes the student's timezone at
  submit time — a later profile timezone change never reinterprets a
  past booking's historical display. Dedicated coverage:
  `tests/Feature/Booking/BookingWizardStudentTimezoneSlotsTest.php`
  (same/different timezone, date crossover both directions, DST,
  submission instant equality, historical-display stability).

## Admin Panel (Filament)

Navigation group **Booking**: Bookings, Booking Types, Teacher
Availability, Teacher Leave (`TeacherUnavailability` model), and a
Reports page. All follow the Schemas/Tables delegation pattern.

- **Bookings** has no Create page by design — bookings are created by
  the engine. Lifecycle row/bulk actions (confirm, cancel, reschedule,
  complete, no-show) call `BookingServiceInterface`, so guards,
  locking, timeline, events, and notifications always run;
  `BookingException` surfaces as a danger notification. Status tabs,
  filters (status, payment, type, teacher, date range, trashed),
  an Activities (timeline) relation manager, soft deletes, CSV export.
- **Booking Types** restricts `key` to registered drivers
  (`BookingTypeRegistry::options()`), has a Bookings relation manager,
  activate/deactivate bulk actions, soft deletes, CSV export.
- **Teacher Availability / Leave** filter teacher selects to
  approved/published instructors; availability has activate/deactivate
  bulk actions; leave defaults to current-or-upcoming filter. Create,
  edit, delete, and publish-style actions — including table row
  `DeleteAction` and bulk `DeleteBulkAction` — run through
  `InstructorAvailabilityService` / `InstructorTimeOffService`
  so timezone, bookable-status, overlap, permission, and audit rules
  are consistent with frontend self-service. No generic
  `Model::delete()` path remains on either resource's table: row
  deletes require the `delete` policy ability (`->authorize()`) and
  call the owning service inside a try/catch that shows a Filament
  danger notification on failure instead of a raw exception; bulk
  deletes authorize each selected record individually
  (`->authorizeIndividualRecords('delete')`) and report a partial-failure
  notification if any record's service call is rejected.
- **Reports** (`/admin/booking-reports`): stats overview, 30-day
  bookings chart, top-teachers table — widgets live in
  `app/Filament/Widgets/Booking/` (kept off the Dashboard by its
  explicit widget list).
- CSV export is dependency-free via `App\Filament\Support\CsvExport`.
- Policies: `BookingPolicy` (+restore/forceDelete),
  `BookingTypePolicy`, `TeacherAvailabilityPolicy`,
  `TeacherUnavailabilityPolicy` — Shield-style permission names
  (`ViewAny:Booking`, …); run `shield:generate` (or seed permissions)
  before granting managers access. Smoke-tested in
  `tests/Feature/Filament/BookingAdminPanelTest.php`.
- **Service-level ownership/admin guards**: policies gate
  the Filament layer, but `InstructorAvailabilityService` and
  `InstructorTimeOffService` also assert authorization internally on
  every `create`/`update`/`delete`, so the same rule applies whether
  the call comes from Filament, the instructor Livewire page, or a
  direct service call. An actor may act on a record only if either
  (a) it is the record's own instructor (`actor->id === teacher_id`
  and `actor->hasRole('instructor')`), or (b) the actor passes the
  matching Shield permission via the resource policy
  (`Create:TeacherAvailability`, `Update:TeacherUnavailability`, …).
  Anyone else — a different instructor, a student, an unpermitted
  manager — gets `Illuminate\Auth\Access\AuthorizationException`, not
  a silent no-op. Covered by
  `tests/Feature/Instructor/InstructorAvailabilityHardeningTest.php`.

## Deployment runbook

Required on every deploy, in order:

1. `php artisan migrate --force` and `php artisan migrate --path=database/settings --force` (settings migrations, see `docs/settings.md`).
2. `php artisan db:seed --class=BookingTypeSeeder --force` — sync
   booking-type drivers to rows (idempotent).
3. `php artisan db:seed --class=BookingPermissionSeeder --force` —
   **mandatory**: policies deny unknown permissions, so without this
   only `super_admin` can reach the booking admin. Grants managers
   everything except force-delete.
4. **Queue worker** — all notifications and listeners run on the
   `notifications` queue with the `database` driver:
   `php artisan queue:work --queue=notifications --tries=3`
   (supervised). Nothing is delivered without it.
5. **Scheduler** — `* * * * * php artisan schedule:run` cron. Gates
   `booking:release-expired` (unpaid reservation cleanup, every 5 min),
   `booking:generate-series` (**mandatory** for recurring schedules —
   without it a series never grows past the classes created at booking
   time; hourly, idempotent) and the existing prune jobs.
6. `npm run build` — the booking wizard ships compiled Tailwind.

> **The runnable version of this checklist lives in
> `docs/deployment/recurring-bookings-go-live.md`** — exact commands,
> expected output and the evidence to record for each item. The summary
> below states what is being proved and why; that document states how.

### Deployment verification checklist — before enabling ongoing/long schedules

`recurring_future_generation_enabled` must stay **false** until every
step below has been performed **on the target environment** and the
evidence recorded. A passing test suite is not evidence: it proves the
code is correct, not that this machine runs the cron, supervises the
worker, or delivers mail.

Local checks — necessary, and NOT sufficient:

```bash
php artisan schedule:list | grep booking:generate-series   # registered
php artisan booking:generate-series --sync                 # command runs
php artisan test --env=testing tests/Feature/Booking/RecurringScheduleReleaseSafeguardsTest.php
```

Deployment evidence — each needs an artefact, not an assumption:

| # | Check | Evidence to keep |
|---|---|---|
| 1 | The Laravel scheduler is actually invoked | **This deployment supervises long-running processes with Supervisor, so an empty `crontab -l` is not evidence of a problem.** Identify the one mechanism in use (`supervisorctl status`, systemd timer, or cron), then show it `RUNNING` with real uptime and no crash loop |
| 2 | The task fires on schedule | Two `scheduler_histories` rows for `booking:generate-series` ~60 min apart with `triggered_by != 'manual'`. A manual run proves the command, never the schedule |
| 3 | Scheduler Monitor sees it | `booking:generate-series` listed with a recent successful run (`/admin` → Scheduler Monitor, `scheduler_histories`) |
| 4 | Queue worker is running and supervised | The command only DISPATCHES; the work happens on the **`notifications`** queue. A worker consuming only `default` never touches it. `php artisan queue:monitor notifications`; kill it and confirm Supervisor restarts it |
| 5 | **Future classes are actually generated** | Create a test schedule longer than the horizon; wait for at least one hourly run; confirm new `bookings` rows appear for it with no manual command. Query: `select count(*) from bookings where booking_series_id = ?` before and after |
| 6 | Generation is failing loudly, not quietly | `php artisan platform:health-check` — exit 0/1/2 covers scheduler staleness, the sweep's own staleness, queue backlog and stalled series |
| 7 | **Notification delivery works end to end** | Put the test instructor on leave for a future date of that schedule, wait for the sweep, and confirm the student receives the message on a real channel (inbox, not just `notifications` table). Then confirm `booking_series_exceptions.notified_at` is set and a second sweep sends nothing |
| 8 | Recovery works | Lift the leave, use **Try again** on that date, and confirm a booking is created and the exception row is gone |

Only after 1–8: set `recurring_future_generation_enabled = true` (Spatie
settings, `booking` group). Do **not** enable it on the strength of
local success, and do not enable it in one environment because another
passed.

### Paying for a whole schedule at once

`BookingSeriesPrepaymentService` collects for every RESERVED class of a
schedule in one checkout, routed through the student's own wallet.

That routing is the design, not a shortcut. `BookingPayment` is the
obligation to pay for ONE booking; one obligation spanning many would
mean apportioning a partial refund across a single captured provider
payment whenever a class is later cancelled. Refunds already credit the
wallet, so paying through it makes cancelling one class of a batch
*exactly* the refund path that already exists. Every per-class semantic
survives: own price, own `BookingPayment`, own reservation, own invoice,
own refund decision.

Rules worth knowing:

- Only reserved classes are billed — never ones beyond the horizon that
  nobody is holding.
- The top-up is exactly the shortfall, never rounded up.
- A mixed-currency batch is refused rather than half-settled (wallet
  payment never converts).
- Classes settle earliest-first, so a short balance secures the soonest.
- Completion runs off the verified recharge
  (`SettleSeriesPrepaymentOnWalletRechargeSucceeded`), not the browser's
  return, so closing the tab at the gateway cannot leave classes unpaid.

### Confirming future classes unattended

Two switches, both required, because money moves with nobody present:

| Switch | Meaning |
|---|---|
| `BookingSettings::$recurring_wallet_auto_settle_enabled` | the platform CAPABILITY — stops it for everyone at once |
| `booking_series.auto_settle_from_wallet` | the student's PERMISSION, per schedule, default false |

Consent is recorded only by an explicit opt-in and is never inferred
from having paid a batch once. It spends **only money already in the
wallet**: it never opens a checkout, never tops up, and never touches a
card — a short balance simply leaves the class payment-due. Partial
settlement is deliberately refused, since draining the balance to zero
*and* leaving classes unpaid is the worst of both outcomes.

Withdrawal is never refused, even when the capability has since been
switched off or the schedule has ended — a control that stops spending
must not disappear on someone inside it.

Each settled class dispatches `BookingPaymentSucceeded`, so the student
is told through the existing notification path; it is never silent.

Add to the verification checklist before enabling
`recurring_wallet_auto_settle_enabled`:

| # | Check | Evidence |
|---|---|---|
| 9 | A generated class is confirmed from balance | Opt a test schedule in, fund the wallet, wait for the hourly pass, confirm the new class is `paid` with a `BookingPayment` row and a wallet ledger debit |
| 10 | A short balance charges nothing | Same, with a balance below the class price: the class stays payment-due and the balance is untouched |
| 11 | The student is notified | Confirm the payment-succeeded message arrives on a real channel |
| 12 | Opting out stops it | Untick in My Bookings, wait for the next pass, confirm nothing is settled |

To roll back, set it to `false`. Existing schedules keep generating —
the flag gates CREATION of new ones, not the sweep.

Settings checklist (Spatie `booking` group): `payment_provider`
(**`fake` moves no money** — implement a real
`PaymentProviderInterface` before enabling paid types publicly),
`payment_reservation_minutes`, `captcha_enabled` +
`turnstile_site_key`/`turnstile_secret_key`,
`max_daily_bookings_per_teacher`, `minimum_booking_notice_minutes`,
`maximum_advance_booking_days`, `recurring_confirmation_horizon_days`,
`recurring_generation_batch_size`,
`recurring_future_generation_enabled` (**starts false** — see the
verification checklist above), notification channel toggles.

## Subject normalization

`teacher_subjects` has a nullable `subject_id` FK to the `Subject` master (added by the reconciliation described in `docs/architecture/subject-teacher-subject-reconciliation.md`), alongside its original free-text `subject` column; `InstructorService`/`TeacherCandidateRepository` prefer the `subject_id` relation when set, falling back to free text otherwise.

`bookings.meta.subject` (used by matching + analytics) is still free-text only — no `subject_id` FK exists on `bookings` today. Normalizing that side follows the same pattern already used for `teacher_subjects` (add a nullable FK, backfill by slug, dual-write, then switch readers) whenever it's needed for admin CRUD or i18n on the booking side specifically.

## Student Booking

Session-auth JSON endpoints under `/dashboard/bookings` (same
middleware stack as the student dashboard), backed by
`StudentBookingServiceInterface` — a thin layer over the core engine;
every occurrence still runs through `BookingService::request` (rules,
locks, events, notifications identical to every other flow).

The authenticated `/book` wizard (`BookingWizard` Livewire component, `WizardBookingService`/`WizardBookingData`) is the other entry point — it auto-assigns a teacher and drives payment; the route requires `auth` (redirects to login, preserving the intended URL — no slot/price state is preserved across that boundary, the student picks again once logged in). `WizardBookingService::book()` also independently refuses when unauthenticated, since `CreateBookingData::$studentId` is a non-nullable `int`.

`BookingActor::Student`/`::Instructor` are the domain's own participant terminology — distinct from `teacher` (marketplace/matching context: `TeacherAssignmentService`, `teacher_subjects`, `TeacherAvailability`) and meeting-provider `host` fields (`booking_meetings.host_url`, Zoom `host_user_id`/`host_email`), which are legitimate, unrelated uses of similar words.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/dashboard/bookings` | My upcoming bookings |
| GET | `/dashboard/bookings/teachers?type&subject&grade` | Choose a teacher (eligible list) |
| GET | `/dashboard/bookings/previous-teachers` | Rebook a previous teacher |
| GET | `/dashboard/bookings/slots?teacher_id&type&date&timezone` | Chosen teacher's slots |
| POST | `/dashboard/bookings` | Book (add `recurring`, `occurrences`, `interval_weeks` for a series) |
| POST | `/dashboard/bookings/{booking}/pay` | Settle a pending payment (placeholder) |

- **Teacher choice** is validated: with subject+grade the teacher must
  teach it (`teacher_subjects`); otherwise they must be an
  approved/published instructor.
- **Recurring** creates a `BookingSeries` on a `Daily` or `Weekly`
  cadence (`RecurrenceFrequency` enum). `occurrences` is **no longer
  capped at 12** — the request shape is unchanged, but classes are
  reserved as far as the confirmation horizon reaches and the rest are
  generated as their dates approach, so `data` may legitimately be
  shorter than `occurrences`. Every booking still carries the shared
  `meta.recurring_group` uuid (it is the series id, and still
  `RecurringBookingResult::$groupId`); conflicting occurrences are
  reported in `failures` — all failing is a 422. `WizardBookingService`
  resolves the instructor once (locked via deep-link, or auto-assigned
  for the first occurrence) and that instructor is the series'
  instructor for its whole life. Recurrence is rejected on a non-paid
  type with a `BookingException`.
- **Payments**: paid types return a payment intent on creation.
  `BookingPaymentServiceInterface` is bound to a clearly marked
  PLACEHOLDER (`BookingPaymentService`) — generates references,
  verifies them on `pay`, flips `payment_status` to Paid, and records
  a timeline entry. Swap the binding for an
  app/Services/Payment-backed gateway; callers don't change.
  `BookingPolicy::pay` restricts settlement to the student (or
  `Update:Booking`).
- `BookingActor::forUser()` is the shared actor-resolution helper.
- JSON error handling for non-`api/*` endpoints comes from
  `expectsJson()` in bootstrap/app.php (exceptions + BookingException
  → 422).

## Recurring class schedules (series)

A repeating booking is a **schedule** — a stored rule — that owns
individual class bookings, rather than N loose bookings sharing a uuid.

That distinction is what removed the twelve-occurrence limit. The old
design created every occurrence up front, so "every occurrence" had to
be finite and small. Storing the rule instead means the length of a
schedule costs nothing at creation: a student can ask for forty classes,
or for one with no end at all.

### Tables

| Table | Holds |
|---|---|
| `booking_series` | the rule (frequency, `repeat_interval`, `weekdays`, `start_date`, `time_of_day`, end condition), its own `timezone`, the student's `student_timezone`, status, and `generated_through_date` — the generation watermark |
| `booking_series_exceptions` | per-date deviations: `skipped` (student removed it), `conflict` (became unbookable after the fact), `moved` (same date, different `local_time`) |
| `bookings.booking_series_id` + `bookings.series_occurrence_date` | the link, with a **UNIQUE** index on the pair |

All additive and nullable; nothing is backfilled. Historical recurring
bookings keep being identified only by `meta.recurring_group` and are
deliberately not given a rule — inventing one for a set of dates nobody
stated one for would be a guess, and a guessed rule would generate real
classes. New series bookings keep writing `meta.recurring_group` (set to
the series id), so existing readers, reports and API consumers are
unaffected.

### Confirmation horizon

`BookingSettings::$recurring_confirmation_horizon_days` (default 60) is
how far ahead classes are actually created and reserved. Beyond it,
dates are **planned**: real parts of the schedule, not yet held and not
charged. `BookingSeriesService::horizonDate()` measures from the series'
START once that is in the future — otherwise a schedule beginning in six
months would confirm nothing at the moment the student books it — and is
always clamped to `maximum_advance_booking_days` measured from today, so
a series can never reserve further ahead than any other booking may.

`booking:generate-series` (hourly, `withoutOverlapping()`,
`onOneServer()`) dispatches `GenerateBookingSeriesOccurrences` per active
series; the job fills one bounded batch
(`recurring_generation_batch_size`, default 25) and re-dispatches itself
while the horizon still has room.

### Release gate: `recurring_future_generation_enabled`

A schedule that reaches past the confirmation horizon — an ongoing one,
**or a long finite one** — is only a real promise if the background pass
that fills it in is actually running on that deployment. Tested
generation code does not establish that: the cron may not be installed,
the worker may not be supervised.

`BookingSettings::$recurring_future_generation_enabled` starts **false**
and is enforced server-side in `BookingSeriesService::create()` — the
one chokepoint the wizard, the JSON API and any future caller all funnel
through, so it cannot be bypassed by using a different entry point.
`BookingWizard::setEndCondition()` refuses "never" independently, so
hiding the option is not the protection.

It is **not a class-count cap**:

- With it **on**, there is no limit of any kind.
- With it **off**, a schedule needing future generation is REFUSED with
  an explanation naming the last date currently bookable and pointing at
  "extend later". It is never silently shortened — booking the part that
  fits and dropping the rest is the exact failure the horizon exists to
  prevent.
- Schedules that fit inside the horizon are unaffected either way.

`requiresFutureGeneration()` answers ongoing and long-finite together
rather than special-casing "until I cancel", so a 40-week finite
schedule is gated for the same reason and with the same message shape.

Turn it on only after walking the deployment checklist below on the
target environment.

### Telling the student when a future class is lost

Interactively, conflicts are resolved before confirmation. The case a
student cannot see is a date that was fine when they booked and has
since stopped being — the instructor took leave, the slot went.

`BookingSeriesService` dispatches `BookingSeriesOccurrenceUnavailable`,
and `SendBookingNotifications::handleSeriesOccurrenceUnavailable()`
sends `BookingSeriesOccurrenceUnavailableNotification` — the ordinary
participant pipeline, per the repository rule that notifications never
originate from services directly. Student only: the instructor has
nothing to act on, since it failed because their own calendar was busy.

The message carries the date and time in the **recipient's** timezone
(`FormatsRecipientLocalTime`, not the series' instructor-anchored
clock), the reason, what it costs (a counted schedule reaches one date
further; a date-bounded one loses a class), and a link to the
repeating-schedule panel.

Two independent guards stop duplicates:

| Guard | Covers |
|---|---|
| `booking_series_exceptions.notified_at` | a later generation pass, a restarted worker, the next hour's sweep. The row is written with `updateOrCreate`, so its existence cannot mean "already reported" — the stamp is claimed **before** dispatch, so a crash between the two leaves a silent date rather than a repeated message |
| `NotificationIdempotencyGuard`, keyed `series:date:recipient` | the queue's at-least-once delivery redelivering the same event |

A failed **student-initiated** retry re-stamps `notified_at` instead of
notifying: they triggered it and are reading the answer on screen.

### Recovering a lost class

A conflict exception is otherwise permanent, and the sweep will not
revisit the date — it sits behind `generated_through_date`, which is
what makes the watermark cheap. `BookingSeriesService::retryOccurrence()`
addresses that one date directly (student action: **Try again** in the
schedule panel). On failure the exception is recorded again with a fresh
reason, so the date stays visible and can be tried later rather than
being lost.

### Idempotence

Generation is safe to retry, repeat, or race, in this order of authority:

1. `bookings (booking_series_id, series_occurrence_date)` is UNIQUE — two
   workers attempting one occurrence produce one row and one caught
   constraint violation, never two classes and two payment demands.
2. `generated_through_date` is a watermark, so a resumed run does not
   re-walk decided work.
3. A cancelled occurrence keeps its booking row, so regeneration cannot
   resurrect a class the student called off.

`ShouldBeUnique` on the job is an optimisation, not the guarantee.

### Recurrence math

`RecurrenceScheduler` is the only place a rule becomes dates — the
wizard preview, the review step, creation, the background job and the
API all go through it, so the client can never disagree with the server.
It is pure (no persistence, no clock, no availability) and unit-tested in
`tests/Unit/Booking/RecurrenceSchedulerTest.php`.

Dates are enumerated in the RULE's timezone. Weeks are anchored on the
Sunday of the start date's week, so `Weekday`'s Sunday-first numbering is
also chronological order and "every N weeks" keeps its phase across month
and year boundaries. Each occurrence pairs the calendar date with the
rule's own time of day — that pairing, not a fixed UTC offset from the
first class, is what resolves to an instant, so the intended wall clock
survives daylight saving. A reading a spring-forward transition deletes
(or a fall-back doubles) is reported as unrepresentable, never moved:
`WizardBookingService::assertRuleRepresentable()` refuses the whole
series before anything is created, exactly as before (TZ-6 / TZ-AUD-022,
`tests/Feature/Booking/TimezonePolicyClosureTest.php`).

The series' timezone is the INSTRUCTOR's scheduling calendar
(`AvailabilityRepository::calendarTimezoneFor()`), unchanged from TZ-6's
product decision. Weekdays the student ticked are in THEIR calendar and
are translated by the day offset between the two at the chosen slot — a
Monday-evening class for a student in New York can be a Tuesday for an
instructor in Kolkata, and the rule has to say Tuesday.

### Conflicts, and what a lost date costs

`SeriesOccurrenceConflictChecker` evaluates one date against the
instructor's availability (the same `AvailabilityService::ensureAvailable()`
`BookingService::request()` runs under the instructor lock), the
student's own overlapping classes
(`BookingRepositoryInterface::studentHasOverlap()`), the bookable window
and wall-clock representability. It is only ever given the series'
existing instructor and has no way to look for another — a date the
assigned instructor cannot teach is a conflict for the student to
resolve, never a silent reassignment.

The preview is advisory by nature (it runs outside the lock), which is
why creation re-checks every occurrence under it.

Nothing is ever silently skipped or shifted:

- **In the wizard**, conflicts are shown before confirmation and must be
  moved or removed; the CTA is disabled and `submit()` refuses.
- **In the background**, a date that has become unbookable is written as
  a `conflict` exception carrying its reason — visible in the student's
  series view — rather than vanishing.

Whether a lost date costs a class depends on the end condition, and the
student is told which applies before confirming:

| End condition | A removed/unbookable date |
|---|---|
| After N classes | does not count — the schedule reaches one date further, so N classes still happen |
| On a date | is simply not booked — the last date does not move, so there is one class fewer |
| Never (ongoing) | is not booked; the schedule carries on |

### Managing a schedule

`BookingSeriesService` owns the operations, all routed through the
ordinary engine so refund policy, reschedule allowance, the timeline,
events and notifications behave exactly as for a single booking:

- `skipOccurrence()` — drop one date (cancels its booking if one exists).
- `moveOccurrence()` — same date, different time, recorded as a `moved`
  exception; the class keeps its place and still counts.
- `cancelFrom()` — `SeriesChangeScope::ThisOnly` /
  `ThisAndFollowing` / `RemainingSeries`. The latter two truncate the
  RULE rather than deleting the series, so completed classes, payment
  history and existing exceptions are preserved and nothing further is
  generated.
- `extend()` — moves a finite schedule's end. Nothing is recreated:
  existing bookings keep their ids, references, payments and meetings,
  and the watermark means generation resumes where it stopped.

Ownership is re-checked for every series action
(`BookingDetail::ownedSeries()`) rather than inferred from the single
booking's policy check — a series action changes OTHER bookings, so "may
view this one" is not the question being asked.

### Payment semantics

Unchanged. Each class is priced, reserved and paid **separately**, with
its own reference and its own `reserved_until` hold; nothing is charged
automatically and no refund, authorization or cancellation policy
changed. The wizard states three separate figures — per class, the
finite schedule's scheduled value, and the amount payable now — and
shows an ongoing schedule as having no total.

## Student booking wizard (student-facing UX)

The `/book` wizard (`BookingWizard` Livewire component,
`resources/views/livewire/frontend/booking/`) presents **four conceptual
stages** over the unchanged internal phase list. `BookingWizard::phases()`
and `$step` remain the authoritative state machine; stages are how that
machine is presented (`BookingWizard::STAGE_PHASES`).

| Stage | Internal phases | What the student does |
|---|---|---|
| 1 Learning details | `mode`, `level`, `academic_subject`, `curriculum` (legacy: `subject`, `grade`) | Session type, then level → subject → curriculum, disclosed progressively in one panel. Answered questions collapse to a "Change" row (`x-booking.chosen-row`, `editPhase()`). |
| 2 Schedule | `billing_mode`, `date`, `time` | "How often?" (paid only); for a repeating schedule, class days + "how long" inline; month calendar ("Starting from" when repeating); grouped Morning/Afternoon/Evening times; live schedule summary — one panel, one "Review booking" CTA. |
| 3 Review | `funding`, `review` | Learning / Schedule / Instructor / Pricing cards with Edit links, package choice when one qualifies, notes, configured cancellation/reschedule facts. CTA "Proceed to payment" or "Confirm booking". |
| 4 Payment / Confirmed | `confirmed` | Unchanged reservation + checkout screen (`partials/confirmed.blade.php`). |

Navigation is server-side: `continueStage()` advances only when the
current stage is complete, `editStage()`/`backStage()` return to an
earlier stage with every selection intact and resume at the furthest
valid phase (`resumeSchedulePhase()`), and `editPhase()` re-opens one
answered question inside the current stage. Re-selecting the same level,
subject or curriculum keeps everything after it; choosing a different one
clears only its dependants (subject/curriculum lists, availability, the
price preview) — see `selectLevel()`/`selectAcademicSubject()`/
`selectCurriculum()` and `selectBillingMode()` (switching one-time ↔
repeating clears the chosen date/time). The last selection of a stage no
longer auto-advances: tests that walk the component call
`continueStage()` where the UI would.

**Pre-filled learning details (returning students).**
`BookingWizardService::learningPrefill()` returns the ids from the
student's most recent non-cancelled booking's `BookingAcademicContext`
(`BookingRepositoryInterface::latestAcademicContextForStudent()` —
`created_at` desc, then `id` desc so same-second bookings resolve the
same way every load), falling back to the profile's
`student_academic_level_id`, plus the student's active preferred subject
ids (`User::preferredSubjects()`, chosen on the profile page).
`BookingWizard::applyLearningPrefill()` feeds them through the ordinary
`select*()` chain, so validation, locked-instructor narrowing and the
no-normalized-grade refusal apply unchanged; the chain stops at the
first id no longer offered. A profile academic level is used only when
exactly one offered level maps to it.

The subject follows the student's own preferred subjects before any
booking history: exactly one of them offered under the chosen level →
it is selected regardless of the last booking; more than one → no
subject is pre-selected and the student stops on the subject step,
where their preferred subjects are listed first with a "Your subject"
badge and the copy asks which one the lesson is for; none → the last
booking's subject as before. Auto-selection happens only in
`applyLearningPrefill()`, never in `selectLevel()`, so editing the level
always shows the subject step. A fully pre-filled selection lands on the
Schedule stage (the header and progress show "Subject • Level • System"
with Edit); `$prefilledLearning` drives the explanatory copy and is
cleared on the first manual change. Students with no booking history
and no profile academic level are never pre-filled. Known gap: recurring
bookings (`WizardBookingService::bookSeries()`) write no
`BookingAcademicContext`, so a recurring-only history contributes no
last-booking prefill — the preferred-subject rule covers those students.

**Terminology.** Every level label comes from the selected
`EducationSystem` (`levelTermSingular()`: Class / Grade / Year, generic
"Level" fallback) and from `EducationSystemLevel::display_label`; no
booking view hardcodes a term.

**Availability.** Dates and times are exactly what
`WizardBookingService::availableDates()/availableSlots()` return
(`AvailabilityService` over the eligible candidate set); the UI never
synthesises slots or counts. Submission still runs the full
`BookingService::request()` path under the instructor lock. If the slot
was taken in between (`SlotUnavailableException` /
`NoEligibleTeacherException`), `submit()` clears only the slot, reloads
the same day's times and returns to the Schedule stage with "That time
is no longer available. Please choose another time." — every other
selection survives.

**Pricing.** The sidebar/review/mobile-footer amount is
`BookingWizardService::pricePreview()`, a display-only call to the same
`BookingPriceCalculator` `BookingService::request()` charges with
(instructor-specific override when an instructor is locked, base price
otherwise, null when unresolvable or for a free type). Discount/tax rows
render only when non-zero. Nothing from the browser feeds the price;
the booking's price is recalculated at creation and shown again on the
payment screen.

**Recurring.** Selecting "Repeating classes" reveals the whole schedule
inside the **existing Schedule step** — there is no separate pattern
step to walk into and back out of, and no "Continue to date & time".

| Field | What it does |
|---|---|
| **Class days** | Mon–Sun multi-select. This is the ENTIRE cadence control: classes repeat on these days every week, and all seven days IS a daily schedule. A running "3 classes per week" states the density. At least one day must stay selected. |
| **Starting from** | The existing calendar, used as a BOUNDARY rather than a class date. `firstClassDate()` resolves the first chosen day on or after it and the UI states it outright ("First class: Wednesday, 2 December 2026"). An unselected weekday is never added to make the picked date work. |
| **Class time** | The existing slot list, loaded for the FIRST CLASS (not the boundary, which may not be a class day). One shared time across every class; the type's duration and the student's timezone are shown beside it. |
| **How long?** | Three compact options — a whole number of classes, an end date, or "Until I cancel". Never preselects the ongoing option. |

The Daily/Weekly switch and the "repeat every N weeks/days" control are
**gone from this form**: they were a second way of saying what the day
list already says, and two ways of saying it meant two things that could
disagree. `BookingSeries` still stores `frequency` and `repeat_interval`
and still generates from them, so series created under the old form keep
their exact pattern and are described by it (`BookingSeries::describe()`
→ "Every 3 weeks on Tuesday"). Nothing is ever converted.

Below the fields, `previewSeries()` drives a live summary card: days,
time, first class, **last class**, class count, and the three price
figures kept apart (per class / total scheduled / payable now — an
ongoing schedule shows "No total"). Then the dated list, where each
occurrence shows whether it is *Ready to confirm*, *Planned*,
*Reserved — payment due* or a conflict, with per-date **Change time**
(that instructor's other slots on that date only) and **Remove**. The
list is paginated and has explicit loading, empty, conflict and retry
states. A removed date stays visible so it can be put back.

The existing **Review booking** action is the only way forward, and it
is disabled until the days, a start date, a time and any conflicts are
resolved (`scheduleComplete()`); `submit()` refuses the same thing
independently. Every choice is ordinary component state, so moving
between stages preserves it, and changing the days keeps the chosen time
of day whenever that slot still exists on the new first class
(`reanchorSchedule()`).

**Payment.** Unchanged: `submit()` reserves, the confirmed screen calls
`initiatePayment()` / wallet / fake simulator exactly as before, and only
webhooks/reconciliation settle. `submit()` is a no-op once `$result`
exists (duplicate-click protection alongside `wire:loading` disabling).

**Layout.** Compact header (title, learning summary, locked instructor,
"Area/City (GMT±hh:mm)"), `x-booking.progress` (stage numbers, completed
summaries, Edit), main panel + sticky "Your session" sidebar from `lg`
(`partials/summary.blade.php`, rows appear only once known). Below `lg`
the summary is a collapsible card and a fixed footer carries the
authoritative price/"Free"/"Package" and the stage CTA
(`partials/cta.blade.php`); touch targets are ≥ 44px and the page never
scrolls horizontally. Selected options (`x-booking.option-card`) combine
border, tint, and a checkmark with `aria-pressed`; stage lists carry
`aria-current="step"`; loading and empty states exist for availability
("No times are available this month / on this date"), price and
submission.

**Reserved / payment-pending screen (stage 4).** Rendered by
`partials/confirmed.blade.php` once `submit()` has reserved a paid
booking: `status = pending`, `payment_status = pending` (or `failed`
after a declined attempt), `reserved_until = now + BookingSettings::
payment_reservation_minutes`. Everything shown comes from
`BookingWizardService::result()` — amount/currency are the booking's own
`price`/`currency` formatted by `MoneyFormatter` (no client arithmetic,
no hardcoded currency), the reference is `bookings.reference`, and the
lesson line uses the `BookingAcademicContext` snapshot (`subject_name`,
`level_display`, `education_system_name`) so the level term is the
education system's own. Layout: compact header + stage progress
("4 of 4 • Payment" on mobile), a status pill, then a two-column
checkout from `md` (Your lesson · Payment); below `md` the columns
stack and a fixed footer repeats "Total due" + "Pay securely"
(hidden while the Stripe Payment Element is mounted, so it never
competes with card entry). The wallet block is an alternate method,
rendered only when `walletOption.available` (feature enabled and a
wallet in the booking's currency exists): sufficient balance offers
"Pay <amount> from wallet", otherwise a muted "Insufficient balance"
tag — never an error style. The only navigation is a low-emphasis
"Back to my bookings" link; "Book another session" is offered only once
the booking is confirmed or the reservation has expired, so a held
reservation is not abandoned by accident.

The reservation countdown is display-only: it is computed in the
browser from the server's `reserved_until` instant (re-derived from
`Date.now()` every tick, so a slept tab catches up), and when it reaches
zero it calls `checkPaymentStatus()` — which only re-reads the booking.
Validity is decided by the server: `BookingPaymentService::initiate()`
refuses terminal or non-payable bookings, and `booking:release-expired`
cancels lapsed holds. States: pending → Pay CTA (`initiatePayment`,
disabled while the checkout is being prepared; the service reuses one
payment reference, so a repeated click cannot mint a second attempt);
failed → "Payment wasn't completed" + "Try payment again"; cancelled
while unpaid (expired hold) → "This reservation has expired" with
"Choose another time" and no Pay CTA; paid → "Booking confirmed" with
"View my bookings" / "Book another session". Provider routing, Razorpay
callback verification, Stripe polling and webhook settlement are
unchanged — the page never marks anything paid itself.

**Coverage.** `tests/Feature/Booking/BookingWizardStagesTest.php`
(stage grouping, prefill from history and profile, change-after-prefill
invalidation, same-answer reselect, price preview presence/absence,
slot-taken recovery, duplicate submit, back/edit/resume, recurring
summary, funding on review, timezone label, per-system terminology) on
top of the existing wizard suites listed elsewhere in this document.

## Booking-type scope

Exactly two booking modes exist: `free_demo` and `paid_one_to_one` ("Paid Lesson"), each single-occurrence or recurring. `BookingTypeRegistry` only ever contains these two drivers; `BookingTypeSeeder` (idempotent, `firstOrCreate` per driver) cannot create a third row through a fresh install. The Filament Booking Type form's `key` field is a closed `Select` populated from `BookingTypeRegistry::options()` with a `unique` constraint — an admin cannot type an arbitrary key.

Every booking is exclusive: one instructor + one exact time admits exactly one active booking. There is no group-capacity or shared-slot mechanism.

`BookingWizard::mount()` never silently selects a type — the phase list (`BookingWizard::phases()`) always starts with `mode` (Free Demo vs Paid Lesson) unless a valid `?type=` query param was supplied (public instructor-profile CTAs pass one explicitly). Paid types add a `billing_mode` phase (Single vs Recurring) — Free Demo skips it and never enters payment. A repeating schedule adds NO phase of its own; its fields live inside the schedule step alongside the calendar and times.

`BookingService::request()` rejects any type key that isn't an active `booking_types` row, independent of what the Livewire UI offers.

Regression coverage: `tests/Architecture/BookingTypeScopeGuardTest.php` (asserts the registry/seeded set is exactly `free_demo`, `paid_one_to_one`, and that no shared-slot/capacity mechanism has reappeared) and `tests/Feature/Booking/BookingTypeScopeTest.php` (behavioral scope: explicit selection, CTAs, recurrence, service-level rejection).

## Country-Aware Academic Demo Booking (Phase 3 / 3.1)

Free Demo and Paid Lesson always walk the country-aware `Level → Subject
→ Curriculum` flow for the student's server-resolved Country. The active
education system is selected automatically from the country's configured
mapping, so it does not consume a student-facing wizard step.
`FeatureSettings::demo_lessons_enabled` is the single global switch: if
Demo Lessons is disabled, Free Demo is unavailable; if enabled, there
is no separate academic-flow toggle and no legacy free-text fallback.
A missing/inactive student Country or incomplete academic selection
throws `BookingException` rather than degrading to another flow.

- **`EducationSystemLevel`** (`app/Models/EducationSystemLevel.php`) is
  the exact, student-selectable level under an Education System (CBSE
  "Class 10", US "Grade 10", UK "Year 10") — see
  `docs/architecture/phase-3.1-education-system-levels.md` for the full
  model rationale. Selecting one implies both the broad `AcademicLevel`
  band and a `normalized_grade` (nullable — a level with none is
  currently unsupported for lesson booking, since candidate matching is
  numeric-grade-based throughout this codebase).
- **`App\Booking\Services\DemoAcademicContextResolver`** — the Booking
  domain's composition layer. `resolveForDemo()` is the authoritative,
  throwing resolution (re-run at candidate-narrowing time AND again
  immediately before persistence — never trusted across the two calls).
  `levelsFor()`/`subjectsFor()`/`curriculaFor()`/`educationSystemsFor()`
  are thin, non-throwing progressive-loading wrappers over
  `App\Curriculum\Services\AcademicContextResolver`, optionally narrowed
  to a locked instructor's eligibility.
- **`App\Models\BookingAcademicContext`** (`booking_academic_contexts`
  table) — the immutable, per-Booking academic snapshot, created
  atomically with the `Booking` row inside `CreateBookingAction`'s
  transaction (never asynchronously). Carries denormalized display
  values (country/system/subject/curriculum names, curriculum version
  number, and the Phase 3.1 level fields `education_system_level_id`/
  `level_term`/`level_value`/`level_display`/`normalized_grade`) so a
  later admin rename never rewrites a booking's historical display.
  `PreventsHardDeletion` + `PreventsUpdates`; no admin CRUD editing
  exists for this model. `bookings.meta.grade` continues to be written
  for legacy downstream readers, sourced from the resolved level's
  `normalized_grade`.
- **Candidate narrowing**: when an academic context is present,
  `TeacherCandidateRepository` intersects the base
  `TeacherSubject`-matched candidate set with
  `InstructorCurriculumEligibility` (via
  `InstructorAcademicEligibilityResolver`) — narrowing the SET itself
  before auto-assignment, never a pick-then-reject-afterward pattern.
  A locked instructor who fails this check is rejected at final submit
  even if their `TeacherSubject` range matches.
- A historical Booking created before country-aware booking may have no
  `BookingAcademicContext` row —
  `Booking::academicContext()` is nullable by design; `booking-detail.blade.php`
  falls back to the legacy `meta.grade` display for those rows.

Regression coverage: `tests/Feature/Booking/CountryAcademicDemoBookingTest.php`
(service/domain-level: feature gating, candidate filtering, snapshot
creation/immutability/idempotency, transaction rollback, the one-free-demo
lifetime rule under academic variation, normalized-grade candidate
compatibility, historical display after a level rename),
`tests/Feature/Booking/BookingWizardAcademicFlowTest.php` (Livewire UI:
progressive selection, stale-state reset, locked-instructor narrowing,
dynamic per-system terminology), `tests/Feature/Academic/EducationSystemLevelTest.php`.

## Analytics

`BookingAnalyticsService` (cached facade, 5-min TTL) over
`BookingAnalyticsRepository` (single-round-trip aggregates only — no
per-row iteration). Surfaced on the Booking Reports page
(`/admin/booking-reports`) as widgets + charts, with a one-click
"Export KPIs (CSV)" header action (`CsvExport`).

KPIs (default period: last 30 days):

| KPI | Definition |
|---|---|
| Demo requests | `free_demo` bookings created in period |
| Conversion rate | distinct demo bookers (user id) with a later paid booking |
| Teacher utilization | booked hours ÷ (weekly schedule × weeks) — approximation, leave/holidays not subtracted |
| Popular subjects | `meta.subject` grouped (top 8) |
| Popular time slots | session-start hour (UTC), non-cancelled |
| Revenue / refunded | Σ price by payment status |
| Cancellation rate | cancelled ÷ created in period |

Performance: conditional-aggregation queries (one per KPI),
`Cache::remember` per metric+period, and dedicated `created_at` /
`starts_at` indexes on bookings. Widgets read only the cached
service — the reports page costs at most one query set per 5 minutes.

## Payment Workflow

Provider-agnostic, synchronized with booking status. The provider is
abstract: `PaymentProviderInterface` (create payment, verify
webhook) is registered in `PaymentProviderRegistry` and selected via
`BookingSettings::payment_provider`. Shipped: `fake` (no money moves;
webhooks still require an HMAC signature). Adding Stripe/Razorpay =
one class + one registry line + a settings change.

State machine (`BookingPaymentService`):

```
paid type booked ──▶ RESERVATION  status=pending, payment=pending,
        │                         reserved_until = now + booking.payment_reservation_minutes
        ├─ success  → paid, hold cleared, booking auto-confirms (unless type needs approval)
        ├─ failure  → failed, hold kept — retry via initiate() (same reference)
        ├─ hold lapses → booking:release-expired cancels (scheduled every 5 min)
        └─ refund   → refunded + active booking cancelled
cancel a PAID booking → automatic refund (SyncPaymentOnCancellation listener)
```

- **Four settlement sources, one settlement path.** Every source ends
  in `BookingPaymentSettlementService::settle()` (attempt captured →
  obligation captured → booking confirmed → receipt → notifications).
  The normal hierarchy is *callback confirms in seconds; webhook is the
  asynchronous authority; the sweep is the safety net*:
  1. **Checkout completion** (`BookingCheckoutCompletionService`): when
     Razorpay Checkout.js reports success, the server verifies the
     callback signature and that the order is this booking's, records
     the payment id, then asks Razorpay about **that specific payment**
     over the authenticated API (`PaymentAttemptVerifier::verify()`,
     `RazorpayGatewayClient::fetchPayment()`). `captured` settles in the
     same request; `authorized` is *awaiting capture*; an unreachable
     provider records a `provider_unavailable` incident. The browser's
     word alone never settles anything. The result is a
     `BookingCheckoutOutcome` whose `BookingCheckoutState`
     (`confirmed | awaiting_capture | provider_unreachable |
     needs_attention | failed | payable`) drives the student copy.
  2. **Webhook** (below) — the source when the browser never returns.
     `payment.captured` and `order.paid` both settle (the latter carries
     the same payment entity); duplicates are ignored on the attempt.
  3. **Reconciliation sweep** (`booking-payments:reconcile`, every five
     minutes, attempts older than
     `booking_payment_unknown_timeout_minutes`, 5) — the backstop for
     both. Worst-case recovery when callback and webhook both fail is
     therefore timeout + one cadence ≈ 10 minutes.
  4. **Admin retry** (`BookingPayments → Retry verification`) — the same
     pass, on demand.
- **Paid attempt, pending booking is repairable.** `settle()` marks the
  attempt Paid first and then runs the booking half in
  `completeLocalSettlement()`. If the booking half fails, a Critical
  `provider_success_local_incomplete` incident is raised and every later
  pass (sweep, poll, admin retry) re-enters
  `completeLocalSettlement()` — lock-protected and idempotent through
  `markPaid()` — instead of returning "attempt already paid" forever.
  Success closes the incident and audits `booking_payment_recovered`.
- **The confirming state is server-derived.**
  `BookingCheckoutCompletionService::currentState()` looks at the attempt
  ledger (an open attempt carrying the provider payment id, or a Paid
  attempt beside a payable booking) and the incident queue. Both the
  wizard and `BookingDetail` compute it on mount and on every poll, so a
  reload, another device or a re-login shows "Payment submitted
  successfully — please don't make another payment", never a Pay
  button; `initiatePayment()` refuses to open a second checkout while it
  is in progress. The page polls the database every 3 s and asks the
  provider at most every 15 s (`PROVIDER_RECHECK_SECONDS`).
- **Audit events** (`activity_log`, log `payments`, never a secret or a
  payload): `booking_checkout_verified`, `booking_payment_provider_verified`
  (provider status word + outcome), `booking_webhook_signature_invalid`,
  `booking_webhook_processed`, `payment_attempt_paid`,
  `booking_payment_settled` (with `source`), `booking_payment_recovered`,
  `booking_reconciliation_completed`.
- Payment transitions are recorded on the booking timeline
  (`payment_status_changed`); booking-status changes flow through
  `BookingService::confirm()/cancel()`, so events, notifications, and
  audit fire exactly like every other transition.
- `markPaid` (settle + release hold + confirm) and `recordRefund`
  (refund + cancel) each run in a single transaction — a crash cannot
  leave a paid-but-reserved or refunded-but-active booking.
- `BookingPaymentStatus` gained `Failed`; `isPayable()` gates
  initiate/retry.

## Teacher Assignment Engine

Students never directly select a teacher for auto-assigned flows. Callers build an
`AssignmentCriteriaData` (type, subject, grade, slot, timezone;
`language` reserved for the future) and
`TeacherAssignmentService::assign()` returns the teacher, whose id
then feeds `CreateBookingData`. Three phases:

1. **Hard match** — `TeacherCandidateRepository`: teaches the subject
   at the grade (`teacher_subjects`, null bounds = any grade) and is
   an approved/published instructor.
2. **Hard filter** — the slot must be bookable
   (`AvailabilityService::ensureAvailable`: hours, leave, holiday,
   buffer, daily cap).
3. **Ranking** — the strategy named by
   `BookingSettings::assignment_strategy`, resolved from
   `AssignmentStrategyRegistry`.

Strategy Pattern: implement `AssignmentStrategyInterface`, register in
`BookingServiceProvider::registerAssignmentEngine()`, switch via
settings — no core changes. Shipped: `best_score` (default) and
`least_loaded`.

Scoring engine: `best_score` sums weighted `TeacherScorerInterface`
implementations tagged `booking.assignment_scorers` — add a scorer
class + tag entry to extend. Shipped scorers: workload
(fewer upcoming bookings), priority (`user_profiles.assignment_priority`,
0–100 admin boost), timezone proximity (neutral 0.5 when unknown).
Scores are clamped to [0, 1]; ties break on lowest user id.

## Concurrency & integrity

- Every booking mutation that could race (create, reschedule) runs
  inside `BookingRepository::withInstructorLock()` — a MySQL advisory
  lock (`GET_LOCK`) serializing mutations per instructor — wrapping a
  `DB::transaction` that re-checks duplicates and availability with
  `lockForUpdate()` before writing.
- Validation runs twice by design: the pipeline fast-fails before the
  lock; the same checks re-run inside it (only the locked copy is
  authoritative).
- Every slot is exclusive — one booking = one student +
  one instructor + one slot. Any overlap, of any type, always blocks;
  there is no shared-slot/group-capacity mechanism.
- **Zoom host capacity** (feature-flagged, `docs/meetings.md` §4a):
  when on, a Zoom-bound booking also reserves the platform Zoom host
  for its occupied interval inside the same transaction. Because the
  instructor lock cannot serialize two *different* instructors, the
  host rows are row-locked first — before the availability re-read —
  and the reservation count is a locking read. Exhausted capacity
  throws inside the transaction: no booking, no hold, nothing charged.
- Booking window limits are admin-tunable via `BookingSettings`
  (`minimum_booking_notice_minutes`, `maximum_advance_booking_days`), enforced by
  `BookingWindowRule`.

## Notifications

Everything is queued on the `notifications` queue (queue driver:
database) — no synchronous mail anywhere. Two queued listeners hang
off the five domain events (Requested/Created, Confirmed, Cancelled,
Rescheduled, Completed), registered in `EventServiceProvider`:

- **`SendBookingNotifications`** — participant delivery. Teachers
  (hosts) are notified on every lifecycle event; students are notified
  via the normal notification pipeline.
  `BookingCompletedNotification` covers both Completed and NoShow
  wording.
- **`RecordBookingLifecycleAudit`** — writes semantic entries
  (`booking_requested`, `booking_confirmed`, …) to the `bookings`
  activity log via `AuditTrailService`. `NotificationMapper` maps exactly these five events to
  admin notifications through the existing pipeline
  (`ActivityCreated` → `NotifyAdminsOnActivity`); the model's generic
  `created`/`updated` audit rows stay silent, so admins see one clean
  notification per lifecycle event.

### Channels

`NotificationChannelResolver` is the single decision point, driven by
`BookingSettings` toggles: `channel_email_enabled` (on),
`channel_whatsapp_enabled` and `channel_sms_enabled` (off — future).
Booking notifications share the `RoutesBookingChannels` trait
(`via()` + `toWhatsApp()`/`toSms()` from one `plainText()` per
notification). `WhatsAppChannel` and `SmsChannel`
(`app/Notifications/Channels/`) are safe stubs that log-and-skip
until a gateway (Twilio, Meta, Vonage, …) is wired into their
`send()` — enabling a channel never breaks other deliveries, and
notifications never change when gateways arrive.

## Database

UUID primary keys on all booking tables except `booking_activities`
(bigint append-only log, `created_at` only). FKs to `users` stay
bigint. All datetimes are UTC. `bookings.student_id`/`instructor_id` are `NOT NULL` (`restrictOnDelete()`, not `cascade`, on both).

| Table | Purpose | Notes |
|---|---|---|
| `booking_types` | Tunable settings per type | `key` links to the code driver; soft deletes; `is_active` |
| `bookings` | Core booking record | Unique human `reference` (`BK-…`); payment snapshot (`price`, `currency`, `payment_reference`); meeting linkage (`meeting_provider`, `meeting_ref`, `meeting_url`); soft deletes; CHECK `starts_at < ends_at` |
| `teacher_availability` | Recurring weekly windows | `day_of_week` (Carbon numbering), optional effective date range; CHECK time range |
| `teacher_unavailability` | One-off blackouts | CHECK time range; composite overlap index |
| `booking_activities` | Domain lifecycle timeline | Complements — never replaces — the unified `activity_log` audit trail |

`BookingTypeSeeder` upserts a row per registered driver (idempotent;
admin-tuned values are preserved). Booking status/payment/location
columns are string-backed and enum-cast on the models.

## Folder structure

```
app/Booking/
├── Actions/            Single-responsibility persistence + transition guards
├── Contracts/          All interfaces (repositories, services, type driver, rule)
├── DTOs/               Immutable readonly inputs/outputs
├── Enums/               Status, payment status, location, actor
├── Events/             Domain events (past tense)
├── Exceptions/         BookingException base + specific failures
├── Registry/           BookingTypeRegistry (single source of truth for types)
├── Types/               Built-in BookingTypeInterface implementations
└── Validation/         BookingValidationPipeline (domain rules)

app/Models/Booking.php            Eloquent model (see docs/architecture/code-standards.md: models live here)
app/Policies/BookingPolicy.php    Authorization
app/Providers/BookingServiceProvider.php
```

## Architecture

```
Controller / Filament page
        │  builds DTO (via FormRequest)
        ▼
BookingServiceInterface            ← orchestration only
        │  1. BookingTypeRegistry::get($typeKey)
        │  2. BookingValidationPipeline (domain rules)
        │  3. AvailabilityServiceInterface::ensureAvailable()
        │  4. Action (persistence, DB::transaction)
        │  5. Domain event
        │  6. AuditTrailService
        ▼
BookingRepositoryInterface         ← all Eloquent queries
        ▼
Booking model
```

- **Actions** persist and guard state transitions — nothing else
  (mirrors `RegisterUserAction`). Transactions live in Actions.
- **Services** orchestrate: validate, call actions, dispatch events,
  audit. They never write raw Eloquent queries.
- **Events** are consumed by listeners that record to the Activity
  Log; notifications flow from that pipeline, never from Services
  (docs/decisions.md). Listeners are queue-ready — events use
  `SerializesModels`.
- **Payments** for paid types reuse the existing
  `app/Services/Payment` module via `BookingPaymentStatus`; the
  Booking domain never talks to gateways directly.

## Booking types

A booking type is a driver implementing `BookingTypeInterface`,
registered in `BookingServiceProvider` — the same pattern as
Navigation's `LinkTypeRegistry`. The registry key (`key()`) is the
value persisted in `bookings.booking_type`.

### Adding a new type

1. Create `app/Booking/Types/{Name}Type.php` implementing
   `BookingTypeInterface` (typed `KEY` constant, snake_case key).
2. Register it in `BookingServiceProvider::registerBookingTypes()`.
3. Optional: add type-specific domain rules (`rules()`) and HTTP
   rules (`formRules()`).

No core changes are required. (See also "Booking-type scope" above — the registry is deliberately restricted to exactly two drivers today; adding a third is a product decision, not a technical limitation.)

## Lifecycle

`BookingStatus` owns the state machine — every transition goes
through `canTransitionTo()`; Actions throw
`InvalidStatusTransitionException` otherwise.

```
Pending ──▶ Confirmed ──▶ Completed
   │            │──▶ NoShow
   └──▶ Cancelled ◀──┘
```

Types with `requiresApproval() === false` are auto-confirmed by
`BookingService::request()`.

## Validation strategy

Three layers, in order:

1. **HTTP (shape)** — FormRequests validate input shape.
   Base booking rules + `BookingTypeRegistry::get($key)->formRules()`
   merged per type. The FormRequest builds `CreateBookingData`.
2. **Domain (business)** — `BookingValidationPipeline` runs
   `BookingRuleInterface` classes: global rules (overlap, lead time)
   then the type's `rules()`. Rules are container-resolved so they
   may inject repositories/settings, and throw `BookingException`
   subclasses. Availability is enforced by
   `AvailabilityServiceInterface::ensureAvailable()` →
   `SlotUnavailableException`.
3. **Authorization** — `BookingPolicy`. Participants (student/instructor)
   manage their own bookings; staff need explicit permissions
   (`ViewAny:Booking`, `Confirm:Booking`, …). Portal routing stays
   in `PortalResolver`; the policy only answers WHAT a user may do.

## Naming conventions

| Type | Convention | Example |
|---|---|---|
| Type driver | `{Name}Type`, key snake_case | `FreeDemoType` → `free_demo` |
| DTO | `{Verb/Noun}{Noun}Data` | `CreateBookingData` |
| Action | `{Verb}BookingAction` | `ConfirmBookingAction` |
| Event | `Booking{PastTenseVerb}` | `BookingRescheduled` |
| Enum | `Booking{Concept}` | `BookingPaymentStatus` |
| Repository contract | `{Name}RepositoryInterface` | `BookingRepositoryInterface` |
| Service contract | `{Name}ServiceInterface` | `AvailabilityServiceInterface` |
| Domain rule | `{Constraint}Rule` | `MinimumLeadTimeRule` |
| Exception | `{Failure}Exception` | `SlotUnavailableException` |
| Permission | `{Ability}:Booking` | `Reschedule:Booking` |

All times are stored UTC (`CarbonImmutable`); the participant's
timezone travels on the DTO/record for display only.

## Deletion policy

Bookings are never physically deleted through the application.
`Booking` is `SoftDeletes` + `App\Support\Concerns\PreventsHardDeletion`
— `forceDelete()` throws `HistoricalRecordCannotBeDeletedException`
unconditionally, and `BookingPolicy::forceDelete()` returns `false`
unconditionally regardless of permission. The Filament resource has no
`DeleteAction`/`DeleteBulkAction`/`ForceDeleteAction`/`ForceDeleteBulkAction`
anywhere — only **Archive**/**Restore**, which delegate exclusively to
`BookingArchivalServiceInterface` (`ArchiveBookingAction`/
`RestoreArchivedBookingAction`): lock → verify terminal (archive only)
→ require a reason → soft-delete/restore → audit via
`AuditTrailService` → return a result DTO (`applied: false` on a
repeated call — idempotent, never a second write). Archiving/restoring
changes only `deleted_at`; status, payment status, and every dependent
record are left exactly as they were — restoring never replays a
lifecycle event (no meeting recreated, no notification sent, no
refund/earning/wallet/settlement change).

Every foreign key reachable from `bookings`/`lessons`/`lesson_reviews`/
`lesson_review_eligibilities` (and the `users → bookings`,
`users → wallets/wallet_ledger_entries/instructor_rating_aggregates`,
`bookings → booking_meetings` edges) is `RESTRICT`, not `CASCADE`, at
the database level — even a raw SQL `DELETE` against a booking with
any dependent lesson, attendance, financial, review, feedback,
quality, or meeting record is rejected by MySQL itself, not just by
application code. The same `PreventsHardDeletion` trait (in
delete-blocking mode for models without `SoftDeletes`) is applied to
every model in that chain — `Lesson` (force-delete-blocking, since it
does have `SoftDeletes`), `LessonReview`, `LessonReviewRevision`,
`InstructorStudentFeedback`, `LessonFinancialDisposition`,
`InstructorEarning`, `ReviewReport`, `InstructorQualityAlert`,
`LessonReviewEligibility`, `ReviewRatingContribution`,
`InstructorRatingAggregate`, the three `LessonAttendance*` models,
`LessonTechnicalIssueReport`, `WalletLedgerEntry`,
`InstructorSettlementBatch`, `NotificationDispatchLog`, and
`BookingMeeting` (no `SoftDeletes`, hooks `deleting` and rejects
unconditionally; meeting cancellation already works by transitioning
`status` rather than deleting the row —
`BookingMeetingService::cancelMeeting()`).

Permissions: `Archive:Booking` + `Restore:Booking` (manager).
`Delete:Booking`/`ForceDelete:Booking` do not exist — neither is
seeded nor grantable to anyone, including super_admin.

See `tests/Feature/Booking/BookingArchivalTest.php` for the full
archive/restore/idempotency/authorization/historical-preservation
suite.
