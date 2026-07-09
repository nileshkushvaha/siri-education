# Phase 10.2D — Student Pricing Matrix Foundation

> **Phase 10.2D-Cleanup update:** the legacy fallback described in this
> document as "not removed" was removed one phase later, in
> development mode, once the matrix itself was proven working. See
> the "Phase 10.2D-Cleanup" section at the end of this document for
> the current, final state — `booking_types.price`/`currency` **no
> longer exist**. The sections above it are kept for historical
> context (why the matrix was introduced, resolution priority, etc.)
> but any sentence describing the legacy fallback as still active is
> superseded.

## Why `booking_types.price`/`currency` is no longer enough

`booking_types.price`/`currency` (Phase 8) models one price per booking
*type* — a single number for every student, every subject, every
country, forever. That was enough to prove the payment pipeline
end-to-end (Phases 8-10.2C), but it cannot represent the actual SRS
requirement: a paid lesson's student-facing price depends on **billing
country, billing currency, subject, education level, and lesson
duration**, set by an admin, and must exist *before* payment can be
attempted (Phase 10.2C-Fix's guard already enforces the "before
payment" half; this phase adds the missing dimensions).

`booking_types.price`/`currency` are **not removed** — they remain a
documented legacy fallback (see below), so every pre-existing paid
booking type keeps working unchanged the moment this migration ships,
without requiring every environment's matrix to be fully populated on
day one.

## Pricing matrix fields (`student_lesson_prices`)

| Field | Notes |
|---|---|
| `booking_type_id` | FK `booking_types`, must be a paid type |
| `subject_id` | FK `subjects` |
| `academic_level_id` | FK `academic_levels`, **nullable** — null means "all levels" |
| `country_id` | FK `countries` |
| `currency_id` | FK `currencies` — source of truth for `minor_units` |
| `currency_code` | denormalized snapshot of `currency.code`, matches `booking_payments.currency_code`'s existing pattern |
| `duration_minutes` | matched against the booking type's duration |
| `amount_minor` | **integer, minor units only** — e.g. `49900` = ₹499.00. Never a float. `CHECK (amount_minor > 0)` at the DB level |
| `is_active` | inactive rows are never matched |
| `effective_from` / `effective_until` | nullable date range; a row outside its range is never matched |
| `priority` | tie-breaker (higher wins) when more than one active, effective row matches |
| `created_by` / `updated_by` | auto-set from `auth()->id()`, matching `Subject`'s convention |

Soft-deletable (`SoftDeletes`), like every other admin-managed
reference table in this module (`BookingType`, `Subject`,
`AcademicLevel`). A booking's own `price`/`currency` columns
(pre-existing, `bookings` table) still hold the point-in-time snapshot
taken at booking-creation time — deleting, deactivating, or changing a
matrix row **never** rewrites the cost of a booking already made.

## Resolution priority

Implemented in `StudentLessonPriceResolver` + `StudentLessonPriceRepository`:

1. **Exact subject + exact academic level + country + duration** — the
   most specific active, currently-effective row.
2. **Exact subject + `academic_level_id IS NULL` ("all levels") +
   country + duration** — tried only if (1) has no match.
3. No documented third fallback exists *inside the resolver* — a full
   miss there always throws `BookingException`. The **one** documented
   fallback beyond the resolver is `BookingPriceCalculator` itself
   falling back to `booking_types.price`/`currency` when either (a) no
   subject/grade context exists for the booking (e.g. `counselling`/
   `parent_meeting`/`webinar` — types with no subject dimension), or
   (b) the resolver was attempted and missed. This is the "legacy
   fallback, explicitly documented" the spec allows — see
   `BookingPriceCalculator`'s doc comment and
   `StudentLessonPriceResolverTest::test_legacy_price_still_works_as_fallback_when_no_matrix_row_matches`.
4. No match anywhere (matrix miss **and** invalid/missing legacy
   price) → `BookingException`: *"This lesson price is not configured
   yet. Please contact support."* — identical message to Phase
   10.2C-Fix's guard, now covering both sources.

When more than one row matches at the same specificity level, the
highest `priority` wins; ties break on `effective_from` then
`created_at` (rows use random UUIDs, not ordered ones, so `id` is
never used as a tie-breaker).

**Subject matching is best-effort.** A booking's subject is the same
free-text string `TeacherSubject.subject` already uses (e.g.
`'maths'`) — the resolver matches it against `Subject.slug` or
`Subject.name`. This mirrors the existing, already-documented
relationship between `TeacherSubject` and `Subject` (optional
reconciliation, not a hard link) rather than inventing a new one.
Grade → academic level uses the existing
`AcademicLevel::coversGrade()` bridge, unchanged.

## Student / admin / instructor visibility

- **Student** sees: the resolved price, currency, and payable amount
  for their own booking — unchanged surface
  (`StudentBookingResource`'s `price`/`currency` fields, `Booking`
  history/checkout Livewire components). They never see the matrix
  itself, only its resolved output snapshotted onto their booking.
- **Admin** sees: the full pricing matrix (list/create/edit, filtered
  by booking type/subject/level/country/currency/duration/status) at
  `/admin/student-lesson-prices`, plus booking and payment amounts
  exactly as before. Gated by `StudentLessonPricePolicy`
  (`ViewAny/View/Create/Update/Delete/Restore/ForceDelete:StudentLessonPrice`
  permissions, `manager`/`super_admin` only — same convention as
  `BookingTypePolicy`).
- **Instructor** sees: nothing pricing-related, structurally — not
  just by omission. `User::canAccessPanel()` gates the entire Filament
  admin panel on `PortalResolver::usesAdminPortal()`, which is `false`
  for the instructor role regardless of any permission grant; there is
  currently no instructor-facing Blade/Livewire surface that reads
  `booking.price`/`currency` at all (verified by direct grep, not
  assumed). `StudentLessonPriceAdminTest::test_instructor_cannot_access_the_pricing_admin_at_all`
  proves this holds even if every `StudentLessonPrice` permission were
  mistakenly granted to the instructor role — the panel gate, not the
  policy, is the real boundary. Platform margin (student price minus
  instructor pay) is not modeled anywhere yet — there is no instructor
  pay to compare it against (see below).

## No instructor payout — by design, not by omission

This phase does not create or touch anything about what an instructor
is paid. `Student Price ≠ Instructor Pay` is the platform principle
(SRS) this phase is laying the *student* half of the foundation for —
instructor compensation is explicitly a later earnings/settlement
phase's responsibility, and nothing here assumes or hard-codes a
relationship between `student_lesson_prices.amount_minor` and any
future instructor-pay figure.

## No wallet debit, no meeting creation

Unchanged from every prior payment phase: a paid booking still reaches
`Confirmed` only through the existing Razorpay/Stripe/fake-provider
verified-payment flow (Phase 10.2C-Hotfix's closed trust boundary is
untouched by this phase — `BookingPaymentService.php` was not
modified). No wallet ledger entry is created for a normal paid
checkout (Option B's late-terminal-payment wallet credit, Phase
10.2B, is the only wallet-writing path in the whole booking domain,
and this phase doesn't touch it). No meeting is created anywhere in
this phase.

## Future discount/tax/promo support

`BookingPriceData` already carries `discountAmount`/`taxAmount`
fields, always zero today (Phase 8 decision, unchanged). A future
discount/promo/tax engine plugs into `BookingPriceCalculator::calculate()`
after the base amount is resolved (matrix or legacy) without changing
`StudentLessonPriceResolver`'s contract — the matrix stores the base
price only, deliberately, so a discount system doesn't need N matrix
rows per promotion.

---

## Phase 10.2D-Cleanup — Legacy Fallback Removed

### Business decision

This is a development-mode codebase with no production traffic yet.
Once Phase 10.2D's matrix was proven working end-to-end, the business
decision was made to remove the legacy `booking_types.price`/`currency`
fallback immediately rather than carry it as permanent scaffolding.
Final rule: **`BookingType` defines booking behavior only.
`StudentLessonPrice` is the only student-facing paid-lesson pricing
source.**

### What changed

- `booking_types.price` and `booking_types.currency` **columns are
  dropped** (new migration; `bookings.price`/`currency`, the
  point-in-time snapshot taken at booking-creation time, and
  `booking_payments.amount_minor`/`currency_code` are untouched — they
  are unrelated columns on unrelated tables).
- `BookingType` model: `price`/`currency` removed from `$fillable`,
  casts, and activity log options.
- `BookingPriceCalculator`: the legacy-fallback branch is deleted. A
  paid booking now *always* resolves through
  `StudentLessonPriceResolver`; any miss (no subject/grade context, no
  billing country, no matching Subject row, or a genuine resolver
  miss) throws the same `BookingException`("This lesson price is not
  configured yet. Please contact support.") — there is no second
  chance via a decimal column anymore.
- `BookingTypeForm` (admin): `price`/`currency` fields removed
  entirely, replaced with a single helper note: "Student-facing paid
  prices are managed from Student Lesson Prices." `BookingTypesTable`'s
  price column and CSV export's Price/Currency columns removed.
- `BookingWizardService::bookingTypes()`: stopped reading
  `type->price`/`type->currency` for its type-selection preview array
  — confirmed via the current Blade view that neither field was ever
  actually rendered (dead data), so removing them changes no visible
  behavior.
- `BookingTypeFactory::paid()`: no longer sets `price`/`currency`
  (columns gone) — the method keeps its `(float $price, string
  $currency)` signature as unused, ignored parameters so every
  existing test call site across the suite still compiles; a type
  built this way has no price until a `StudentLessonPrice::factory()`
  row is created for it.
- New `StudentLessonPriceSeeder`, wired into `DatabaseSeeder` after
  `SubjectSeeder`: one minimal starter price
  (`paid_one_to_one` + Algebra + India + INR, `academic_level_id`
  null — applies to every level), so a fresh dev environment has at
  least one bookable paid lesson. Not a production price list.
- `StudentLessonPriceForm` gained a duplicate-active-row guard: two
  active rows with the identical match key (booking type, subject,
  academic level, country, duration) are rejected at save time —
  resolution is deterministic by design, but two truly identical rows
  are always a mistake, not a valid priority tie-break.

### Paid booking without a matrix price is always blocked

There is no remaining path — admin form, factory, seeder, or runtime —
that lets a paid `BookingType` be created or booked without an active
`StudentLessonPrice` row matching its subject/level/country/duration.
`StudentLessonPriceResolverTest` and `BookingPricingCheckoutReadinessTest`
both assert this directly (schema-level: the columns don't exist;
behavior-level: every paid-booking attempt without a matrix row throws).

### Visibility (reconfirmed, unchanged from Phase 10.2D)

Student sees the resolved payable price. Admin sees the pricing matrix
and every booking/payment amount. Instructor sees neither — the
structural guarantee (`User::canAccessPanel()` gating the entire admin
panel before any policy runs) is unchanged by this cleanup and was
re-verified, not just assumed to still hold.

### No instructor payout, no wallet debit, no meeting creation

Unchanged. This cleanup touches pricing plumbing only —
`BookingPaymentService.php` was not modified, no wallet-writing code
path was added, and no meeting-related column or table was touched.

### Test debt from this cleanup — resolved in Phase 10.2D-Cleanup-Fix

The original cleanup phase deliberately left 7 payment-domain test
files red (`RazorpayCheckoutTest`, `StripeCheckoutTest`,
`StudentCheckoutFrontendTest`, `PaymentWorkflowTest`,
`PaymentTerminalStateTest`, `CountryAwareProviderResolutionTest`,
`RazorpayCheckoutLivewireTest`) — all failing with the same
"price is not configured" error, per its own "focused tests only, do
not reread the full project" scope. Phase 10.2D-Cleanup-Fix gave each
of them a `StudentLessonPrice` fixture (see "Testing note" below) and
restored all 7 to green. `BookingAnalyticsTest`, `Guest/GuestBookingTest`,
and `Student/PaymentHistoryTest` were never actually broken — the
audit that preceded this fix traced why: `BookingAnalyticsTest` builds
`Booking` rows directly via factory, never through
`BookingService::request()`; `PaymentHistoryTest` uses
`Booking::factory()->paid()` (the snapshot-column state, untouched by
this cleanup), not `BookingType::factory()->paid()`; and
`Guest/GuestBookingTest` is rejected by `AuthenticatedAttendeeRule`
before pricing ever runs.

### Testing note — seeding a `StudentLessonPrice` in tests

`BookingType::factory()->paid()` no longer carries a price (the
`$price`/`$currency` parameters are accepted but ignored — see
`BookingTypeFactory`, kept only so existing call sites still compile).
Any test that books a paid lesson through `BookingService`/
`StudentBookingService` needs an active `StudentLessonPrice` row
matching the booking's subject, academic level (or none), billing
country, and duration, or it will fail with "This lesson price is not
configured yet." — that is the intended behavior, not a test bug.

Use `Tests\Support\CreatesStudentLessonPrices` (a trait, mixed into
the test class) rather than duplicating the five-model setup:

- `createPaidBookingTypeWithPrice($key, $amount, $currencyCode, $countryIso2 = null, ...)` —
  the common case: one new paid `BookingType` + one billing country/
  currency + one matching "all levels" price, in one call. Returns
  `['type' => ..., 'country' => ..., 'currency' => ...]`.
- `assignBillingCountry($student, $country)` — points a student's
  billing profile at a country a price was already seeded for.
- `seedStudentLessonPrice($type, $country, $currency, $amount, ...)` —
  the lower-level primitive, for tests that need several prices (e.g.
  one per country, to test routing) or an existing student/country
  they built themselves.

Every `StudentBookingData`/`CreateBookingData` call for a paid type
also needs `subject`/`grade` (or `meta: ['subject' => ..., 'grade' => ...]`)
matching the seeded price's subject — a booking with no subject/grade
context can never resolve a matrix price, by design.

**Watch for stale in-memory relations**: if a test creates a booking
*then* clears the student's `country_id` to test the
profile-completeness gate at payment time (not creation time), call
`$student->unsetRelation('profile')` before passing that `$student`
object to `Livewire::actingAs()` — otherwise the already-loaded
`profile` relation (cached on that PHP object by the earlier price
lookup) still shows the old country, and the gate never fires. This
cost real debugging time in `StudentCheckoutFrontendTest`'s
`test_incomplete_profile_blocks_pay_now` — noted here so the next
person doesn't rediscover it.

---

## Phase 10.2F — Instructor-Specific Student Price Override

### What changed

`student_lesson_prices` gained a nullable `instructor_id` (FK
`users.id`, `nullOnDelete()`, indexed alongside the existing match
columns). No new table — this is the same pricing matrix, one more
optional match dimension, exactly as instructed ("do not create a
separate instructor price table").

- `instructor_id = NULL` — the **base price**, applies to every
  instructor teaching that subject/level/country/duration combination.
  This is the only kind of row that existed before this phase, and
  every pre-existing row is unaffected (a NULL column on an existing
  row is exactly what "base price, no override" means).
- `instructor_id = <user id>` — a **student-facing price override**
  for that one instructor only. It is not instructor compensation —
  `StudentLessonPrice` has never modeled what an instructor is paid,
  and this column doesn't start. Instructor payout/earnings remain a
  separate, future, unbuilt concern.

### Resolver priority (updated)

`StudentLessonPriceResolver::resolve()` now takes an optional
`?int $instructorId` and tries, in order, stopping at the first match:

1. instructor-specific + exact academic level
2. instructor-specific + null academic level ("all levels")
3. base price (no instructor) + exact academic level
4. base price (no instructor) + null academic level ("all levels")
5. no match anywhere → `BookingException` (unchanged message/behavior)

An instructor-specific price is a full override, not a tie-breaker or
a discount applied on top of the base price — if a row exists for that
instructor, at *any* academic-level specificity, it wins over the base
price even if the base price would have matched more specifically
(e.g. instructor-specific "all levels" beats base "exact level" — see
`test_instructor_specific_all_level_price_overrides_base_exact_level_price_for_that_instructor_only`).
Inactive and not-yet-effective/expired instructor rows are ignored
exactly like base rows always were — the resolver falls through to
the base price, never straight to the "not configured" exception,
as long as a valid base price exists.

### `BookingPriceCalculator` integration

`calculate()` gained an optional `?int $instructorId` parameter.
`BookingService::request()` passes `$data->hostId` (the booking's
teacher — already a required, always-present field on
`CreateBookingData`, no new data collection needed). No other caller
of `calculate()` needed to change — the parameter defaults to `null`,
which resolves to "no instructor context, base price only," identical
to every pre-10.2F call site's behavior.

### Admin UI

`/admin/student-lesson-prices` gained an **Instructor Override**
select field (optional, searchable, options scoped to bookable
instructors via the same `whereHas('profile', ...InstructorStatus::bookable())`
convention `TeacherAvailabilityForm` already uses) and an Instructor
column/filter on the list table. Helper text: *"Leave blank to apply
this price to all instructors. Select an instructor only for a special
student-facing price override."* The existing duplicate-active-price
guard now scopes its uniqueness check to `instructor_id` too — a base
price and an instructor override for the identical subject/level/
country/duration are not a conflict; two rows with the *same*
instructor (or both null) for the same match key still are.

### Visibility (unchanged, re-confirmed)

- **Student** sees only the final resolved payable amount — unchanged
  surface, unaware an override even exists.
- **Admin** sees both base and instructor-override prices in the same
  table, filterable by instructor.
- **Instructor** sees neither. `StudentLessonPricePolicy` and
  `User::canAccessPanel()`'s portal gate are both untouched by this
  phase — an instructor still cannot reach `/admin/student-lesson-prices`
  under any permission grant, and there is still no instructor-facing
  route (booking-related or otherwise) that renders any
  `StudentLessonPrice` field, base or override — re-confirmed this
  phase, not just assumed.

### Out of scope, confirmed untouched

Instructor payout/earnings, wallet debit/recharge, meeting creation,
guest checkout, discount/tax/promo, subscriptions/packages, recurring
wallet deduction — none touched. No new table.
