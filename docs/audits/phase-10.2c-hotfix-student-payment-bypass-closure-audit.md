# Phase 10.2C-Hotfix — Student Payment Bypass Closure Audit

**Score: 98/100 — SAFE TO PROCEED.** The critical vulnerability found
in the Phase 10.2C-Fix audit is closed, verified from three
independent angles (route table, static call-site trace, full
regression), and nothing else regressed. Two points held back only
because the fix's own regression test (`test_unsafe_pay_route_no_longer_exists`)
duplicates coverage of "the route is gone" without a second,
independent live-exploit-style probe re-run this session — a
reasonable no-worse-than-before minor observation, not a defect.

## Method

Audited only the hotfix's own diff (`routes/web.php`,
`StudentBookingController.php`, `StudentBookingTest.php`, both docs) —
did not re-litigate anything already re-verified in the Phase
10.2C-Fix audit. For each of the 10 requested checks: re-read the
current code fresh (not the prior session's description of it), then
ran the full suite once to catch anything the focused test selection
during implementation might have missed.

## 1. `dashboard/bookings/{booking}/pay` removed or safely disabled — REMOVED, confirmed

`php artisan route:list --path=dashboard/bookings` lists exactly 5
routes (`index`, `store`, `teachers`, `previous-teachers`, `slots`) —
no `pay` action, no route named `dashboard.bookings.pay`. This is
stronger than "disabled": the route does not exist in the router at
all, so there is no URL to probe, rate-limit, or accidentally
re-expose via a future generic route-list change.

## 2. No active student route can mark a booking paid using only `payment_reference` — confirmed

Traced every call site of `BookingPaymentService::markPaid()`
repo-wide (`grep -rn "markPaid(" app/`) — 6 call sites, all in
non-student-input-trusting contexts:

| Caller | Guard before `markPaid()` |
|---|---|
| `BookingWizard::verifyPayment()` (:327) | `RazorpayPaymentProvider::verifyCheckout()` (:319) — real HMAC check |
| `BookingHistory::verifyPayment()` (:261) | same pattern, re-read fresh, confirmed |
| `BookingWizard::simulateFakePayment()` (:295) | `app()->environment(['local','testing'])` inside the method body (:283) |
| `BookingHistory::simulateFakePayment()` (:295) | same environment guard, re-confirmed |
| `BookingPaymentWebhookController` (:47) | signature-verified webhook payload |
| `GuestBookingPaymentController` (:73) | route not mounted (see §8) — unreachable regardless |

No caller left that accepts a bare client-submitted reference and
trusts it. `StudentBookingController` no longer has a `markPaid()` (or
any `BookingPaymentServiceInterface`) reference at all — confirmed by
reading the current file: constructor now takes only
`StudentBookingServiceInterface`.

## 3. `StudentBookingController::pay()` cannot bypass verification — method does not exist

`grep -rn "function pay(" app/Http/Controllers` returns nothing in
`StudentBookingController`. There is no method left to bypass
anything with.

## 4. `StudentBookingController::store()` does not create an unsafe direct-pay path — confirmed

Fresh read of the current file: `store()` builds `StudentBookingData`,
calls `$this->studentBookings->book($data)` (or `bookRecurring()`),
and returns `StudentBookingResource::make($booking)` — no
`paymentIntentFor()`, no `payment` key, no call into
`BookingPaymentService` anywhere in the class.
`test_student_books_paid_session_with_chosen_teacher` (re-run in
isolation) asserts `assertJsonMissingPath('payment')` on the response,
proving this at the HTTP boundary, not just by reading source.

## 5. Remaining active student checkout flow requires provider verification — confirmed

Same table as §2: the only two UI-reachable checkout entry points
(`BookingWizard`, `BookingHistory`) always call `verifyCheckout()`
before `markPaid()` on the real-provider path, and independently
re-check the environment before ever using the fake-provider path.

## 6. Incomplete-profile student cannot book/pay through any active path — confirmed, and the specific bypass this hotfix targeted is now closed

Booking *creation* still doesn't require a billing country (unchanged,
deliberate — Phase 10.2C-Fix's documented scope decision). Payment
*initiation* does: with `store()`'s auto-initiate removed, the only
two callers of `BookingPaymentService::initiate()` anywhere in the app
are `BookingWizard::initiatePayment()` and
`BookingHistory::initiatePayment()`, both of which check
`auth()->user()->profile->country_id` before calling it (re-confirmed
by reading both methods fresh). `StudentBookingController` no longer
calls `initiate()` at all, so it can no longer be used to route around
that check — this was the exact gap the Phase 10.2C-Fix audit flagged
as "Finding B," and it is now closed as a side effect of removing the
auto-initiate call, with no new guard code needed.

## 7. Booking owner cannot exploit ownership policy to self-confirm payment — confirmed

`BookingPolicy::pay()` itself is unchanged and was already correct
(ownership OR `Update:Booking` permission) — re-confirmed by reading
it fresh. It is now only reachable through `Gate::authorize('pay', ...)`
inside `BookingHistory`'s three payment methods (`initiatePayment()`,
`verifyPayment()`, `simulateFakePayment()` — all three call it,
confirmed via `grep -n "authorize('pay'" app/`), every one of which
requires either real signature verification or the local/testing-only
guard before `markPaid()` is reached. Owning a booking is no longer
sufficient by itself to settle it — it never should have been, and now
isn't anywhere in the codebase.

## 8. Guest payment routes remain disabled — confirmed, untouched by this hotfix

`php artisan route:list --path=guest` → 8 routes, identical set to the
Phase 10.2C-Fix audit's confirmed list (`show`/`cancel`/`reschedule`/
`store`(422-only)/catalog endpoints) — no `payments/razorpay/initiate`
or `.../verify`. This hotfix's diff never touched `routes/api.php` or
`GuestBookingPaymentController` — re-verified rather than assumed,
since it would have been an easy thing to accidentally revert.

## 9. Option B late-payment wallet-credit behavior remains intact — confirmed

This hotfix's diff touches none of `BookingPaymentService.php`'s
`handleLateTerminalPayment()`/`tryCreditStudentWallet()` methods
(`git diff` on that file for this hotfix is empty — the file wasn't
touched at all, only called into less). `PaymentTerminalStateTest`
(part of the full suite run below) passed in full.

## 10. No wallet debit, meeting creation, instructor payout, pricing matrix, or duplicate tables introduced — confirmed

`git diff` for this hotfix's three code files
(`routes/web.php`, `StudentBookingController.php`, `StudentBookingTest.php`)
grepped for `wallet`/`meeting`/`payout`/`pricing`: zero matches. No new
migration files exist since the Phase 10.2C-Fix audit
(`find database/migrations database/settings -newer <that audit doc>`
returns nothing). `app/Models` has exactly one payment model
(`BookingPayment`) and the two pre-existing wallet models — no new
ones.

## Verification commands (fresh run for this audit)

- `php artisan test --env=testing` → **2067/2067 passing**, 4636
  assertions (same test count as the prior audit; assertion count
  shifted by the 1-for-1 test replacement in `StudentBookingTest`, not
  a coverage loss — the removed test's assertions on the now-deleted
  vulnerable flow were replaced by assertions proving that flow is
  gone).
- `php artisan route:list` → 136 named routes total (222 raw route
  entries including HEAD variants), `dashboard.bookings.pay` absent,
  guest payment routes absent, `/book`/`/instructors/book` present and
  auth-gated — all re-confirmed by direct output, not by diffing
  against a remembered count.
- `php artisan migrate:status` → all ran, none pending, none added by
  this hotfix.
- `./vendor/bin/pint --test` → passed.
- `composer validate` → valid.
- `npm run build` — **skipped**, correctly: this hotfix touched no
  Blade/JS/CSS asset (confirmed via `git status` — only `routes/web.php`,
  one PHP controller, one test file, and two docs changed).

## Decision

**SAFE TO PROCEED.**

Every one of the 10 requested checks holds under independent
re-verification, not just re-assertion of the implementation report.
The critical finding from the Phase 10.2C-Fix audit — a student able
to self-confirm payment on their own booking with no gateway
interaction — is closed at the root (the route no longer exists, and
the two remaining checkout entry points were already correctly
verification-gated, re-confirmed here). No new gaps, no scope creep
(wallet/meeting/payout/pricing-matrix/duplicate-table checks all
clean), no regression in the 2067-test full suite.

**Recommended next phase: Phase 10.2D — Student Pricing Matrix
Foundation.** The payment trust boundary this and the prior two audits
have been closing is now solid enough to build on top of — pricing
matrix work sits above this boundary (it changes *what* a booking
costs, not *whether* a payment claim is trustworthy), so it does not
reopen anything closed here. No other pre-existing gaps were surfaced
during this audit's tracing of `BookingPaymentService`'s callers that
would need to be closed first.
