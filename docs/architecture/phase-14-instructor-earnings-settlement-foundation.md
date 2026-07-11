# Phase 14 — Instructor Earnings & Settlement Foundation

A clean earnings ledger for instructors: one earning per eligible
completed lesson, a hold → release → settle lifecycle, admin-configured
payout rules, and settlement batches. **No external payout is executed
anywhere** — marking a batch paid is a manual admin record that money
moved outside the system.

## Student price vs instructor earning

They are different numbers with different audiences, never conflated:

| | Student price | Instructor earning |
|---|---|---|
| Managed by | Pricing matrix (`student_lesson_prices`) | Platform rules (`InstructorEarningSettings`) |
| Stored on | Booking (`price`) / `booking_payments.amount_minor` | `instructor_earnings.earning_amount_minor` |
| Visible to | Student + admin | Instructor + admin |
| Hidden from | Instructor (matrix + snapshot) | Student |

`instructor_earnings.student_amount_minor` and
`platform_margin_minor` are **admin-only**: `$hidden` on the model
(with `calculation_value`, `notes`, `metadata`), so no serialization
an instructor can reach ever contains them — test-verified. The
Filament earnings table shows them only inside the admin panel.

## Data model

- **`instructor_earnings`** — one per lesson (unique `lesson_id`),
  UUID PK, integer-minor-unit money only (no floats anywhere),
  academic snapshot FKs copied from the lesson, `calculation_type`
  (percentage/fixed/manual-reserved) + `calculation_value`, status
  (`pending_hold`, `releasable`, `settled`, `reversed`,
  `disputed_hold`, `cancelled`), hold/release/settle/reverse
  timestamps, `settlement_batch_id`, `source_type`/`source_id`.
  `InstructorEarningStatus::canTransitionTo()` owns the state machine;
  `TransitionInstructorEarningAction` guards every write. Settled /
  reversed / cancelled are terminal (clawback of settled money is a
  future manual concern).
- **`instructor_settlement_batches`** — one instructor + one currency,
  unique `ISB-…` reference, integer total, status machine
  `draft → approved → paid` (+ `processing`/`failed` reserved for the
  future external-payout phase, `cancelled` from draft/failed only).

## Payout rule settings (`InstructorEarningSettings`, group `instructor_earnings`)

| Setting | Default | Meaning |
|---|---|---|
| `earnings_enabled` | true | Kill switch for automatic creation |
| `default_calculation_type` | percentage | Global default rule |
| `default_percentage` | 70.0 | Instructor share of the student amount |
| `default_fixed_amount_minor` | null | Fixed rate; also the free/demo lesson rate |
| `default_currency_code` | null | Currency for fixed earnings on free lessons |
| `hold_days` | 7 | Dispute window before release |
| `auto_release_enabled` | true | Gates the hourly release sweep |
| `minimum_settlement_amount_minor` | null | Minimum batch total |
| `settlement_frequency` | manual | Informational; batches are admin-created |

Global defaults only — per-instructor and per-subject rules are a
future phase. Admin page: `/admin/settings/instructor-earnings`.

## Trigger & eligibility

`LessonCompleted` (new domain event, dispatched by
`LessonLifecycleService` on manual, admin, and auto completion) →
queued `CreateEarningOnLessonCompleted` →
`InstructorEarningService::createFromLesson()`. Never from payment
success, booking confirmation, meeting creation, or a frontend action.

Eligibility (re-checked in the service): lesson `completed` with
`completed_at`; instructor present; booking confirmed/completed;
booking payment `paid` or `not_required`. No earning for
pending/cancelled/disputed/no-show lessons, Option B late terminal
payments (booking no longer confirmed), or manual payment records
without a completed lesson. One earning per lesson is DB-enforced and
service-idempotent — duplicate completions are no-ops.

## Calculation

- **Student amount (admin-only)**: the captured `booking_payments` row
  is authoritative (already integer minor units); fallback is the
  booking's decimal `price` converted via `currencies.minor_units`
  using string/integer math (never floats). Free/demo → null.
- **Percentage** (default): `floor(student_minor × pct / 100)`;
  margin = student − earning (never negative for pct ≤ 100).
- **Fixed**: `default_fixed_amount_minor`; also the automatic fallback
  for free/demo lessons under percentage mode.
- **Blocked, never guessed**: percentage with no student amount and no
  fixed fallback, fixed rate exceeding the student amount (negative
  margin), missing fixed currency, out-of-range percentage — each
  skips creation and writes an `earning_calculation_blocked` audit
  entry for manual admin handling (`manual` calculation type is
  reserved for that future flow).

## Hold / release

Creation → `pending_hold`, `hold_until = completed_at + hold_days`.
`instructor-earnings:release` (hourly, `withoutOverlapping`, logs to
`storage/logs/instructor-earnings-release.log`) promotes due holds to
`releasable` + `released_at`; idempotent; respects
`auto_release_enabled`; never touches disputed/reversed/settled rows.
Admin may release early via the panel (service `override`).

Dispute sync: lesson disputed → earning parked `disputed_hold`
(detached from any open batch); dispute resolved by re-completion →
back to `pending_hold`; lesson cancelled after dispute → `reversed`.
Reversal of an earning assigned to a batch is blocked — cancel the
batch first.

## Settlement lifecycle

Admin drafts a batch (panel header action → service) from one
instructor's releasable, unassigned earnings in one currency,
optionally date-bounded — instructor mixing and currency mixing are
impossible by construction; empty and below-minimum batches are
rejected. Assigned earnings leave the pool immediately (no
double-batching). `draft → approved` (approved_at/by) → `mark paid`
(manual, own `MarkPaid` permission): batch `paid` + `payment_reference`,
earnings `settled` — terminal, never reusable. Draft/failed batches
cancel safely: earnings detach and return to the pool.

## Visibility

- **Admin** (`Earnings` navigation group, deny-by-default via
  `InstructorEarningPermissionSeeder`): earnings list (filters:
  instructor/status/currency/date; admin-only student-amount + margin
  columns; release/reverse actions), settlement batches list
  (create/approve/mark-paid/cancel actions), settings page. No
  create/edit forms for earnings; settled rows have no mutations.
- **Instructor**: no frontend earnings UI this phase (documented gap,
  next sub-phase). `InstructorEarningPolicy` /
  `InstructorSettlementBatchPolicy` already enforce: view own only;
  no release/reverse/approve/markPaid; and the model layer hides
  student amount, margin, calculation rule, notes, and metadata from
  every serialization — all test-verified.

## Events / notifications

`InstructorEarningCreated`, `InstructorEarningReleased`,
`InstructorSettlementPaid` are dispatched with **no listeners this
phase** — the subscription points for a future notification phase.
Audit flows through `AuditTrailService` (log name
`instructor_earnings`); no NotificationMapper entries yet, no
WhatsApp/SMS.

## Explicitly not built (deferred)

Real bank transfers, RazorpayX / Stripe Connect payout integration,
tax/TDS/GST engine, invoice PDF generation, student wallet debit,
instructor withdrawal UI, refunds, per-instructor/per-subject earning
rules, settled-earning clawback, instructor frontend earnings page.
No wallet, payment provider, meeting provider, pricing matrix, or
lesson flow behavior changed.

## Deployment runbook

1. `php artisan migrate --force` — creates both tables + seeds
   `instructor_earnings.*` settings defaults.
2. `php artisan db:seed --class=InstructorEarningPermissionSeeder --force`
   — **mandatory**: deny-by-default.
3. Queue worker (`notifications` queue) — earning listeners are queued.
4. Scheduler cron — gates `instructor-earnings:release` (hourly).

## Tests

`tests/Feature/Earnings/`: `InstructorEarningTest` (creation,
eligibility incl. late-terminal/unpaid, calculation paths + blocks,
hold/release sweep + kill switch + override, dispute park/restore,
cancellation reversal, serialization hiding, wallet boundary),
`InstructorSettlementTest` (batch drafting/isolation, approve → paid →
settled, no reuse, cancellation, minimums, Http::assertNothingSent),
`InstructorEarningPermissionSeederTest`, `InstructorEarningAdminPanelTest`.
Phase 10.2D's boundary test was updated: `instructor_earnings` now
exists by design; `instructor_payouts` still must not.
