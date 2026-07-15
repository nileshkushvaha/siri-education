# Booking Engine

Enterprise booking foundation supporting the SRS Version 1 appointment
types (Free Demo, Paid Lesson — single or recurring) behind one
lifecycle, one registry, and one set of contracts.

## Status

Engine complete: foundation, database layer, and concrete
implementations (`BookingService`, `AvailabilityService`,
`BookingRepository`, `AvailabilityRepository`,
`BookingTypeRepository`), domain rules, queued participant
notifications, and listeners — all bound in `BookingServiceProvider`.
Filament resources, controllers, and frontend land next.

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

## Admin Panel (Filament)

Navigation group **Bookings**: Bookings, Booking Types, Teacher
Availability, Teacher Leave (`TeacherUnavailability` model), and a
Reports page. All follow the Schemas/Tables delegation pattern.

- **Bookings** has no Create page by design — bookings are created by
  the engine. Lifecycle row/bulk actions (confirm, cancel, reschedule,
  complete, no-show) call `BookingServiceInterface`, so guards,
  locking, timeline, events, and notifications always run;
  `BookingException` surfaces as a danger notification. Status tabs,
  filters (status, payment, type, teacher, date range, trashed),
  Guests + Timeline relation managers, soft deletes, CSV export.
- **Booking Types** restricts `key` to registered drivers
  (`BookingTypeRegistry::options()`), has a Bookings relation manager,
  activate/deactivate bulk actions, soft deletes, CSV export.
- **Teacher Availability / Leave** filter teacher selects to
  approved/published instructors; availability has activate/deactivate
  bulk actions; leave defaults to current-or-upcoming filter. Create,
  edit, delete, and publish-style actions — including table row
  `DeleteAction` and bulk `DeleteBulkAction`, hardened in Phase 6.3 —
  run through `InstructorAvailabilityService` / `InstructorTimeOffService`
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
- **Service-level ownership/admin guards (Phase 6.3)**: policies gate
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

1. `php artisan migrate --force` — includes settings migrations and
   the one-way token-hashing data migration (2026_07_04_100000).
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
6. `npm run build` — the guest wizard/manage pages ship compiled
   Tailwind.

Settings checklist (Spatie `booking` group): `payment_provider`
(**`fake` moves no money** — implement a real
`PaymentProviderInterface` before enabling paid types publicly),
`payment_reservation_minutes`, `captcha_enabled` +
`turnstile_site_key`/`turnstile_secret_key`,
`max_daily_bookings_per_teacher`, `min_lead_hours`,
`max_advance_days`, notification channel toggles.

## Subject normalization plan (deferred)

`subject` currently lives as strings in `teacher_subjects.subject` and
`bookings.meta.subject` (used by matching + analytics). Normalizing is
deliberately deferred; when subjects need admin CRUD or i18n:

1. Create `subjects` (uuid, slug unique, name, is_active) and seed
   from `SELECT DISTINCT subject FROM teacher_subjects`.
2. Add nullable `subject_id` FKs to `teacher_subjects` and `bookings`;
   backfill by slug (bookings via `meta->>'$.subject'`).
3. Dual-write in `TeacherCandidateRepository` + booking meta; switch
   readers (matching, analytics `popularSubjects`) to the FK.
4. Drop the string column / stop writing meta.subject once verified.

Each step is independently deployable and reversible until step 4.

## Guest Booking API

Unauthenticated REST API under `/api/v1/guest` (routes/api.php).
Guests never see teachers — availability is aggregated across eligible
teachers and the assignment engine picks one on booking.

| Method | Endpoint | Throttle |
|---|---|---|
| GET | `/availability/dates?type&subject&grade&from&to&timezone` | 30/min per IP |
| GET | `/availability/slots?type&subject&grade&date&timezone` | 30/min per IP |
| POST | `/bookings` | 5/min + 20/day per IP |
| GET | `/bookings/{reference}?token` | 30/min per IP |
| POST | `/bookings/{reference}/cancel` | 5/min + 20/day per IP |
| POST | `/bookings/{reference}/reschedule` | 5/min + 20/day per IP |

- **Guest identity** lives on the booking (`guest_name`, `guest_email`,
  `guest_phone`; `attendee_id` is nullable, a CHECK requires one of
  the two). Guest attendees receive mail via on-demand notification
  routing.
- **Authorization without auth**: creating a booking returns a 64-char
  `manage_token` exactly once (plus a ready `manage_url`). Only its
  SHA-256 hash is stored (`bookings.manage_token`); lookups hash the
  presented token and compare with `hash_equals`. A bad token is
  answered with 404, never revealing whether the reference exists.
- **Manage page**: `/book/manage/{reference}?token=…`
  (`booking.manage`) lets guests view, cancel, and reschedule using
  the same API endpoints. The link embeds the capability token by
  design (signed-URL pattern) — treat access logs accordingly.
- **Spam protection**: honeypot `website` field (`prohibited` rule),
  aggressive write throttles, a cap of 3 active upcoming bookings per
  guest email (`GuestBookingService::MAX_ACTIVE_PER_EMAIL`), and
  optional **Cloudflare Turnstile** (`BookingSettings::captcha_enabled`
  + site/secret keys; `App\Rules\TurnstileToken`, implicit so absent
  tokens fail). Authenticated flows are exempt.
- **Performance**: `availableDates` streams per teacher (date strings
  only, never slot objects) and stops once every day in the range has
  coverage — measured: 25 teachers × 30 days ≈ 0.8 s / bounded memory
  (previously OOM). Add caching only if real traffic demands it.
- **Errors**: `BookingException` renders as JSON 422 on `api/*`
  (bootstrap/app.php); validation failures are standard 422s.
- FormRequests live in `app/Http/Requests/Api/Guest/` (the store
  request merges per-type driver `formRules()`); resources in
  `app/Http/Resources/Guest/`; thin controllers in
  `app/Http/Controllers/Api/Guest/` delegate to
  `GuestBookingServiceInterface`.

## Guest Booking Frontend

Public wizard at `/book` (`GuestBookingPageController` →
`resources/views/booking/create.blade.php`, layout
`layouts.frontend`). Single-page Alpine.js component — every step is
AJAX against `/api/v1/guest`; no page reloads.

Flow: type → subject → grade → calendar → slots → guest details →
confirmation. Two catalog endpoints power steps 1–2:
`GET /booking-types` and `GET /subjects` (`GuestCatalogController`).

- Reusable Blade components in `resources/views/components/booking/`:
  `progress`, `step`, `option-card`, `field`, `alert`, `spinner`.
- **Gotcha**: on Blade components, Alpine bindings need `::attr`
  (single `:attr` is a Blade PHP expression binding).
- Live validation client-side on blur, server 422s mapped back onto
  fields; domain errors (slot taken, no teacher, 429) surface in a
  dismissible retry banner.
- Accessibility: `aria-current` progress, `aria-pressed` option
  buttons, focus moved to each step's heading, `aria-live`
  announcements, honeypot hidden from assistive tech.
- Browser timezone auto-detected and sent on every availability call;
  calendar is capped to the 90-day booking window.
- The `manage_token` is displayed once on the confirmation step with
  copy-to-clipboard.

## Student Booking (authenticated)

Session-auth JSON endpoints under `/dashboard/bookings` (same
middleware stack as the student dashboard), backed by
`StudentBookingServiceInterface` — a thin layer over the core engine;
every occurrence still runs through `BookingService::request` (rules,
locks, events, notifications identical to guest/admin flows).

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
- **Recurring** books up to 12 weekly (or n-weekly) occurrences,
  tagged with a shared `meta.recurring_group` uuid; conflicting
  occurrences are skipped and reported in `failures` — all failing is
  a 422.
- **Payments**: paid types return a payment intent on creation.
  `BookingPaymentServiceInterface` is bound to a clearly marked
  PLACEHOLDER (`BookingPaymentService`) — generates references,
  verifies them on `pay`, flips `payment_status` to Paid, and records
  a timeline entry. Swap the binding for an
  app/Services/Payment-backed gateway; callers don't change.
  `BookingPolicy::pay` restricts settlement to the attendee (or
  `Update:Booking`).
- `BookingActor::forUser()` is the shared actor-resolution helper.
- JSON error handling for non-`api/*` endpoints comes from
  `expectsJson()` in bootstrap/app.php (exceptions + BookingException
  → 422).

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
| Conversion rate | distinct demo bookers (user id / guest email) with a later paid booking |
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
abstract: `PaymentProviderInterface` (create payment, refund, verify
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

Guests and students never select a teacher. Callers build an
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
- Every slot is exclusive (Phase 17U.3A) — one booking = one student +
  one instructor + one slot. Any overlap, of any type, always blocks;
  there is no shared-slot/group-capacity mechanism.
- Booking window limits are admin-tunable via `BookingSettings`
  (`booking.min_lead_hours`, `booking.max_advance_days`), enforced by
  `BookingWindowRule`.

## Notifications

Everything is queued on the `notifications` queue (queue driver:
database) — no synchronous mail anywhere. Two queued listeners hang
off the five domain events (Requested/Created, Confirmed, Cancelled,
Rescheduled, Completed), registered in `EventServiceProvider`:

- **`SendBookingNotifications`** — participant delivery. Teachers
  (hosts) are notified on every lifecycle event; attendees are users
  or guests (guests via on-demand mail routing to `guest_email`).
  `BookingCompletedNotification` covers both Completed and NoShow
  wording.
- **`RecordBookingLifecycleAudit`** — writes semantic entries
  (`booking_requested`, `booking_confirmed`, …) to the `bookings`
  activity log via `AuditTrailService` (guest bookings use the Guest
  actor). `NotificationMapper` maps exactly these five events to
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
bigint. All datetimes are UTC.

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
├── Enums/              Status, payment status, location, actor
├── Events/             Domain events (past tense)
├── Exceptions/         BookingException base + specific failures
├── Registry/           BookingTypeRegistry (single source of truth for types)
├── Types/              Built-in BookingTypeInterface implementations
└── Validation/         BookingValidationPipeline (domain rules)

app/Models/Booking.php            Eloquent model (standards.md: models live here)
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

No core changes are required.

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

1. **HTTP (shape)** — FormRequests (phase 2) validate input shape.
   Base booking rules + `BookingTypeRegistry::get($key)->formRules()`
   merged per type. The FormRequest builds `CreateBookingData`.
2. **Domain (business)** — `BookingValidationPipeline` runs
   `BookingRuleInterface` classes: global rules (overlap, lead time)
   then the type's `rules()`. Rules are container-resolved so they
   may inject repositories/settings, and throw `BookingException`
   subclasses. Availability is enforced by
   `AvailabilityServiceInterface::ensureAvailable()` →
   `SlotUnavailableException`.
3. **Authorization** — `BookingPolicy`. Participants (attendee/host)
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

All times are stored UTC (`CarbonImmutable`); the attendee's
timezone travels on the DTO/record for display only.

## Deletion policy (Phase 17U.1)

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
`bookings → booking_guests/booking_meetings` edges) was changed from
`CASCADE` to `RESTRICT` at the database level — even a raw SQL
`DELETE` against a booking with any dependent lesson, attendance,
financial, review, feedback, quality, guest, or meeting record is
rejected by MySQL itself, not just by application code. Zero foreign
keys referencing `bookings` remain `CASCADE` (Phase 17U.2 §1 closed
the `booking_guests`/`booking_meetings` residual deliberately left
open by Phase 17U.1 — see
`database/migrations/2026_09_05_100000_restrict_booking_guest_and_meeting_deletes.php`).
The same `PreventsHardDeletion` trait (in delete-blocking mode, since these
models have no `SoftDeletes`) is applied to every model in that
historical chain — `Lesson` (force-delete-blocking, since it does have
`SoftDeletes`), `LessonReview`, `LessonReviewRevision`,
`InstructorStudentFeedback`, `LessonFinancialDisposition`,
`InstructorEarning`, `ReviewReport`, `InstructorQualityAlert`,
`LessonReviewEligibility`, `ReviewRatingContribution`,
`InstructorRatingAggregate`, the three `LessonAttendance*` models,
`LessonTechnicalIssueReport`, `WalletLedgerEntry`,
`InstructorSettlementBatch`, `NotificationDispatchLog`, and — as of
Phase 17U.2 §1 — `BookingMeeting` (no `SoftDeletes`, hooks `deleting`
and rejects unconditionally; meeting cancellation already worked by
transitioning `status` rather than deleting the row —
`BookingMeetingService::cancelMeeting()`). `BookingGuest` itself was
removed entirely in Phase 17U.3 (see below) rather than kept under
this protection — there is no unauthenticated/guest participant
concept left to protect.

Permissions: `Archive:Booking` + `Restore:Booking` (manager).
`Delete:Booking`/`ForceDelete:Booking` no longer exist — neither is
seeded nor grantable to anyone, including super_admin.

See `tests/Feature/Booking/BookingArchivalTest.php` for the full
archive/restore/idempotency/authorization/historical-preservation
suite, and `tests/Feature/Audit17T/BookingForceDeleteCascadeWipesFinancialHistoryTest.php`
(Phase 17T Finding S-2, remediated here) for the regression proof.

## Authenticated-only booking (Phase 17U.3)

Every booking participant is an authenticated, verified platform user.
There is no unauthenticated guest booking concept anywhere in this
domain — not a data shape, not an API surface, not a UI path.

- **Schema**: `bookings.student_id`/`instructor_id` are `NOT NULL`
  from the baseline migration (`restrictOnDelete()`, not `cascade`, on
  both — historical business records, same principle as Phase 17U.1).
  `guest_name`/`guest_email`/`guest_phone`/`manage_token` never exist.
  `booking_guests` never exists.
- **Terminology**: `BookingActor::Attendee`/`::Host` are
  `BookingActor::Student`/`::Instructor`; `Booking::attendee()`/`host()`
  are `Booking::student()`/`instructor()`; every DTO/repository method
  using `attendeeId`/`hostId` uses `studentId`/`instructorId`. Scoped
  to the Booking domain's own participant concept only — `teacher`
  (marketplace/matching context: `TeacherAssignmentService`,
  `teacher_subjects`, `TeacherAvailability`) and meeting-provider `host`
  fields (`booking_meetings.host_url`, Zoom `host_user_id`/`host_email`)
  are untouched, both legitimate, unrelated uses of similar words.
- **The `/book` wizard** (`BookingWizard` Livewire component,
  `WizardBookingService`/`WizardBookingData`, renamed from the
  pre-authenticated-only "guest" naming) is the one UI that creates a
  booking with auto-teacher-assignment and drives payment; the route
  requires `auth` (redirects to login, preserving the intended URL —
  no slot/price state is preserved across that boundary, the student
  just picks again once logged in). `WizardBookingService::book()`
  also independently refuses when unauthenticated — defense-in-depth
  below the route middleware, since `CreateBookingData::$studentId` is
  a non-nullable `int` and must never be asked to accept a null one.
  `/dashboard/bookings/*` (`StudentBookingController`,
  `StudentBookingServiceInterface`) is the explicit-teacher-choice +
  recurring-booking flow; both funnel through the same
  `BookingService::request()` core.
- **Removed entirely**: the `/api/v1/guest` API (catalog, availability,
  booking CRUD, payment — all of it, including the read-only browse
  endpoints), `GuestBookingPageController`/`GuestBookingService`/
  `GuestBookingServiceInterface`/`GuestBookingData`, `BookingGuest` +
  `booking_guests`, `AuthenticatedAttendeeRule` (superseded by the
  type system itself), `/book/manage/{reference}` + its capability
  token, and every guest-specific test.
- **Permanent guard**: `tests/Architecture/BookingGuestRemovalGuardTest.php`
  fails the build if guest-booking classes/tables/columns/routes
  reappear, or if `attendee`/`host` identifiers creep back into the
  Booking domain's own source — while explicitly protecting the
  legitimate, unrelated uses of "guest" (Laravel's `guest` middleware,
  the Activity Log's generic guest-actor system used by public forms).

## Booking-type scope: Free Demo and Paid Lesson only (Phase 17U.3A)

- **Version 1 booking modes**: exactly two — `free_demo` and
  `paid_one_to_one` ("Paid Lesson"), each single-occurrence or
  recurring (daily or weekly). `Counselling`, `Parent Meeting`, and
  `Webinar` (type drivers, seed rows, admin options) are removed
  entirely — no disabled seeds, reserved enum cases, or hidden admin
  config. `BookingTypeRegistry` only ever contains the two approved
  drivers; `BookingTypeSeeder` (idempotent, `firstOrCreate` per driver)
  can therefore never create a third row through a fresh install.
- **No group-capacity / shared-slot mechanism**: `booking_types.max_attendees`,
  `BookingTypeInterface::maxAttendees()`, `sharedSlotTypeKey`
  (threaded through `AvailabilityService`/`BookingRepository::hasOverlap()`),
  `BookingService::sharedSlotKey()`/`assertCapacity()`, and
  `BookingRepository::studentCountForSlot()` are all removed. Every
  booking is now unconditionally exclusive — one instructor + one
  exact time admits exactly one active booking, enforced by the same
  overlap check `hasOverlap()` always ran, just without the shared-slot
  carve-out. `TimeSlotData::$remainingCapacity` is gone; a returned
  slot is simply bookable.
- **Explicit wizard selection**: `BookingWizard::mount()` never
  silently selects a type (the old `collect($this->types)->first()['key']`
  default — DB/array ordering — is gone). The wizard's phase list
  (`BookingWizard::phases()`) always starts with `mode` (Free Demo vs
  Paid Lesson) unless a valid `?type=` query param was supplied (the
  public instructor-profile CTAs pass one explicitly); a generic CTA
  with no query params lands on the mode step with nothing
  preselected. Paid types add a `billing_mode` phase (Single vs
  Recurring) and, if recurring, a `frequency` phase (Daily/Weekly +
  occurrence count) — Free Demo skips both and never enters payment.
- **Recurring bookings**: `RecurrenceData` supports both `Daily` and
  `Weekly` (`RecurrenceFrequency` enum), not weekly-only. The wizard's
  `WizardBookingService::bookRecurring()` resolves the instructor once
  (locked via deep-link, or auto-assigned for the first occurrence)
  and reuses that same instructor for every later occurrence in the
  series; occurrences that lose availability are skipped and reported
  (`RecurringBookingResult::$failures`), never silently dropped.
  `StudentBookingService::bookRecurring()` (the explicit-teacher
  `/dashboard/bookings` API) shares the same `RecurrenceData` DTO.
  Both reject recurrence on a non-paid type with a `BookingException`.
- **Service-level validation, not just UI**: `BookingService::request()`
  rejects any type key that isn't an active `booking_types` row —
  structurally impossible for a removed type to reach it, since the
  row never exists. `WizardBookingService`/`StudentBookingService`
  reject recurrence on Free Demo directly, independent of the Livewire
  validation that also prevents the UI from offering it.
- **Admin restriction**: the Filament Booking Type form's `key` field
  is a closed `Select` populated from `BookingTypeRegistry::options()`
  (currently 2 entries) with a `unique` constraint — an admin cannot
  type an arbitrary key or recreate a removed type. The capacity field
  is gone from both the form and the table/CSV export.
- **Permanent guard**: `tests/Architecture/BookingTypeScopeGuardTest.php`
  fails the build if the Counselling/Parent-Meeting/Webinar drivers,
  the capacity column/methods, or the shared-slot mechanism reappear,
  and asserts the registry/seeded set is exactly `free_demo`,
  `paid_one_to_one`. `tests/Feature/Booking/BookingTypeScopeTest.php`
  covers the behavioral scope (explicit selection, CTAs, recurrence,
  service-level rejection).
