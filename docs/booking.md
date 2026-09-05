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

1. `php artisan migrate --force` — includes settings migrations.
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
   `booking:release-expired` (unpaid reservation cleanup, every
   5 min) and the existing prune jobs.
6. `npm run build` — the booking wizard ships compiled Tailwind.

Settings checklist (Spatie `booking` group): `payment_provider`
(**`fake` moves no money** — implement a real
`PaymentProviderInterface` before enabling paid types publicly),
`payment_reservation_minutes`, `captcha_enabled` +
`turnstile_site_key`/`turnstile_secret_key`,
`max_daily_bookings_per_teacher`, `minimum_booking_notice_minutes`,
`maximum_advance_booking_days`, notification channel toggles.

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
- **Recurring** books up to 12 occurrences on a `Daily` or `Weekly` cadence (`RecurrenceFrequency` enum), tagged with a shared
  `meta.recurring_group` uuid; conflicting occurrences are skipped and reported in
  `failures` (`RecurringBookingResult::$failures`) — all failing is a 422. The wizard's `WizardBookingService::bookRecurring()`
  resolves the instructor once (locked via deep-link, or auto-assigned for the first occurrence) and reuses that same instructor for every later occurrence.
  Recurrence is rejected on a non-paid type with a `BookingException`.
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

## Booking-type scope

Exactly two booking modes exist: `free_demo` and `paid_one_to_one` ("Paid Lesson"), each single-occurrence or recurring. `BookingTypeRegistry` only ever contains these two drivers; `BookingTypeSeeder` (idempotent, `firstOrCreate` per driver) cannot create a third row through a fresh install. The Filament Booking Type form's `key` field is a closed `Select` populated from `BookingTypeRegistry::options()` with a `unique` constraint — an admin cannot type an arbitrary key.

Every booking is exclusive: one instructor + one exact time admits exactly one active booking. There is no group-capacity or shared-slot mechanism.

`BookingWizard::mount()` never silently selects a type — the phase list (`BookingWizard::phases()`) always starts with `mode` (Free Demo vs Paid Lesson) unless a valid `?type=` query param was supplied (public instructor-profile CTAs pass one explicitly). Paid types add a `billing_mode` phase (Single vs Recurring) and, if recurring, a `frequency` phase (Daily/Weekly + occurrence count) — Free Demo skips both and never enters payment.

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
  `Booking::academicContext()` is nullable by design; `booking-history.blade.php`
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

- **Webhook**: `POST /api/webhooks/bookings/payments/{provider}` —
  the provider's `parseWebhook()` verifies authenticity (401 on
  failure) and normalizes to `succeeded|failed|refunded` + reference.
  Idempotent: replays, unknown references, and out-of-state events
  answer 200 `ignored` so providers stop retrying.
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
