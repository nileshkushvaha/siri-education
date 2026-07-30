# Phase 8 Pricing, Booking Type & Checkout Readiness

## Decision

Phase 8.0 audited the existing pricing/payment state before building
anything. No pricing, payout, or wallet structures existed anywhere in
the codebase. Rather than build a new pricing table speculatively, the
existing `booking_types.price`/`currency` (one global price per type) was
confirmed sufficient for this phase and reused as-is — a decision made
explicitly with the user before implementation (see "Pricing model
decision" below). Phase 8.1 adds a `BookingPriceCalculator` on top of
that existing data, hardens the demo/paid boundary, and closes two admin
gaps found in the audit. No migration was added.

## Prerequisite

`docs/audits/phase-7-booking-flow-hardening-audit.md`: 95/100, SAFE TO
PROCEED, no blocking issues. Two minor Phase 7 items were closed first
in this phase (see below).

## Phase 7 Cleanup (completed first, in this phase)

1. `BookingFlowHardeningTest::test_final_availability_check_runs_even_when_caller_precheck_is_bypassed`'s
   docblock overclaimed that the test proves the rejection happens
   specifically *inside* the host lock. Since both the pre-lock
   `TeacherAvailabilityRule` and the in-lock re-check call the identical
   `AvailabilityService::ensureAvailable()`, and the pre-lock rule always
   runs first, the test cannot actually isolate which call site threw.
   Comment corrected to state only what the test actually proves: the
   service itself refuses even when the caller's own pre-check is
   skipped.
2. `EditBooking`'s `DeleteAction`/`ForceDeleteAction` now also
   `->visible(fn (Booking $record) => $record->status->isTerminal())`,
   hiding the button proactively for a non-terminal booking instead of
   only refusing after the confirmation modal. The `before()`/`halt()`
   guard was kept as a defense-in-depth backstop for any direct action
   call that bypasses the rendered button (verified by
   `assertActionHidden`/`assertFormFieldDisabled`-style tests).

## Existing Pricing/Payment State (Phase 8.0 audit)

Confirmed by direct inspection:

- **`booking_types`**: `price` (nullable decimal), `currency` (nullable
  char(3)), `is_paid`, `duration_minutes`, `buffer_minutes`,
  `requires_approval`, `is_active`, `sort_order` — already a complete,
  working single-global-price-per-type model. `FreeDemoType`/
  `PaidOneToOneType` (plus `CounsellingType`/`ParentMeetingType`/
  `WebinarType`) are the only drivers; no `instant_booking` or recurring
  placeholder type exists.
- **`bookings`**: `price`/`currency` are snapshotted at creation time
  (already existed pre-Phase-8); `payment_status` is a five-state enum
  (`NotRequired`/`Pending`/`Paid`/`Failed`/`Refunded`); `meeting_provider`/
  `meeting_ref`/`meeting_url` columns exist and are unused by any
  integration.
- **`BookingPaymentService`**: a clearly-marked PLACEHOLDER
  (`payment_provider = fake`). `markPaid()` already requires a matching
  `payment_reference` and can only transition from `Pending` — it cannot
  be called casually to mark something paid without a reference match.
  Untouched this phase.
- **`BookingSettings`**: `payment_reservation_minutes`,
  `payment_provider` — unchanged.
- **Country/Currency foundation**: `Country.default_currency_id` →
  `Currency` (`code`, `symbol`, `minor_units`), `UserProfile.country_id`
  → `Country`. `GeneralSettings::$default_currency` (seeded `INR`) is the
  platform-wide fallback. This foundation was solid and ready to reuse
  as-is.
- **No subject pricing, no instructor payout/earnings fields, no
  wallet, no pricing table, no Razorpay code** existed anywhere —
  confirmed by direct search, not assumption.
- **Two admin gaps found**: `BookingForm` (the Booking edit form)
  exposed freely-editable `meeting_provider`/`meeting_ref`/`meeting_url`
  text fields with no payment-status gate — an admin could type a
  meeting URL onto an unpaid booking. Neither the calculated amount nor
  the booking type's price/currency were visible anywhere in the Booking
  admin table or form (only in CSV export).

## Pricing Model Decision

Presented to the user as an explicit choice before writing any code:
reuse `booking_types.price`/`currency` only (no new table), or add a
`booking_type_country_prices` override matrix now. **The user chose reuse
only.** `BookingPriceCalculator` is built entirely on existing data — the
country/subject/duration price matrix remains a documented option for a
future phase, not built now.

## Booking Type Hardening

`BookingTypeForm`'s `currency` field was a free-text 3-character input
with no validation against real currency codes. Replaced with a `Select`
populated from `Currency::active()`, defaulting to
`GeneralSettings::default_currency` — reuses the existing Currency
foundation instead of accepting arbitrary strings. `price`/`is_paid`/
`is_active`/`duration_minutes`/`buffer_minutes` were already
well-modeled and needed no structural change.
`BookingTypeRepository::requireActiveByKey()` already rejected inactive
types with a `BookingException` (confirmed by test, not changed).

## Payable Amount Calculation

`App\Booking\Services\BookingPriceCalculator::calculate(BookingType
$type, ?User $student = null): BookingPriceData`:

```
baseAmount      = type->is_paid ? (float) (type->price ?? 0) : 0.0
discountAmount  = 0.0   (no promo system exists)
taxAmount       = 0.0   (no tax system exists)
payableAmount   = max(0, baseAmount - discountAmount + taxAmount)
currency        = type->currency ?? student's country default currency ?? GeneralSettings::default_currency
requiresPayment = type->is_paid && payableAmount > 0
isFreeBooking   = !type->is_paid || payableAmount <= 0
```

`BookingPriceData` is an immutable DTO already carrying
`discountAmount`/`taxAmount` fields (always zero today) so a future
discount or tax engine is additive — no caller's contract changes when
one arrives.

**A paid type with an admin-configured zero price is treated as free**
— `requiresPayment` is false and `isFreeBooking` is true — rather than
becoming a permanently-unpaid `Pending` reservation. This is a real,
tested behavior improvement in `BookingService::request()`, not just the
calculator: the auto-confirm/payment-status/reservation-hold decision
now uses `BookingPriceData::isFreeBooking`/`requiresPayment` instead of
the previous raw `$type->is_paid` check, so this edge case (not exercised
by any pre-Phase-8 test, since no existing fixture used `is_paid=true`
with `price=0`) now behaves correctly without changing behavior for any
existing paid/free type.

## Currency Display

No exchange-rate engine exists or was built. The calculator's `currency`
field is a **display/labeling fallback chain**, never a conversion: the
booking type's own configured currency is always the actual charge
currency when set. Only when a type has no configured currency (typically
free types) does the student's country default currency, then the
platform default, apply — purely cosmetic since the amount is zero either
way in that case.

## Booking Status Boundary

`BookingService::request()`:

- Free/demo (`isFreeBooking`): `payment_status = NotRequired`,
  `price`/`currency`/`reserved_until` stay `null`, auto-confirms when the
  type doesn't require approval — unchanged from pre-Phase-8 behavior for
  every type actually configured `is_paid = false`.
- Paid with a real price (`requiresPayment`): `payment_status = Pending`,
  `price`/`currency` snapshotted from the calculator, `reserved_until`
  set from `BookingSettings::payment_reservation_minutes` — unchanged
  from pre-Phase-8 behavior for every existing paid type.
- `BookingPaymentService::markPaid()` remains the only path to
  `payment_status = Paid`, and it already required a matching
  `payment_reference` before this phase.
- Meeting fields (`meeting_provider`/`meeting_ref`/`meeting_url`) are now
  disabled in the admin form unless `payment_status` is `Paid` or
  `NotRequired` — an unpaid booking can no longer have a meeting link
  attached through the admin panel.

## Admin / Filament

- `BookingTypesTable` already showed `price` (money-formatted); no
  duplicate `BookingTypeResource` exists or was created.
- `BookingsTable` gained a `price` column (money-formatted per-row
  currency, "Free" placeholder), alongside the existing `payment_status`
  badge column.
- `BookingForm` gained a read-only "Payment" section (payment status
  label, formatted amount, payment reference) and the meeting-field
  payment gate described above. No new editable field lets an admin set
  `payment_status` directly — it was never exposed and remains so.
- `StudentBookingResource`/`GuestBookingResource` (the JSON API
  responses) gained `requires_payment`/`is_free_booking` booleans
  alongside the existing `price`/`currency`/`payment_status` fields —
  checkout-readiness information for a future frontend, without building
  any checkout UI.

## What Is Intentionally Not Built

Razorpay integration, wallet ledger, payment capture logic, meeting link
creation, instructor payout/earnings, and any discount/tax engine. No new
migration, table, or model was added. `BookingPaymentService`,
`PaymentProviderInterface`, `FakePaymentProvider`, and the webhook
controller were not touched.

## Future Integration

- **Razorpay**: a real `PaymentProviderInterface` implementation remains
  a drop-in swap (one class + one registry line + a settings change),
  unaffected by this phase. `BookingPriceCalculator::payableAmount` is
  exactly the amount such a provider would be asked to collect.
- **Wallet ledger**: no coupling exists; a future wallet phase would hang
  off `BookingPaymentService::markPaid()`/`recordRefund()`.
- **Meeting creation**: `bookings.meeting_provider`/`meeting_ref`/
  `meeting_url` remain unused columns, now additionally guarded so they
  can only be populated (even manually, by an admin) once payment is
  settled or not required — matching the eventual rule a real meeting
  integration will need to enforce anyway.
- **Per-country/subject pricing matrix**: documented above as the
  rejected-for-now option; if a future phase needs it, add a
  `booking_type_country_prices` (or similar) table keyed by
  `booking_type_id` + `country_id`, falling back to
  `booking_types.price`/`currency` when no row matches — `Country`/
  `Currency` foreign keys already exist to support it without further
  foundation work.

## Tests

`tests/Feature/Booking/BookingPricingCheckoutReadinessTest.php` (14
tests): pricing calculation (demo zero, paid amount, admin-zero-price
treated as free, currency from country, currency fallback to
`GeneralSettings`), demo/paid boundary (unpaid never marked paid, no
meeting link, no wallet/Razorpay tables, free auto-confirms, inactive
type rejected, duration/buffer still compatible with availability),
and admin bypass prevention (meeting fields disabled while unpaid,
enabled once settled, payment status unchanged after a form save
attempt), plus an explicit no-duplicate-table schema assertion.

Full regression: `composer test` → **1902 tests passed, 4239 assertions**
(1888 baseline + 14 new), including every Phase 2–7 test file unchanged.

## Remaining Gaps

- No per-country/subject price matrix (deliberately deferred — see
  above).
- No discount or tax system exists, so those `BookingPriceData` fields
  are always zero; this is accurate today, not a placeholder bug.
- Guest bookings are not restricted from selecting a paid booking type
  at the API level (pre-existing behavior, unchanged) — the guest-facing
  public wizard only currently offers demo booking in practice, but the
  raw JSON API does not enforce that. Out of scope for this phase; worth
  a future look when Razorpay is actually wired up for guests.
