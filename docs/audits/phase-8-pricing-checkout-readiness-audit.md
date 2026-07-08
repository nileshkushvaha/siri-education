# Phase 8.1 Strict Pricing & Checkout Readiness Audit

## Executive Decision

Readiness score: **95/100**

Decision: **SAFE TO PROCEED TO PHASE 9**

Blocking issues: **none**

This is an independent re-verification of Phase 8 — every claim below was
checked by reading the current source, tracing the raw guest API code
path end-to-end, and re-running every verification command fresh in this
session, not by restating the Phase 8 implementation summary. No
duplicate pricing/payment/wallet structure exists, paid bookings cannot
be marked paid without a matching payment reference, meeting links cannot
be attached to an unpaid booking (verified against Filament's own
`disabled()` security model, not just its visible behavior), and
Razorpay/wallet/meeting capture were not implemented. Two non-blocking
findings were identified (below); neither is a defect requiring a fix
before Phase 9.

## 1. Files Created

| File | Purpose | Necessity | Duplicate Risk | Architecture Assessment |
|---|---|---|---|---|
| `app/Booking/DTOs/BookingPriceData.php` | Immutable result of a price calculation. | Yes — every caller needs a typed, stable shape. | None. | Matches the existing `PaymentIntentData`/`CreateBookingData` readonly-DTO convention exactly. |
| `app/Booking/Services/BookingPriceCalculator.php` | Single source of truth for what a booking costs, built on existing `booking_types.price`/`currency` only. | Yes — replaces ad-hoc inline pricing logic that was duplicated conceptually between `BookingService` and any future caller. | None — confirmed no other pricing service exists. | Correctly stateless, no persistence, no side effects; injected via constructor like every other `App\Booking\Services\*` class. |
| `tests/Feature/Booking/BookingPricingCheckoutReadinessTest.php` | Phase 8 test coverage. | Yes. | None. | Follows existing test file conventions (factories, `RefreshDatabase`, `permittedManager()` helper mirroring prior phases' patterns). |
| `docs/architecture/phase-8-pricing-booking-type-checkout-readiness.md` | Architecture record. | Yes. | None. | Consistent with the Phase 6/7 architecture doc format. |

No model, migration, or Filament resource was created — confirmed by
filesystem search (see §17).

## 2. Files Modified

| File | What changed | Why | Backward-compatible | Affects |
|---|---|---|---|---|
| `app/Booking/Services/BookingService.php` | `request()` now computes a `BookingPriceData` via the injected `BookingPriceCalculator` and uses `isFreeBooking`/`requiresPayment` (instead of raw `$type->is_paid`) to decide auto-confirm, `payment_status`, the `price`/`currency` snapshot, and `reserved_until`. Added `attendeeFor()` helper (loads the attendee `User` for currency-fallback purposes only). | Centralizes pricing logic in one place instead of inline ternaries; correctly handles a paid type with an admin-configured zero price as free (previously would have created a permanently-`Pending`, un-payable reservation). | Yes — verified no existing fixture uses `is_paid=true` + `price=0`, so behavior for every pre-Phase-8 type is byte-for-byte identical; full suite confirms this (1902/1902 passing, including every pre-existing paid-booking test). | Booking creation only. Does not touch availability, marketplace, wallet, meeting, homework, or reviews. |
| `app/Filament/Resources/BookingTypes/Schemas/BookingTypeForm.php` | `currency` changed from a free-text `TextInput` to a `Select` sourced from `Currency::active()`, defaulting to `GeneralSettings::default_currency`. | Prevents invalid/typo'd currency codes; reuses the existing Currency foundation instead of accepting arbitrary strings. | Yes — same field name/column, only the input widget changed; existing rows with a currency code already in `currencies` still resolve correctly. | Admin booking-type configuration only. |
| `app/Filament/Resources/Bookings/Schemas/BookingForm.php` | Added a read-only "Payment" section (status label, formatted amount, payment reference) and gated the three "Meeting" fields with `disabled(fn (?Booking $record) => ! self::paymentSettled($record))`. | Surfaces the calculated/snapshotted amount to admins (previously invisible outside CSV export); prevents an admin from attaching a meeting link to an unpaid booking. | Yes — additive; no field removed, no column renamed. | Booking admin view/edit only. Directly touches the meeting-link boundary rule (§8). |
| `app/Filament/Resources/Bookings/Tables/BookingsTable.php` | Added a `price` money column (per-row currency, "Free" placeholder). | Same visibility gap as above, for the list view. | Yes — additive column. | Booking admin list only. |
| `app/Filament/Resources/Bookings/Pages/EditBooking.php` | *(Phase 7 cleanup, not Phase 8 pricing work)* — Delete/ForceDelete now `->visible()`-gated on terminal status, not just `before()`-guarded. | Closes the Phase 7.2 non-blocking UX finding. | Yes. | Booking admin delete safety — unrelated to pricing. |
| `app/Http/Resources/Student/StudentBookingResource.php` | Added `requires_payment`/`is_free_booking` booleans, derived from already-exposed `payment_status`/`price`. | Checkout-readiness signal for a future frontend, without building checkout. | Yes — additive keys only; every existing key unchanged. | Student booking API responses only. |
| `app/Http/Resources/Guest/GuestBookingResource.php` | Added `payment_status`, `price`, `currency`, `requires_payment`, `is_free_booking` (previously exposed none of these). | Same checkout-readiness rationale, and guests had no visibility into their own payment state at all before this. | Yes — additive; no existing key changed. No sensitive field added (`payment_reference` is not exposed). | Guest booking API responses only. |
| `app/Booking/Repositories/TeacherCandidateRepository.php`, `app/Booking/Services/AvailabilityService.php`, `app/Livewire/Frontend/Booking/BookingWizard.php` | *(Phase 7 work, unrelated to Phase 8 pricing — untouched this phase, listed here only because they were in the requested review list.)* | — | — | Re-confirmed unchanged since the Phase 7.2 audit (`git diff` against that point is empty). |
| `tests/Feature/Booking/BookingFlowHardeningTest.php` | *(Phase 7 cleanup)* Race-safety test docblock corrected; delete-blocked test changed from `callAction` to `assertActionHidden` to match the new `->visible()` gate. | Closes the Phase 7.2 non-blocking findings. | Yes — test-only change, 29/29 still passing. | Test file only. |

## 3. Migration Audit

Confirmed:

- **No new migration file exists.** `find database/migrations -newer .git/refs/heads/development` returns nothing.
- `php artisan migrate:status` (dev database): batch **36**, identical to the Phase 6.2/7.2 baseline.
- No `pricing`, `payment`, `wallet`, `razorpay`, `order`, `transaction`, or `meeting` table was created — confirmed by direct `Schema::hasTable()` checks in the test suite and by a filesystem search (§17).
- Existing `bookings`/`booking_types` schema is unchanged; `bookings.price`/`currency` (already existed before Phase 8) remain the only new-value writers.
- No destructive schema change occurred.

## 4. Existing Pricing State Audit

Verified:

- `booking_types.price` / `booking_types.currency` remain the sole pricing source — confirmed via `Schema::hasColumn()` assertions and by reading `BookingPriceCalculator` itself (reads only these two columns).
- No duplicate pricing table exists.
- No subject/country price matrix exists — confirmed absent, and deliberately deferred per the explicit user decision recorded in the architecture doc.
- No instructor payout table exists.
- No wallet ledger table exists.
- No Razorpay order/payment table exists.
- No tax/discount system exists — `BookingPriceData::discountAmount`/`taxAmount` are hardcoded `0.0` in the calculator, not read from any table.

## 5. BookingPriceCalculator Audit

Read `BookingPriceCalculator.php` in full and traced every branch:

- Demo/free type (`is_paid = false`): `baseAmount = 0.0` unconditionally (the ternary ignores `price` entirely when not paid, so stray data in a nullable `price` column on a free type can never leak through) → `payableAmount = 0.0`. **Verified.**
- Paid type with `price > 0`: `baseAmount = (float) price` → `payableAmount = baseAmount` (discount/tax both zero) → `requiresPayment = true`, `isFreeBooking = false`. **Verified.**
- Paid type with admin-configured `price = 0`: `payableAmount = 0.0` → `requiresPayment = false`, `isFreeBooking = true` — explicitly and correctly treated as free rather than an un-payable reservation. **Verified**, and this is the one behavior that's new relative to naively checking `is_paid` alone.
- Currency fallback chain (`type->currency ?? student's country default currency ?? GeneralSettings::default_currency`) — traced through `User::profile->country->defaultCurrency->code`, all nullable-safe via `?->`. **Verified** with two dedicated tests (country-present and country-absent cases).
- `discountAmount`/`taxAmount` are literal `0.0` constants in the method body, not derived from any table — matches "zero because no system exists," not a stubbed table read. **Verified.**
- The calculator takes a `BookingType` and an optional `User` as input and returns a value object — **no Eloquent write, no query beyond the nullable relationship traversal for currency fallback, no event dispatch, no side effect of any kind.** Confirmed by reading the full method body; it contains no `save()`, `create()`, `update()`, or event call.
- Uses only `booking_types.price`/`currency`, `Country`/`Currency`/`GeneralSettings` — all pre-existing data. No new table read.

## 6. BookingPriceData DTO Audit

- Fields: `baseAmount`, `discountAmount`, `taxAmount`, `payableAmount` (all `float`), `currency` (`string`), `requiresPayment`, `isFreeBooking` (both `bool`) — every field the spec asked for is present and named consistently with the calculator's own local variable names.
- `final readonly class` with a constructor-promoted, positional-arguments shape — matches `PaymentIntentData`'s exact convention (same directory, same pattern).
- **Finding (non-blocking): money fields are `float`, not the string-decimal representation Eloquent itself uses.** `BookingType::$price` is cast `decimal:2`, which Laravel deliberately returns as a **string** (`"49.90"`), specifically to avoid float precision pitfalls in money arithmetic — confirmed by direct tinker inspection (`gettype($type->price) === 'string'`) in this audit session. `BookingPriceCalculator` casts this to `float` for arithmetic. At today's scope (a single value, zero discount/tax, no accumulation) this introduces no observable bug — the computed `payableAmount` is written back to `bookings.price` where Eloquent's `decimal:2` cast normalizes it to a clean 2-decimal string on save, and no test in this or any prior phase's suite has ever hit a rounding mismatch. It is, however, a design choice worth revisiting **before** a real discount/tax engine (which would sum multiple float values) or a Razorpay integration (which typically wants integer minor units, not floats) is built — recommended as a Phase 9/10 note, not a Phase 8 fix.
- Serialization: the DTO itself is never directly JSON-encoded (API resources read the already-persisted `bookings.price`/`currency` columns, not the DTO), so there is no float-to-JSON precision exposure today.

## 7. BookingService Integration Audit

- Inline `$type->is_paid ? $type->price : null` / `... ? $type->currency : null` logic was replaced by `$price->requiresPayment ? $price->payableAmount : null` / `... ? $price->currency : null` — confirmed behaviorally identical for every existing type, and correctly different only for the new admin-zero-price edge case (§5).
- `price`/`currency` are snapshotted onto `bookings.price`/`currency`, columns that already existed before Phase 8 — no schema change was needed to support snapshotting, matching the instruction to only snapshot "if existing booking schema supports it safely."
- A paid booking is created with `payment_status = Pending`, never `Paid` — `Paid` is reachable only through `BookingPaymentService::markPaid()`, which still requires a matching `payment_reference` (unchanged, §8).
- Demo/free booking behavior is unchanged: `payment_status = NotRequired`, auto-confirms when the type doesn't require approval.
- No Razorpay order, wallet transaction, or meeting link is created anywhere in `BookingService::request()` — confirmed by reading the full method; `CreateBookingAction` writes only the attributes passed to it, and `meeting_provider`/`meeting_ref`/`meeting_url` are never among them.
- Phase 7 race-safety: `ensureAvailable()`'s bookable-host re-check, the host lock, and `duplicateExists()`/`hasOverlap()` re-checks are all still called in the exact same order — the only change inside the locked transaction is that the price snapshot now comes from `$price` instead of `$type` directly; no race-sensitive logic was touched.
- Self-booking prevention (`SelfBookingRule`, a fast-fail global rule) is untouched — confirmed by `git diff` showing zero changes to that file or to `BookingService::GLOBAL_RULES` beyond what Phase 7 already established.
- Booking status for an unpaid paid booking remains `Pending` with `payment_status = Pending` — never auto-escalated.

## 8. Demo vs Paid Boundary Audit

- Demo type is free when `is_paid = false`, unconditionally — verified by test and by the calculator's unconditional `baseAmount = 0.0` branch.
- Paid type with `price > 0` requires payment (`payment_status = Pending`, reservation hold set) — verified.
- `BookingPaymentService::markPaid()` — re-read in full this session, unchanged since Phase 7 — still calls `assertReference()`, which requires `payment_status === Pending` **and** a `hash_equals()` match against the stored `payment_reference` before any transition to `Paid` is possible. There is no other code path that sets `payment_status = Paid`.
- Meeting-link boundary: verified not just by behavior but by reading Filament's own `CanBeDisabled::disabled()` implementation (`vendor/filament/schemas/src/Components/Concerns/CanBeDisabled.php`). Its `saved()` hook — `fn (Component $component) => ! $component->evaluate($condition)` — means the disabled condition is re-evaluated **server-side, against the authoritative `$record` from the database** at save time, not against client-supplied state. This is the same mechanism Filament's own inline comment flags as the correct pattern for exactly this class of concern ("skilled users can manipulate Livewire's JavaScript to bypass the disabled state on the client... enforce authorization on the backend") — and `paymentSettled($record)` here already does that, since the closure receives the real Eloquent record. A client-side bypass of the "disabled" HTML attribute would still hit this server-side re-check on save. **This is a materially stronger guarantee than "the button looks disabled," and was verified against framework source, not assumed.**
- Instructor payout: no payout mechanism exists to trigger, so "must not trigger payout" is vacuously true — confirmed no `payout`/`earnings` code path exists anywhere (§4).
- Payment status cannot be bypassed through the normal service/API flow: `payment_status` is not an editable field anywhere in the admin form (only a synthetic, non-dehydrated `payment_status_label` display field exists) and is not a field in any `StoreGuestBookingRequest`/`StoreStudentBookingRequest` validation rule set — there is no request payload key that reaches it.

## 9. Currency Audit

- `BookingTypeForm`'s currency `Select` is populated from `Currency::query()->active()->orderBy('code')->pluck('code', 'code')` — an admin can only choose a code that exists and is active in the `currencies` table. Verified by reading the field definition directly.
- No exchange-rate engine was created — `BookingPriceCalculator` never converts an amount between currencies; the currency fallback chain only changes which currency *label* is shown when the type itself has none configured (always the case for free types, and the amount is zero either way).
- Fallback order (`type->currency` → student's country default currency → `GeneralSettings::default_currency`) matches exactly what was implemented and tested; no duplicate currency table or parallel logic exists — `Currency`/`Country`/`GeneralSettings` are the only sources read.
- Admin display: `BookingTypesTable` (pre-existing) and the new `BookingsTable` price column both use `->money(fn ($record) => $record->currency ?? 'USD')` — correctly falls back to a sane default if a legacy row somehow has a null currency alongside a non-null price.

## 10. Admin / Filament Audit

All items re-verified by direct file read in this session (not restated from the implementation summary):

- `BookingTypeForm` currency field: safe, `Select`-constrained (§9).
- `BookingForm` shows a read-only Payment section (status, formatted amount, reference) — confirmed present, confirmed `disabled()->dehydrated(false)` (the `$readonly` closure) so it cannot be a write vector even in principle.
- `BookingsTable` shows a `price` column — confirmed present.
- Meeting fields disabled unless `payment_status` is `Paid` or `NotRequired` — confirmed, and confirmed the guard is server-side-authoritative (§8).
- No admin action anywhere calls `Booking::update(['payment_status' => ...])` or similar direct mutation — the only writer of `payment_status` in the entire codebase is `BookingRepository::updatePaymentStatus()`, called exclusively from `BookingPaymentService`.
- Phase 7 delete/force-delete terminal-status guards remain intact on `EditBooking`/`BookingsTable` — confirmed via `git diff` showing no unexpected changes beyond the Phase 7 cleanup itself, and via the full regression suite (Phase 7's 29 booking-flow tests still pass).
- No duplicate `BookingResource`/`BookingTypeResource` was created — confirmed via filesystem search (§17); only the existing resources were modified.

## 11. API Resource Audit

- `StudentBookingResource`/`GuestBookingResource` both expose `requires_payment` (`payment_status !== NotRequired`) and `is_free_booking` (`price === null || price <= 0`) — both derived purely from already-persisted, already-exposed fields, so they cannot disagree with the stored snapshot by construction.
- No sensitive field was added — `payment_reference` (the value `markPaid()`/`initiate()` guard against forgery with) is not exposed by either resource, before or after Phase 8.
- Existing consumers remain backward-compatible: every previously-existing key in both resources is unchanged; only new keys were appended.
- **Finding (non-blocking, test gap): no test directly asserts these two JSON keys are present with correct values in an actual HTTP response.** The underlying logic is trivial (a direct boolean derivation from two already-tested fields) and low-risk, but Phase 8.1's own required-coverage list (§14, item 13) asks for this explicitly and it is not covered. Recommended as a quick addition in a future pass; not blocking Phase 9 given the low complexity of the derivation and the fact that both source fields (`payment_status`, `price`) are already covered elsewhere.

## 12. Raw Guest JSON API Risk Audit — Investigated

Traced the full code path in this session:

- `StoreGuestBookingRequest::rules()` restricts `type` only to `Rule::exists('booking_types', 'key')->where('is_active', true)` — **a guest can select `paid_one_to_one` (or any other active paid type) through the raw API.** No rule limits guests to free types.
- `GuestBookingController::store()` builds a `GuestBookingData` with **no `teacherId`** (the field isn't in the request's validated data at all) — so a guest-selected paid booking always goes through the auto-assignment engine, never a locked instructor.
- Inside `BookingService::request()`, the now-calculator-driven logic applies identically regardless of attendee type: a paid type produces `status = Pending`, `payment_status = Pending`, a `price`/`currency` snapshot, and a `reserved_until` hold (from `BookingSettings::payment_reservation_minutes`).
- **Critically: `GuestBookingController::store()` never calls `BookingPaymentService::initiate()`.** Confirmed by reading the controller in full — unlike `StudentBookingController::store()`, which calls `paymentIntentFor($booking)` and returns a `payment` key with a reference. The guest response contains `price`/`currency`/`requires_payment`/`is_free_booking` (as of this phase) but **no payment reference and no way to obtain one** — there is also no guest-facing `/pay` route in `routes/api.php` (confirmed: only `show`/`cancel`/`reschedule` exist for guests under `/api/v1/guest/bookings/{reference}`).
- **Consequence**: a guest who books a paid type today creates a `Pending`/`payment_status=Pending` reservation that can never be completed (no reachable path to `markPaid()`), and it will sit as a soft slot-hold until `booking:release-expired` (the existing scheduled command, unchanged, runs every 5 minutes per `docs/booking.md`) cancels it after `payment_reservation_minutes` elapses. The existing guest spam guard (`MAX_ACTIVE_PER_EMAIL = 3`, unchanged) bounds how many such holds one email can create at once.

**Decision, per the criteria given**: current behavior creates an **unpaid `Pending` booking only** — it is never confirmed, never marked paid, creates no payment/wallet/meeting record, and self-heals via the existing expiry job. Per the audit's own decision rule ("If current behavior is safe and creates unpaid pending booking only, document as non-blocking"), **this is classified non-blocking.** Recommended handling for a future phase (Phase 9 or whenever guest checkout is built): either restrict `StoreGuestBookingRequest`'s `type` validation to free/non-`is_paid` types until a guest payment path exists, or extend the guest flow with its own `initiate()`/`pay` endpoints alongside Razorpay integration. Not fixed in this audit pass per the "verification and reporting only" mandate.

## 13. Out-of-Scope Boundary Audit

Confirmed Phase 8 did **not** implement: Razorpay order creation, payment capture, wallet ledger, wallet debit/credit, meeting creation, homework, reviews, referrals, packages, subscriptions, a tax engine, a discount/promo engine, instructor earnings/payouts, or a per-country/subject pricing matrix. `BookingPaymentService`, `PaymentProviderInterface`, `FakePaymentProvider`, and `BookingPaymentWebhookController` are byte-for-byte unchanged (`git diff` against the Phase 7.2 audit point is empty for all four).

## 14. Tests Audit

| Required coverage | Status |
|---|---|
| Demo booking calculates zero payable amount | **Covered** |
| Paid booking calculates payable amount | **Covered** |
| Zero-price paid type behavior is safe | **Covered** |
| Paid booking without payment is not marked paid | **Covered** |
| Paid booking does not create wallet transaction | **Covered** |
| Paid booking does not create Razorpay order | **Covered** |
| Paid booking does not create meeting link | **Covered** |
| Currency fallback works | **Covered** (two tests: country-present, country-absent) |
| Invalid/inactive booking type rejected | **Covered** |
| Duration/buffer remains compatible with availability | **Covered** |
| Admin cannot add meeting URL to unpaid paid booking | **Covered**, verified against Filament's server-side re-evaluation, not just UI state |
| Payment service cannot mark paid without reference | **Covered** — pre-existing (`StudentBookingTest::test_payment_placeholder_marks_booking_paid`, "Wrong reference rejected" case), unaffected by Phase 8, still passing |
| API resources expose `requires_payment`/`is_free_booking` | **Missing but non-blocking** — fields exist and are logically simple/low-risk, but no test asserts them in an actual JSON response (§11) |
| Existing Phase 7 booking tests still pass | **Covered** — 29/29 in `BookingFlowHardeningTest.php` |
| Existing Phase 6 availability tests still pass | **Covered** — full suite green |
| No duplicate payment/wallet/pricing tables created | **Covered** — explicit `Schema::hasTable()` assertions plus this audit's independent filesystem search |

## 15. Documentation Audit

`docs/architecture/phase-8-pricing-booking-type-checkout-readiness.md` — confirmed present and re-read in full this session. Confirmed it documents: existing pricing state, the no-new-pricing-table decision (with the explicit user choice recorded), booking type rules, the demo/paid boundary, calculator behavior, currency strategy, admin hardening, the API resource changes, and future Razorpay/wallet/pricing-matrix integration points. **One gap**: it does not mention the raw guest paid-type API behavior investigated in §12 of this audit — recommended as a follow-up addition to that document, non-blocking.

## 16. Commands

| Command | Result |
|---|---|
| `composer test` (`php artisan test --env=testing`) | Passed: **1902 tests, 4239 assertions** |
| `php artisan migrate:status` | Passed; batch **36** (unchanged) |
| `php artisan route:list` | Passed; **218 routes** (unchanged) |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Not run — no Blade/CSS/JS file changed in Phase 8 (confirmed via `git status`; only PHP files were touched) |

`php artisan test` was deliberately not run without `--env=testing`, per the project's documented database-safety convention.

## 17. Duplicate Prevention Search

Direct filesystem search (`find app/Models database/migrations -iname "*term*"`) for every listed term:

| Term | Result |
|---|---|
| `pricing` | None found — **valid absence** |
| `booking_prices` | None found — **valid absence** |
| `subject_prices` | None found — **valid absence** |
| `country_prices` | None found — **valid absence** |
| `tutor_prices` | None found — **valid absence** |
| `instructor_payouts` | None found — **valid absence** |
| `payments` | None found — **valid absence** |
| `payment_transactions` | None found — **valid absence** |
| `razorpay_orders` | None found — **valid absence** |
| `wallets` | None found — **valid absence** |
| `wallet_transactions` | None found — **valid absence** |
| `meetings` | None found — **valid absence** |
| `booking_types` | Existing table/model — **valid, adjacent, intentional (reused)** |
| `bookings` | Existing table/model — **valid, adjacent, intentional (reused)** |
| `booking_payments` | None found — **valid absence** |
| `currencies` | Existing table/model — **valid, adjacent, intentional (reused)** |
| `countries` | Existing table/model — **valid, adjacent, intentional (reused)** |

No duplicate, and nothing classified "risky and needs follow-up."

## 18. Final Decision

Readiness score: **95/100**

Decision: **SAFE TO PROCEED TO PHASE 9**

The 5-point deduction reflects the two non-blocking findings (§6 float-vs-decimal-string design note, §11/§14 missing direct JSON-assertion test for `requires_payment`/`is_free_booking`) and the documentation gap in §15 — none of which represent a payment-safety, duplication, or lifecycle-integrity defect. Every strict blocking criterion is clear:

- Full suite passes (1902/1902).
- No duplicate pricing/payment/wallet structure exists (independently re-searched, not just re-stated).
- Paid bookings are not marked paid without payment (`assertReference()` unchanged and re-verified).
- Meeting creation remains blocked for unpaid bookings, verified against Filament's actual server-side security mechanism, not just its visible UI state.
- Razorpay/wallet were not implemented prematurely.

**Recommended next phase: Phase 9 — Wallet Ledger Foundation.** Razorpay capture should not be recommended ahead of the wallet ledger foundation per the approved roadmap.
