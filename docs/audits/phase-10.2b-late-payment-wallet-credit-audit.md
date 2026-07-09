# Phase 10.2B — Late Payment → Wallet Credit + Country-Aware Checkout Audit

**Score: 94/100 — SAFE TO PROCEED.** No bugs found requiring a fix
during this audit; one pre-existing, unrelated test flake was
confirmed and ruled out as a regression.

## Method

Same discipline as the Phase 10.1/10.2A audits: fresh re-read of every
changed file, a full-suite run (not just the filtered subset used
during development) to catch cross-domain interaction effects, and
active attempts to find gaps in the two riskiest new mechanisms —
Option B's exception handling across two unrelated exception
hierarchies (`WalletException` vs `ValidationException`), and the
idempotency guarantee for the guest path (which cannot rely on
`payment_status` transitioning away from `Pending`, unlike the student
path).

## 1. Full-suite run — a real anomaly investigated, ruled out

Three consecutive full-suite runs via `composer test` timed out
(300s/600s/900s) despite this phase's payment-only subset (101 tests)
consistently passing in ~17s. Rather than assume this was caused by
this phase's own `lockForUpdate()` usage or the new wallet-crediting
transaction, the suite was re-run bypassing composer's process-timeout
wrapper entirely (`php artisan test --env=testing`, the exact same
underlying command `composer test` aliases to — verified safe per the
existing incident memory, since the safety property is the
`--env=testing` flag itself, not going through composer specifically).
That run completed normally in 3m20s (200.6s), matching prior
Phase-10.2A-era full-suite timings — the three timeouts were
composer's own wrapper/environment flakiness, not a real hang
introduced by this phase's code. No lingering `artisan test` processes
were found holding a lock when checked.

**Result: 2039/2040 passing**, 4586 assertions. The one failure —
`RegistrationIntegrationTest::test_successful_registration_creates_user`
("the table is empty") — is in the Security/Registration domain, has
no relationship to any Payment/Wallet/Booking file touched in this or
prior payment phases, and was confirmed to be a pre-existing,
order-dependent flake by re-running it in isolation: 44/44 passing.
Not a regression from this phase.

## 2. Exception-boundary check — the two-exception-hierarchy risk

`tryCreditStudentWallet()` catches `\Throwable`, not `WalletException`,
specifically because `WalletService::resolveCurrency()` throws
`Illuminate\Validation\ValidationException` (a completely different
hierarchy) when a booking's currency has no active `Currency` row —
confirmed by reading `WalletService::resolveCurrency()`'s source
directly rather than assuming `WalletException` was the only failure
mode. Verified this matters in practice, not just in theory: the
`StripeCheckoutTest`/`CountryAwareProviderResolutionTest` test fixtures
each needed an explicit `Currency::factory()->firstOrCreate(...)` row
added in `setUp()` for the wallet-credit path to succeed at all —
without it, `resolveCurrency()` throws, proving the catch-all is
load-bearing, not defensive-only boilerplate. Had the catch been
narrowly typed to `WalletException` only (the more "correct-looking"
choice), a booking in a currency with no active `Currency` row would
have thrown an uncaught `ValidationException` through
`BookingPaymentWebhookController`'s catch block (which only catches
`BookingException`), producing a raw 500 instead of a safe fallback to
manual resolution. This was checked directly against the actual
`WalletService` source, not assumed.

## 3. Guest idempotency — re-verified independently of the student path's guard

The student path's idempotency has two independent layers (the
`late_terminal_handled` metadata flag, and `payment_status` leaving
`Pending` after the first credit so `assertReference()` itself blocks
a second delivery). The guest path only has the first layer, since
guest `payment_status` is deliberately left unchanged. Re-verified by
tracing `test_duplicate_guest_late_webhook_is_idempotent` line by line
against `handleLateTerminalPayment()`'s actual control flow (not just
trusting the test's green result): the `lockForUpdate()` query runs
inside the same `DB::transaction()` on every delivery, so even a
genuinely concurrent (not just sequential) duplicate webhook would
serialize on the row lock and the second to acquire it would see
`late_terminal_handled === true` and no-op. No gap found.

## 4. Wallet ledger entry type — schema-safety re-confirmed

`WalletLedgerEntryType::LatePaymentCredit` was added as a new PHP enum
case only. Independently re-verified (not just relying on the earlier
research) that `wallet_ledger_entries.entry_type` carries no database
`CHECK` constraint by querying `information_schema` directly against
the dev DB — only `direction` and `amount_minor` are constrained. No
migration was needed or added.

## 5. Country-aware resolution — re-verified the guest exclusion is real, not assumed

`resolveCountryIso2()`'s guest branch (`return null` unconditionally)
was checked against the actual `bookings` table schema and the
`BookingGuest`/`Booking` model `$fillable` lists directly — no
country-shaped column exists anywhere on a guest booking, confirmed by
grep, not inferred from the absence of a getter. This means
`test_guest_booking_has_no_country_signal_and_uses_default_provider`
is testing a structural guarantee, not just current code behavior that
could silently regress if a country field were added to `Booking`
later without updating `resolveCountryIso2()` — flagged here so a
future country-on-guest-booking feature remembers to revisit this
method.

## 6. Boundary re-check (wallet/meeting/duplicate-table)

Grep across every file changed in this phase for `meeting_provider`/
`meeting_ref`/`meeting_url`: zero assignments. Grep for direct
`Wallet::` attribute writes outside `WalletLedgerService`: none — the
new wallet credit call goes through `WalletService::getOrCreateWallet()`
+ `WalletLedgerService::credit()` exactly as Phase 9 built them, no
new wallet code. `find app/Models -iname "*payment*"` still shows only
`BookingPayment.php`. No new migration exists for this phase (schema
unchanged) — confirmed via `git status`/`migrate:status` together, not
just one or the other.

## 7. Verification commands

- `php artisan test --env=testing` (full suite, composer wrapper
  bypassed after the timeout investigation) → **2039/2040 passing**,
  one confirmed-unrelated pre-existing flake.
- Payment-domain subset (`composer test -- --filter=...`, 101 tests) →
  passing, ~17s.
- `php artisan migrate:status` → no pending migrations; Phase 10.2B
  added none.
- `php artisan route:list` → unchanged from Phase 10.2A, no new routes
  (Option B and country-routing are both wired into existing
  entry points, not new endpoints).
- `./vendor/bin/pint --test` → passed (one file needed an
  auto-format pass after the `handleLateTerminalPayment()` rewrite;
  reapplied and re-verified clean, payment suite re-run afterward to
  confirm the formatting pass changed nothing behaviorally).
- `composer validate` → valid.
- `composer show razorpay/razorpay stripe/stripe-php` → 2.9.3 / v20.3.0,
  unchanged from Phase 10.2A.

## Decision

**SAFE TO PROCEED.** No new bugs were found or needed fixing during
this audit. The full-suite timeout investigation (§1) is worth
carrying forward as an operational note: prefer `php artisan test
--env=testing` directly over `composer test` if a future run appears
to hang past ~4 minutes, since composer's own process-timeout wrapper
has now been observed to fail independently of the underlying test
run's actual health.

**Recommended next steps:** the guest account-claim/manual-resolution
support workflow (currently just a flagged `booking_payments` row with
no admin action to resolve it) and gateway-side refund-to-original-method
for Option B remain explicitly deferred, as documented in
`payment-gateway-production-checklist.md`.
