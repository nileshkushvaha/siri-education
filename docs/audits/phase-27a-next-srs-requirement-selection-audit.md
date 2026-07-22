# Phase 27A — SRS Backlog Reconciliation and Next-Requirement Selection Audit

## Final decision: **READY**

---

## 0. Method note

This is a read-only audit. Every classification below cites concrete current-code
evidence gathered in this phase (file paths, `grep`/`find` results, or named-test runs
executed today) — it does not carry forward any Phase 25A conclusion without
re-checking it. Three gaps were verified in depth per the phase's Step 2 mandate
(GAP-002, GAP-003, GAP-023); one additional closure was discovered that Phase 25A
did not anticipate (GAP-027, closed in a session between 25A and this audit); and one
gap's classification was corrected against direct evidence that contradicts both the
original `SRS_Compliance_Audit.md` and Phase 25A (GAP-041, half-closed, not "not
touched").

---

## 1. Reconciled status of all 41 GAP-IDs

| ID | Requirement | SRS ref | Status at Phase 25A | **Updated status (Phase 27A)** | Evidence |
|---|---|---|---|---|---|
| GAP-001 | Manual instructor payout fallback | SRS-15-14/15.31 | Open — BLOCKED | **Still BLOCKED** | No `markPaid`-style manual withdrawal action found in `app/Earnings`; RazorpayX remains the only payout path. No `docs/decisions.md` entry resolves Uncertainty #1. Unrelated to any Phase 25B–26D work (none touched `app/Earnings`). |
| GAP-002 | Wallet payment for lessons | SRS-13-7 | Open — actionable | **CLOSED** | See §3. |
| GAP-003 | Wallet recharge via gateway | SRS-13-6 | Open — actionable | **CLOSED** | See §3. |
| GAP-004 | One free demo/instructor | SRS-11-9 | Closed (24A) | **Closed, unaffected** | `OneFreeDemoPerInstructorRule` still wired in `app/Booking/Types/FreeDemoType.php:rules()`; not touched by 25B–26D. |
| GAP-005 | Cancellation-window refund | SRS-11-20 | Closed (24C) | **Closed, unaffected** | `CancellationRefundPolicy` unchanged; confirmed still the sole refund-decision path (`SyncPaymentOnCancellation.php:55` calls `refundToWallet()` through it). |
| GAP-006 | Reschedule limit | SRS-11-22 | Closed (24D) | **Closed, unaffected** | `RescheduleLimitPolicy` untouched by any wallet/learning-plan/recording work this cycle. |
| GAP-007 | Invoice/receipt generation | SRS-14-14/15/16 (§14.21–14.23) | Open | **Still open — actionable (SELECTED, see §4–§12)** | `find app -iname "*invoice*" -o -iname "*receipt*"` returns nothing. |
| GAP-008 | Demo-to-paid conversion incentive | SRS-15-7 | Open (Phase 3) | **Still open, unaffected** | No conversion-bonus code found; not in scope of any recent phase. |
| GAP-009 | Payment settings audit | SRS-20-18 | Closed (24G/24S) | **Closed, unaffected** | `LogsSettingsUpdates` still wired into all governed settings pages, including the Recording toggle added in Phase 26A (which itself now uses the same audited-save pattern). |
| GAP-010 | Last Super Admin guard | SRS-23-7 | Closed (24E) | **Closed, unaffected** | `SuperAdminGuardService` untouched. |
| GAP-011 | Role/permission audit via `AuditTrailService` | SRS-23-6 | Closed (24Q) | **Closed, unaffected** | Not touched. |
| GAP-012 | Session idle-timeout enforcement | SRS-1-23 | Closed (24F) | **Closed, unaffected** | Not touched. |
| GAP-013 | Student lifecycle write path | SRS-2-20/B1-12 | Closed (24H) | **Closed, unaffected** | `StudentLifecycleService` reused as-is by the wallet-payment and learning-plan-progress work (booking/plan actions still call `assertEligibleForStudentAction()`), never modified. |
| GAP-014 | Fraud-detection layer | SRS-B1-9 | Open (Phase 2, L) | **Still open, unaffected** | `find app -iname "*Fraud*"` empty; no rule-based flag system exists. |
| GAP-015 | Suspicious-activity/compliance monitoring | SRS-24-11 | Open (Phase 2, L) | **Still open, unaffected** | No compliance-monitoring model/service found. |
| GAP-016 | Support/dispute case system | SRS-25-1/2/5/6/7 | Open (Phase 2, XL) | **Still open, unaffected** | Only `LessonDisputed` (a lesson-outcome dispute, not a support-case system) exists; no `SupportCase`/ticket model anywhere. |
| GAP-017 | Student-instructor messaging | SRS-17-11 | Open (Phase 2, XL) | **Still open, unaffected** | No `Conversation`/`Message` model found (only unrelated `SanitizesProviderMessages` string-sanitizing trait). |
| GAP-018 | Waitlist | SRS-10-25/2-12/16/20-6 | Open (Phase 2, L) | **Still open, unaffected** | No waitlist migration/model found. |
| GAP-019 | Availability-change impact warning | SRS-10-12 | Closed (24I) | **Closed, unaffected** | Not touched. |
| GAP-020 | Homework due-date reminders | SRS-7-11 | Closed (24K) | **Closed, unaffected** | Not touched by the learning-plan-progress work (Phase 26B–D touched homework *linkage to progress*, never the reminder pipeline). |
| GAP-021 | Homework `learning_plan_id` FK | SRS-7-1/12 | Closed (24J) | **Closed, unaffected** | `homework_assignments.learning_plan_id` + CHECK constraint unchanged; Phase 26B/26C only *read* this column (via `StudentLearningPlan::homeworkAssignments()`), never altered its schema or constraint. |
| GAP-022 | Homework resource/attachment library | SRS-7-3/8 | Open (Phase 2) | **Still open, unaffected** | `HomeworkAssignment` still has no `HasMedia`/`registerMediaCollections()`. |
| GAP-023 | Learning-plan progress composite | SRS-6-5/6.17.5 | Open (Phase 2) | **CLOSED** | See §3. |
| GAP-024 | Localized pricing on discovery | SRS-9-5 | Open (Phase 2) | **Still open, unaffected** | Price resolution still only inside the booking wizard; no evidence of a discovery-card/profile price surface. |
| GAP-025 | Recommendation sections | SRS-8-4/8-10 | Open (Phase 3) | **Still open, unaffected** | Not touched. |
| GAP-026 | `demo_lessons_enabled` toggle enforcement | SRS-20-5 | Closed (24B) | **Closed, unaffected** | Not touched. |
| GAP-027 | Platform `recording_enabled` outer-flag plumbing | SRS-20-8 | Open (bundled w/ GAP-028) | **CLOSED (new evidence this phase)** | `app/Booking/Services/RecordingAvailabilityResolver.php` exists and is the sole authority (`isAvailable()` = outer AND inner flag); wired into `MeetingSettingsPage.php:93` as a live "Effective Recording Availability" indicator; `ZoomMeetingProvider.php` carries an explicit, tested, permanently-unconditional `auto_recording => 'none'` regression guard so the newly-readable outer flag can never accidentally trigger real capture. Dedicated test `tests/Feature/Booking/RecordingFeatureToggleTest.php` (12 tests) passes today (see §14). This closure happened in a session between Phase 25A and this audit and was not anticipated by either prior document. |
| GAP-028 | Recording capture pipeline | SRS-12-11 | Open (Phase 3, L) | **Still open, unaffected — deliberately** | Zero fetch/store/retention/access code exists; the GAP-027 closure explicitly avoided building this by design (Zoom's payload stays hardcoded `none` regardless of the now-readable flag) to prevent uncontrolled real recording capture with no storage/retention pipeline. Correctly remains open. |
| GAP-029 | Country-level feature flags | SRS-20-17/21-11 | Open (Phase 2, L) | **Still open, unaffected** | `Country::feature_flags` column exists (`app/Models/Country.php:26,32`) with zero readers anywhere in `app/` (`grep -rn "feature_flags" app --include="*.php"` outside the model file returns nothing). |
| GAP-030 | Per-recipient notification timezone | SRS-21-6 | Closed (24L) | **Closed, unaffected** | Not touched. |
| GAP-031 | Currency active-status re-check at transaction time | SRS-21-4 | Closed (24M) | **Closed, unaffected** | `CurrencyEligibilityPolicy` reused as-is by the new wallet-payment/recharge code (`WalletService::resolveCurrency()` still calls `assertUsable()`), never weakened. |
| GAP-032 | Backup strategy | SRS-27-27 | Open (Category F — ops) | **Still open, unaffected** | No backup package in `composer.json`. Remains Category F: operational/infrastructure, not an application-code gap. |
| GAP-033 | Pulse APM activation | SRS-27-29 | Closed (24O) | **Closed, unaffected** | Not touched. |
| GAP-034 | Failed-job retry UI | SRS-26-2 | Closed (24N) | **Closed, unaffected** | Not touched. |
| GAP-035 | `OperationalAlert` model | SRS-26-10 | Open (Phase 2, L) | **Still open, unaffected** | `find app -iname "*OperationalAlert*"` empty. |
| GAP-036 | Redirect (301/302) management | SRS-22-16 | Open (Phase 2) | **Still open, unaffected** | No redirect model/migration found. |
| GAP-037 | Media conversions | SRS-27-26 | Open (Phase 2) | **Still open, unaffected** | `grep -rln "registerMediaConversions" app` empty; Spatie Media Library remains installed but unused for conversions. |
| GAP-038 | Real SMS/WhatsApp provider | SRS-17-2/3 | Open (Category F) | **Still open, unaffected** | No Twilio/Meta-Cloud-API client class found; channels remain log-and-skip stubs. Remains Category F: blocked on an external vendor account, not an application gap. |
| GAP-039 | Admin-editable notification templates | SRS-17-9 | Open (Phase 3) | **Still open, unaffected** | Not touched. |
| GAP-040 | Unified revenue report | SRS-19-19 | Open — BLOCKED | **Still BLOCKED** | No revenue-definition decision recorded in `docs/decisions.md`; no code change in 25B–26D touched any revenue/reporting-aggregation logic that would resolve the multi-currency ambiguity. Uncertainty #4 remains open. |
| GAP-041 | Referral notification listener + promo credits | SRS-16-14/16-16 | Open ("not touched"/"dead events") | **CORRECTED → Partially implemented** | `SendReferralRewardNotifications` (`app/Referral/Listeners/`) is fully wired to `ReferralRewardCredited`/`Held`/`Reversed` in `EventServiceProvider.php:253-261` — queued, idempotent (`NotificationIdempotencyGuard`), identity-masked (`ReferredStudentMask`). This directly contradicts the "dead events" claim in the original `SRS_Compliance_Audit.md` and Phase 25A's uncritical carry-forward of it. **What remains genuinely missing:** an independent promotional-credit *campaign* engine (marketing-driven credits not triggered by a referral) — confirmed absent (`find app -iname "*Promo*"` empty; `ReferralRewardType` enum only has `Percentage`/`Fixed`, both referral-reward calculation modes, no promotional-credit case). |

**Net effect of this phase:** 3 gaps closed via verified Phase 25B–26D work (GAP-002,
GAP-003, GAP-023) plus one additional closure discovered (GAP-027, not anticipated by
either prior audit) plus one corrected classification (GAP-041, now partially rather
than fully open). All other gap statuses from Phase 25A were re-confirmed against
current code and are unchanged.

**Classification buckets:**
- **Closed:** GAP-004, 005, 006, 009, 010, 011, 012, 013, 019, 020, 021, 026, 027,
  030, 031, 033, 034, plus **GAP-002, GAP-003, GAP-023** (newly confirmed this phase).
- **Partially implemented:** GAP-041.
- **Open and actionable:** GAP-007, 014, 015, 016, 017, 018, 022, 024, 025, 028
  (bundled/deferred by design), 029, 035, 036, 037, 039.
- **Blocked (product/finance decision):** GAP-001, GAP-040.
- **Operational/external:** GAP-032 (backup infrastructure), GAP-038 (external
  SMS/WhatsApp vendor).
- **Unable to verify:** none.

---

## 2. Unrelated pre-existing baseline failure (documented, not reopened)

`tests/Feature/Reporting/LearningAnalyticsReportTest.php` fails with 10 errors, all
identical in signature: `SQLSTATE[HY000]: General error: 3819 Check constraint
'chk_homework_assignments_context' is violated` on `HomeworkAssignment::factory()`
rows that omit both `booking_id` and `learning_plan_id`. This was proven
pre-existing and unrelated to Phase 26 work via `git stash` (identical failure count
and test names with the stash applied and removed) during Phase 26B, and reconfirmed
identically during Phase 26C and Phase 26D. Re-run in this audit phase produces the
exact same 18 passed / 10 errors, same test names, same constraint name — **not a new
or changed failure**, so it is not counted against any gap's closure evidence and was
not investigated further (out of this audit's read-only scope).

---

## 3. Evidence for GAP-002, GAP-003, and GAP-023 closure

### GAP-002 — Wallet payment for lessons (SRS §13.13/SRS-13-7)

- **Authoritative boundary:** `BookingPaymentServiceInterface::payWithWallet(Booking $booking, User $student): Booking`, implemented in `app/Booking/Services/BookingPaymentService.php:209`.
- **Entry points:** reuses the single `BookingPaymentService` choke point; no parallel wallet-specific booking-confirmation path exists.
- **Authorization/feature-toggle:** `assertWalletPaymentAllowed()` re-validates the booking is `Pending`/owned by the acting student under a row lock; `FeatureSettings::wallet_enabled` gates the option (verified present in the checkout UI).
- **Idempotency/concurrency:** `Booking::query()->whereKey($booking->id)->lockForUpdate()` — a concurrent second call blocks until the first commits, then is rejected by the precondition re-check (no double-debit possible); `WalletLedgerService::debit()` also carries its own idempotency key.
- **Transaction behavior:** debit + `finalizeSuccessfulPayment()` commit as one `DB::transaction()`; `BookingPaymentSucceeded` dispatches only after commit.
- **Historical/refund integration:** `SyncPaymentOnCancellation.php:55` already refunds every cancelled booking to wallet via the pre-existing (Phase 16A.1) `refundToWallet()` path regardless of original payment method — confirmed no gap here; wallet-paid bookings are refunded identically to gateway-paid ones.
- **Reporting:** `grep -n "provider.*IN\|whereIn.*provider" app/Reporting/Repositories/*.php` returns nothing — no report hard-filters by gateway provider value, so wallet-paid rows are not silently excluded.
- **Focused evidence (run today):** `tests/Feature/Booking/WalletLessonPaymentTest.php` — part of the 161/161 passing batch in §14.
- **Residual risk:** none identified. **Fully closed.**

### GAP-003 — Wallet recharge via gateway (SRS §13.6/SRS-13-6)

- **Authoritative boundary:** `WalletRechargeService` (`app/Wallet/Services/WalletRechargeService.php`) with `WalletRechargeReconciliationService` for drift recovery; `WalletRechargeWebhookController` (`app/Http/Controllers/Api/`) as the provider-callback entry point.
- **UI:** `resources/views/livewire/frontend/student/wallet-overview.blade.php` — the previously-disabled "Coming soon" button is now a live `wire:submit.prevent="initiateRecharge"` form with min/max limits from `WalletSettings`, Stripe Payment Element integration, and status polling.
- **Provider support:** Razorpay and Stripe both implemented; monitoring surface `app/Filament/Pages/RechargeMonitoring.php` (read-only, no mutation actions).
- **Focused evidence (run today):** `WalletRechargeTest.php`, `StripeWalletRechargeTest.php` — part of the 161/161 passing batch in §14; `WalletRechargeWebhookTest.php`, `WalletRechargeReconciliationTest.php`, `WalletRechargeConcurrencyTest.php`, `WalletRechargeMonitoringTest.php` exist as additional regression coverage (not re-run in this audit to respect the narrow-test-only constraint, since GAP-002/003/023/027 were the only gaps requiring re-verification this phase).
- **Residual risk:** none identified. **Fully closed.**

### GAP-023 — Learning-plan progress composite (SRS §6.17.5)

- **Authoritative boundary:** `LearningPlanProgressCalculator::calculate()` (`app/Services/Student/LearningPlanProgressCalculator.php`) — equal-weight average across milestones, directly-linked homework, plan-linked lessons, and the latest structured academic-review percentage, excluding domains with no applicable evidence. `LearningPlanProgressService::recalculate()` is the single locked, unchanged-value-skipping write boundary.
- **Lesson linkage (the former blocker):** `lessons.learning_plan_id` (nullable FK, `restrictOnDelete`), resolved server-side only by `LessonLearningPlanResolver` at the single lesson-creation boundary (`CreateLessonFromBookingAction`) — ambiguous or cross-student/subject/instructor candidates always resolve to null, never guessed.
- **Review linkage (the final blocker):** `learning_plan_reviews.progress_percent` (nullable, `unsignedTinyInteger`, CHECK 0–100), latest-eligible-review semantics (deterministic `reviewed_at DESC, id DESC` ordering), never averaged, never inferred from free text.
- **Feature/lifecycle protection:** `LearningPlanProgressService` refuses to write to a Completed/Archived plan (SRS §6.19), centrally, for every trigger.
- **Focused evidence (run today):** `LearningPlanCompositeProgressTest.php`, `LearningPlanLessonProgressTest.php`, `LearningPlanReviewProgressTest.php` — all part of the 161/161 passing batch in §14.
- **Residual risk:** none — all four SRS evidence domains (milestones, homework, lessons, reviews) now contribute. **Fully closed.**

---

## 4. Ranked actionable backlog

| Rank | Gap | Severity | Impact | Dependencies | Scope | Reason |
|---|---|---|---|---|---|---|
| **1** | **GAP-007 — Invoice/receipt generation** | High | Financial-compliance/trust; every payment method (gateway + now wallet, both booking and recharge) succeeds today with zero durable financial record for the student | Payment core (fully built and stable: `BookingPaymentSucceeded`, `WalletRechargeSucceeded`) | M | Directly completes the payment journey Phases 25B–25F/D just finished; SRS text is unusually precise and non-ambiguous (§14.21–14.23, exact field list, explicit "tax out of scope for V1," explicit numbering-format example); zero open product decision; reusable foundation is exceptionally strong (both `BookingPayment` and `WalletRecharge` already carry every field an invoice needs) |
| 2 | GAP-018 — Waitlist | High | Real conversion-funnel gap (a feature flag is already checked somewhere with nothing to gate) | none | L | Larger, more speculative scope than GAP-007; the "flag checked, nothing built" finding is itself worth a note but doesn't change its L-scope ranking |
| 3 | GAP-024 — Localized pricing on discovery | High | Guest-conversion UX | Pricing resolver (exists) | M | Comparable scope to GAP-007 but UX/discovery rather than financial-compliance; criterion 1 (financial/compliance impact) favors GAP-007 |
| 4 | GAP-014/015 — Fraud + compliance monitoring | High | Security/abuse, compounds with now-fixed demo rule | GAP-004 (satisfied) | L | Correctly still deferred as its own cross-cutting initiative |
| 5 | GAP-016/017 — Support cases + messaging | Critical | Real gap, but XL, no shared foundation | none | XL | Still correctly sequenced after smaller, atomic wins |
| 6 | GAP-041 (remaining half) — Promotional-credit campaign engine | Medium | Marketing/growth, not safety-critical | Referral core (exists) | S/L | Now smaller in scope than originally documented (notifications already done), but still a net-new campaign concept requiring product definition of promo rules — not selected this round |
| 7+ | GAP-022, 029, 035, 036, 037, 039, 025 | Medium–High | Real but narrower/lower urgency | varies | S–L | Unchanged ranking rationale from Phase 25A |

**GAP-007 confirmed as the correct pick** — the Phase 25A hypothesis holds. No later
work (25B–26D) introduced any invoice/receipt functionality; the payment core it
depends on is now *more* complete than when Phase 25A wrote its hypothesis (a second
payment method — wallet — now exists and needs the same invoice treatment as gateway
payments), which strengthens rather than weakens the case.

---

## 5. Selected requirement

**GAP-ID:** GAP-007
**SRS sections:** §14.21 "Invoice and Receipt Strategy", §14.22 "Invoice Numbering",
§14.23 "Student Billing Details", §14.24 "Refund References" (context only), plus the
"Invoice Generation" / "Invoice Number" / "Invoice Download" / "Invoice Immutability"
requirement block (~line 13189).

**Faithful paraphrase:** the platform shall generate an immutable, uniquely-numbered
invoice/receipt record for every successful payment (wallet recharge, paid lesson
booking/booking payment, and manual financial correction where applicable), containing
student name, billing country, amount, currency, payment date, payment reference,
service description, the relevant booking or wallet-recharge reference, platform
business details, and the invoice/receipt number itself. Numbering must be unique and
its format configurable (SRS gives the example `STEM/INV/2026/000001`). Once
generated, an invoice must never be silently edited — a correction requires a future
adjustment/credit-note mechanism, out of scope for V1. Tax handling is explicitly out
of scope for V1. Download is Medium priority (generation/numbering/immutability are
High); this phase treats download as in-scope but its exact rendering format (true PDF
vs. printable HTML) is flagged below as an interpretation, not a hard SRS mandate.

**Current implementation status:** absent. `find app -iname "*invoice*" -o -iname
"*receipt*"` returns zero results.

**Why it outranks every other actionable candidate:** see §4, criteria 1–3
(financial/compliance impact, SRS clarity, journey completion) dominate; no other
open gap scores as strongly on all three simultaneously while also being free of
business-decision ambiguity (unlike GAP-001/040) and free of XL scope (unlike
GAP-016/017).

**Why superficially higher-risk candidates were not selected:**
- GAP-016/017 (Critical) are XL and share no existing foundation — selecting either
  would, in effect, bundle several independent requirements into one phase.
- GAP-014/015 (High, security) are correctly still deferred as their own initiative;
  nothing about GAP-002/003/023's closure changes their scope or urgency.
- GAP-018 (waitlist, High) is L-scope and, notably, has a checked-but-unused feature
  flag — worth flagging for a future audit, but not a reason to leapfrog GAP-007.

**Explicit interpretations distinguished from direct SRS text:**
1. **Invoice download format** — SRS says "students shall be able to download
   invoices" (Medium priority) but does not mandate PDF specifically. This audit
   recommends adding a pure-PHP, no-external-service PDF renderer (e.g.
   `barryvdh/laravel-dompdf`) as the cleanest way to satisfy "download" literally,
   but a printable/downloadable HTML view would also satisfy the letter of the
   requirement for V1 if the implementation phase judges a new dependency
   undesirable. **Flagged as an interpretation, not a blocker** — either choice is
   safe, reversible, and contacts no external provider.
2. **"Platform business details"** — SRS does not name which fields. This audit
   recommends reusing `GeneralSettings::organization_name`/`address`/`support_email`
   (already present, already the platform's canonical business-identity fields)
   rather than inventing a new settings surface.
3. **Numbering mechanism** — SRS gives an example format but not an algorithm. No
   existing sequential-numbering utility exists in the codebase (`Booking.reference`
   and `WalletRecharge`/`BookingPayment` references are all random, not sequential).
   A new, concurrency-safe sequence generator is necessary new work, not a reusable
   foundation — flagged explicitly in §7/§8, not treated as a blocker.
4. **Manual financial correction invoices** — SRS lists "manual financial
   correction, where applicable" as an invoice source. No such correction mechanism
   currently exists in the codebase outside admin wallet-ledger tools. This audit
   recommends scoping V1 invoice generation to the two concrete, already-implemented
   success events (`BookingPaymentSucceeded`, `WalletRechargeSucceeded`) and
   explicitly excluding manual-correction invoicing until a manual-correction
   feature itself exists — **flagged as a scope boundary, not a silently-resolved
   ambiguity**.

**Blockers:** none.

**Recommended phase identifier and title:** **Phase 27B — Invoice & Receipt
Generation for Wallet and Booking Payments (SRS §14.21–14.23 / GAP-007)**

---

## 6. Implementation baseline

| Layer | Component | Status | Reusable? |
|---|---|---|---|
| Events | `BookingPaymentSucceeded` (`app/Booking/Events/`), `WalletRechargeSucceeded` (`app/Wallet/Events/`) | Exist, dispatch after commit | Fully — natural invoice-generation trigger points |
| Models | `BookingPayment` (`amount_minor`, `currency_code`, `paid_at`, `user_id`, `booking_id`, `provider` incl. `'wallet'`), `WalletRecharge` (`amount_minor`, `currency_code`, `succeeded_at`, `user_id`, `wallet_id`) | Exist, structurally invoice-ready | Fully |
| Settings | `GeneralSettings` (`organization_name`, `address`, `support_email`, `support_phone`, `website_url`) | Exists | Fully, as "platform business details" |
| Billing identity | `User`, `UserProfile::country_id` → `Country` → `defaultCurrency` | Exists | Fully, as "student name"/"billing country" |
| Admin surface | `app/Filament/Resources/BookingPayments` | Exists (payment-attempt visibility) | Partially — no invoice tab/relation yet |
| PDF/document rendering | none | **Missing** | New dependency required (see §5 interpretation #1) |
| Sequential numbering | none (`Str::random()` used everywhere for references) | **Missing** | New component required |
| Notifications | `SendBookingNotifications`, `SendWalletNotifications` (existing, queued, idempotent) | Exist | Reusable if invoice-ready notification is desired (not SRS-mandated; flag only) |

**Fully reusable foundations:** payment-success events, payment/recharge models,
platform business-identity settings, billing-country resolution.
**Disconnected foundations:** none identified (this is a genuinely net-new domain,
not a half-built one like GAP-002/003 were).
**Missing behavior:** invoice model/table, sequence generator, PDF/HTML rendering,
download route, admin visibility.
**Stale documentation:** none found referencing invoices as if implemented.
**Speculative additions to avoid:** tax computation, multi-line/itemized invoices,
credit-note/adjustment workflow, subscription/recurring invoicing — all explicitly
out of SRS V1 scope and must not be introduced.

---

## 7. Authoritative enforcement boundary

**Proposed boundary:** a single new `InvoiceService::generateForBookingPayment(BookingPayment $payment): Invoice`
and `InvoiceService::generateForWalletRecharge(WalletRecharge $recharge): Invoice`,
called only from two new, thin, queued listeners on the two existing success events —
never from a controller, Livewire component, or Filament action directly.

| Entry point | Current path | Proposed boundary | Bypass risk | Change |
|---|---|---|---|---|
| Booking payment success (gateway or wallet) | `BookingPaymentSucceeded` → `SendBookingNotifications` only | Add `GenerateInvoiceOnBookingPaymentSucceeded` listener → `InvoiceService` | None if `InvoiceService` is the only writer of the `invoices` table | New listener + service method |
| Wallet recharge success | `WalletRechargeSucceeded` → `SendWalletNotifications` only | Add `GenerateInvoiceOnWalletRechargeSucceeded` listener → `InvoiceService` | Same | New listener + service method |
| Admin/Filament | No admin-initiated invoice creation today | N/A | None — no such path should be added; admins only *view* invoices | No new admin-write action |
| Console/queue | None | N/A | None | No change |
| API | None (no public payment API) | N/A | None | No change |

Idempotency: each listener must generate at most one invoice per source payment
(`unique` constraint on `(source_type, source_id)`, not merely an application-level
check) — a redelivered event must never produce a duplicate invoice. Transaction:
invoice creation commits within the same request as the listener's own handling, but
since both trigger events already implement `ShouldDispatchAfterCommit`, invoice
generation only ever fires after the payment/recharge itself is durably committed.
Failure/rollback: if invoice generation fails, the payment/recharge must not be
affected (it already committed) — a failed invoice-generation job should log/retry via
the existing queue-retry convention (`tries`/`backoff`, matching every other listener
in the codebase) without blocking or reversing the underlying payment.

---

## 8. Precise business rules

| Rule | SRS support | Note |
|---|---|---|
| Generated only for a payment/recharge that reached its authoritative success state (`BookingPaymentRecordStatus::Captured` / `WalletRechargeStatus::Succeeded`) | §14.21 ("for successful payments") | Explicit |
| One invoice per source payment, enforced by a unique constraint on `(source_type, source_id)` | §14.22 (uniqueness) + idempotency convention | Explicit + established pattern |
| Invoice number format configurable, unique, monotonic within its configured scope (e.g., per year) | §14.22, example `STEM/INV/2026/000001` | Explicit; exact algorithm is new work (§5, interpretation #3) |
| Immutable after creation — no update path, ever; correction is out of scope for V1 | §14.22 ("should remain immutable"), §13209 ("Invoice Immutability", High priority) | Explicit |
| Required fields: student name, billing country, amount, currency, payment date, payment reference, service description, booking/recharge reference, platform business details, invoice number | §14.21 (exact list) | Explicit |
| Tax fields/calculation: none | §14.21 ("Tax handling is out of scope for Version 1") | Explicit exclusion |
| Eligible actor for viewing: the paying student (self-service) and authorized admins only | Implicit self-service convention, mirrors `WalletPolicy` | Interpretation, following an established pattern |
| No admin-initiated manual invoice creation in V1 | §14.21 mentions "manual financial correction, where applicable" but no correction mechanism exists yet | Scope boundary, not silently resolved — flagged in §5 |
| Download: student can retrieve their own invoice; admin can retrieve any | §13203 ("Invoice Download", Medium priority) | Format is an interpretation (§5 #1) |
| Currency/amount semantics: verbatim copy of the source payment's `amount_minor`/`currency_code` at generation time, never recomputed later | §13.9/14.3 (immutable ledger conventions already established) | Explicit by extension of existing immutability rules |
| Notifications: not SRS-mandated for invoice generation itself; existing payment-success notifications already inform the student a payment succeeded | — | Flag only — do not invent a new notification unless the implementation phase finds an explicit need |
| Historical/backfill: no retroactive invoice generation for payments that succeeded before this phase ships | Consistent with every prior phase's "no automatic historical backfill" convention | Interpretation, following established convention |
| Reporting/export: invoices are a read-only projection of already-reported payment data; no existing report needs modification unless an "invoice number" column is explicitly requested | — | To be confirmed empirically during implementation, not assumed |
| Privacy: an invoice must never expose another student's data; billing country/name are the invoice owner's own | Existing privacy conventions | Interpretation |
| Admin/system exemptions: none — even an admin-corrected `BookingPayment`/`WalletRecharge` (if such a path is added later) would still go through the same generation boundary | — | Interpretation, protecting single-writer discipline |

**Material ambiguities requiring explicit product/finance sign-off before or during
implementation:** none identified. The four interpretations in §5 are implementation
details with safe, reversible defaults, not blocking ambiguities.

---

## 9. Database and migration plan

| Table | Change | Type/nullability | Default | FK behavior | Constraints/indexes | Historical/backfill | Rollback |
|---|---|---|---|---|---|---|---|
| `invoices` (new) | `id` (uuid, matches `BookingPayment`/`WalletRecharge` PK convention), `invoice_number` (string, unique), `source_type` (string), `source_id` (uuid), `user_id` (FK → users, `restrictOnDelete`), `student_name_snapshot`, `billing_country_snapshot`, `amount_minor` (int), `currency_code`, `service_description`, `booking_reference` (nullable), `wallet_recharge_reference` (nullable), `issued_at` (timestamp), timestamps | New table | none | `user_id` FK `restrictOnDelete` (an invoice is a financial record; never orphaned by a user hard-delete, mirroring `HomeworkAssignment`/`LearningPlanReview` conventions) | unique `invoice_number`; unique `(source_type, source_id)` (idempotency); index on `user_id` | No backfill — new invoices only, from the date this phase ships forward | `dropIfExists('invoices')` — fully reversible, purely additive |
| `invoice_number_sequences` (new, only if a per-scope-atomic-counter design is chosen over a single global auto-increment) | `scope_key` (e.g. year), `next_number` (unsigned int) | New table, small | `next_number` default 1 | none | unique `scope_key`, row-locked increment | none | `dropIfExists` |

**Snapshot fields are deliberate, not redundant traceability:** per this audit's own
"do not add redundant traceability columns when existing immutable relationships
already provide deterministic linkage" instruction, `source_type`/`source_id` alone
would suffice for *linkage*, but the invoice's student-name/billing-country/amount
fields must be **snapshotted at issuance**, not joined live, because SRS §14.22
requires invoices to remain immutable even if the user later changes their profile
name or billing country — a live join would silently mutate historical invoices. This
is not redundant traceability; it is the immutability requirement itself.

**No existing table requires modification.** Both source models already carry every
field an invoice needs to read at generation time; nothing needs to change on
`booking_payments` or `wallet_recharges` (contradicting nothing from GAP-002/003's own
migration decisions, which correctly found no schema change necessary there either).

**Engine compatibility:** this project's tests run on MySQL (confirmed throughout
Phases 25B–26D); `unique`/standard FK constraints are fully supported. No CHECK
constraint is needed here (unlike GAP-023's percentage column) since there is no
bounded-numeric-range rule for invoice fields.

---

## 10. Cross-domain integration impact

| Domain | Impact | Evidence |
|---|---|---|
| Students/instructors | Students gain a downloadable invoice; instructors unaffected (invoices are payment-side, not earnings-side) | `InstructorCompensationResolver` never reads payment method or invoice state (unchanged finding from Phase 25A) |
| Bookings/lessons | None — lessons key off `payment_status`, not invoice existence | No lesson/outcome code reads `booking_payments` beyond status |
| Payments/wallets/refunds/payouts | New read-only consumer of two existing success events; zero change to payment/refund logic itself | `BookingPaymentSucceeded`/`WalletRechargeSucceeded` dispatch signatures unchanged |
| Referrals/promotions | None | No shared code path |
| Learning plans/homework | None | No shared code path |
| Notifications | Optional, not mandated (§8) | Existing notification classes untouched if no new notification is added |
| Reports/dashboards/exports | Potential future addition of an "invoice number" report column; no existing report requires modification for GAP-007 to function | To be confirmed empirically in implementation (do not assume, per this audit's own constraint) |
| Admin operations | New read-only Filament relation/tab on `BookingPayments`/a new `Invoices` resource (view-only) | New, additive |
| Audit trails | Invoice creation should use the same `AuditTrailService`/system-audit convention as every other financial side-effect (e.g., `logSystem()` pattern seen in `WalletRechargeReconciliationService`) | Reuse, not new mechanism |
| Privacy | Invoice fields are self-service only; no cross-user exposure | Mirrors `WalletPolicy` |
| Queues/schedules/failed jobs | Two new queued listeners join the existing `notifications` queue convention; failed invoice generation retries via the existing failed-job UI (GAP-034, already closed) | Reuses closed infrastructure |
| Deployment/configuration | If a PDF library is added, requires `composer require` + no further deployment change (pure-PHP rendering, no external service) | New dependency, zero infra impact |

"No impact" rows are evidence-based per the grep/read citations above, not assumed.

---

## 11. Focused test plan (for the future implementation phase — not run in this audit)

**New:**
- `tests/Feature/Payment/InvoiceGenerationTest.php` — happy path for both booking-payment and wallet-recharge success (invoice created with correct snapshotted fields), uniqueness (duplicate event delivery produces exactly one invoice), immutability (no update path exists / attempting a direct model update is not exposed anywhere), numbering format/uniqueness across many invoices, authorization (student can only fetch their own invoice; unrelated student cannot), download route/response (whatever format is chosen), privacy (no cross-user field leakage).
- `tests/Feature/Payment/InvoiceNumberSequenceTest.php` (if a dedicated sequence table is chosen) — concurrency-safe sequential allocation under simultaneous requests, no gaps/duplicates.

**Regression (run named, not swept):**
- `tests/Feature/Booking/WalletLessonPaymentTest.php`, `tests/Feature/Wallet/WalletRechargeTest.php`, `tests/Feature/Wallet/StripeWalletRechargeTest.php` — prove the new listeners do not alter existing payment/recharge success behavior.
- `tests/Feature/Booking/PaymentGatewaySettingsAdminTest.php` — prove gateway settings/admin surfaces are unaffected.

**Checks to include only if scope touches them:** Pint on all changed PHP files;
`migrate:status` after the new migration; route inspection only if a download route is
added; `npm run build` only if any compiled asset changes (unlikely — Blade/Livewire
only expected).

---

## 12. Dependency-ordered implementation slices

| Slice | Outcome | Files | Tests | Mandatory? |
|---|---|---|---|---|
| 1 | `invoices` table + model + factory | new migration, `app/Models/Invoice.php`, factory | migration status clean | Mandatory |
| 2 | Numbering mechanism decided and built | new sequence service/table, or a single locked counter row | unit-level numbering test | Mandatory |
| 3 | `InvoiceService::generateForBookingPayment()`/`generateForWalletRecharge()` | new service | `InvoiceGenerationTest.php` happy path | Mandatory |
| 4 | Listener wiring on the two existing success events | `EventServiceProvider.php`, two new listeners | idempotency/duplicate-delivery test | Mandatory |
| 5 | Student-facing download surface | new route + Blade/PDF view | authorization + download test | Mandatory (SRS Medium priority, but named as an explicit requirement) |
| 6 | Admin read-only visibility | Filament relation/resource | none beyond existing admin-auth tests | Conditional — only if a concrete admin need is confirmed; do not build speculatively |
| 7 | Regression closure | — | full named-file batch from §11 | Mandatory |

No slice includes placeholder code as a completion condition; each has a concrete,
testable outcome.

---

## 13. Risks, assumptions, and blockers

| Risk | Classification |
|---|---|
| Choice of PDF vs. HTML download format | Manageable — explicit interpretation, not blocking (§5) |
| Numbering algorithm concurrency safety | Manageable — standard row-locked-counter pattern, already proven elsewhere in this codebase (e.g., `WalletLedgerService`'s locking discipline) |
| Whether any existing report needs an invoice-number column | Manageable — to verify empirically in Slice 7, not assumed either way |
| Manual-financial-correction invoicing | Explicitly out of V1 scope until a correction mechanism itself exists — not a blocker, a scope boundary |
| Tax handling | Explicitly out of scope per SRS itself — no risk |
| Blocking product decision | **None** |

No blocking risk was found. GAP-007 has no open ambiguity comparable to GAP-001 or
GAP-040.

---

## 13a. One optimized implementation prompt

*(Provided below for future use — not executed in Phase 27A.)*

> **Phase 27B — Invoice & Receipt Generation for Wallet and Booking Payments**
>
> **Goal:** Implement SRS §14.21–14.23 (GAP-007): generate an immutable,
> uniquely-numbered invoice/receipt record for every successful payment —
> both booking payments (gateway or wallet, via `BookingPaymentSucceeded`)
> and wallet recharges (via `WalletRechargeSucceeded`) — containing student
> name, billing country, amount, currency, payment date, payment reference,
> service description, booking/recharge reference, platform business
> details, and a unique invoice number. Students may download their own
> invoice; admins may view (never create or edit) any invoice.
>
> **Authoritative sources, in order:** `docs/SRS.md` §14.21–14.24 (and the
> "Invoice & Receipt"/"Invoice Download"/"Invoice Immutability" block near
> line 13189) is authoritative. `docs/audits/phase-27a-next-srs-requirement-selection-audit.md`
> (this audit) is supporting evidence, not a substitute for reading the
> current code yourself — verify every filename and class this prompt
> names against the actual codebase before relying on it; things may have
> changed since this audit was written.
>
> **Mandatory scope:** invoice generation, numbering, immutability, and
> student/admin read access only. Reuse `BookingPaymentSucceeded` and
> `WalletRechargeSucceeded` (verify their current dispatch points and
> payload shape first) as the only two generation triggers — do not add a
> third trigger, and do not let any controller/Livewire/Filament code
> create an invoice directly.
>
> **Explicit exclusions:** tax calculation of any kind (explicitly out of
> scope per SRS §14.21); a credit-note/adjustment/correction workflow;
> manual financial-correction invoicing (no such correction mechanism
> exists yet — do not build one as a side effect of this phase); itemized
> or multi-line invoices; subscription/recurring invoicing; any new
> payment or wallet business logic (this phase only reads already-settled
> payment state); any change to `booking_payments`, `wallet_recharges`, or
> any existing payment/refund/wallet-ledger code path; any real
> PDF-generation SaaS/external API (a pure-PHP library like
> `barryvdh/laravel-dompdf` is acceptable; no network call of any kind is
> acceptable). Do not add phase-number comments to production code. Do
> not run the complete test suite or a directory-wide sweep. Do not run
> `migrate:fresh`.
>
> **Step 0 — Revalidate before building.** Confirm current field names/types
> on `BookingPayment` and `WalletRecharge` (amount, currency, paid/succeeded
> timestamp, user relation), confirm the exact current dispatch site and
> payload of both success events, confirm whether any invoice/receipt code
> was added since this audit, and confirm whether `GeneralSettings` still
> holds `organization_name`/`address`/`support_email` as the platform's
> business-identity fields. Report findings before writing code.
>
> **Step 1 — Schema.** One new `invoices` table: uuid PK, unique
> `invoice_number`, `source_type`/`source_id` (unique together, for
> idempotency and linkage), `user_id` (FK, `restrictOnDelete`),
> snapshotted `student_name`, `billing_country`, `amount_minor`,
> `currency_code`, `service_description`, `booking_reference`
> (nullable), `wallet_recharge_reference` (nullable), `issued_at`,
> timestamps. Snapshot the student-identity fields at issuance — do not
> live-join `users`/`user_profiles`, since a later profile change must
> never alter a historical invoice. Decide and implement a
> concurrency-safe, configurable-format sequential numbering mechanism
> (SRS example: `STEM/INV/2026/000001`); no existing sequence generator
> exists in this codebase — this is new work. No changes to any existing
> table. Purely additive, reversible migration.
>
> **Step 2 — `InvoiceService`.** One authoritative service with
> `generateForBookingPayment(BookingPayment $payment): Invoice` and
> `generateForWalletRecharge(WalletRecharge $recharge): Invoice`. Both must
> be idempotent at the database level (unique `(source_type, source_id)`,
> not just an application check) — a redelivered event must never create a
> second invoice for the same payment. No update/delete method — invoices
> are immutable by omission, not by a guard you have to remember to call.
>
> **Step 3 — Listeners.** Two new, thin, queued listeners (matching this
> codebase's established `ShouldQueue`/`tries`/`backoff` convention) on
> `BookingPaymentSucceeded` and `WalletRechargeSucceeded`, registered in
> `EventServiceProvider`, calling only the service above. Verify both
> events already implement `ShouldDispatchAfterCommit` (or equivalent) so
> invoice generation never fires on an uncommitted payment.
>
> **Step 4 — Student/admin access.** A student-facing download surface for
> their own invoices only (authorization: owner or admin, mirroring the
> existing `WalletPolicy`/booking-ownership convention — do not invent a
> new authorization primitive). Add read-only admin visibility only if you
> confirm a concrete existing admin surface naturally wants it (e.g. a
> relation on the existing `BookingPayments` Filament resource) — do not
> build a speculative new management screen.
>
> **Step 5 — Verify integration, don't assume it.** Check whether any
> existing payment/wallet report or export would benefit from or needs an
> invoice-number column; do not add one unless a concrete existing report
> already exposes per-payment rows where it would fit. Confirm no existing
> report breaks.
>
> **Step 6 — Focused tests only.** New `tests/Feature/Payment/InvoiceGenerationTest.php`
> (happy path both trigger types, duplicate-event idempotency, immutability,
> numbering uniqueness/format, authorization, download, privacy) and, if a
> dedicated numbering table is built, `tests/Feature/Payment/InvoiceNumberSequenceTest.php`
> (concurrency-safe allocation). Re-run named (not swept): `tests/Feature/Booking/WalletLessonPaymentTest.php`,
> `tests/Feature/Wallet/WalletRechargeTest.php`, `tests/Feature/Wallet/StripeWalletRechargeTest.php`,
> `tests/Feature/Booking/PaymentGatewaySettingsAdminTest.php`. Pint on all
> changed PHP files. `migrate:status` after the new migration. Route
> inspection if a download route is added. No `npm run build` unless a
> compiled asset actually changed.
>
> **Step 7 — Final report.** Exact SRS interpretation for numbering format
> and download rendering; files changed (new vs. modified); migration
> applied; exact focused test commands and reconciled totals
> (passed+failed+errors+skipped); confirmation no payment/wallet/refund
> business logic changed; confirmation no external provider was contacted;
> confirmation invoices are immutable (no update path exists in code, not
> just by convention); confirmation of scope exclusions honored (no tax,
> no credit notes, no manual-correction invoicing). Stop after Phase 27B.

---

## 14. Exact commands and verification results (this audit phase)

```
php artisan test --env=testing \
  tests/Feature/Booking/WalletLessonPaymentTest.php \
  tests/Feature/Wallet/WalletRechargeTest.php \
  tests/Feature/Wallet/StripeWalletRechargeTest.php \
  tests/Feature/Student/LearningPlanCompositeProgressTest.php \
  tests/Feature/Student/LearningPlanLessonProgressTest.php \
  tests/Feature/Student/LearningPlanReviewProgressTest.php \
  tests/Feature/Booking/RecordingFeatureToggleTest.php
```
Result: **161 passed, 0 failed, 0 errors, 0 skipped** (total = 161).

```
php artisan test --env=testing tests/Feature/Reporting/LearningAnalyticsReportTest.php
```
Result: **18 passed, 10 errors** — identical test names/signature to the documented
pre-existing baseline from Phase 26B/26C/26D (CHECK-constraint factory issue,
unrelated to GAP-002/003/023/027). Not counted against any closure.

No other commands were run. No migration was executed. No external provider was
contacted. `sources/` and other synced reference material were not read or modified
beyond this audit's own inspection of `docs/`.

---

## Final confirmations

- No application, migration, test, route, configuration, or UI file was modified
  during this phase.
- Only this audit report (`docs/audits/phase-27a-next-srs-requirement-selection-audit.md`)
  was created.
- Exactly one implementation requirement was selected: **GAP-007**.
- No unresolved product, finance, legal, compliance, or provider decision was
  silently resolved — GAP-001 and GAP-040 remain explicitly BLOCKED; the four GAP-007
  interpretations in §5 are flagged, reversible implementation details, not business
  decisions.
- No full test suite or directory-wide sweep was run — only the eight named test
  files above.
- No migration was executed (`migrate:status` was not even needed this phase, since
  no schema change was proposed for GAP-002/003/023 verification).
- No external payment, email, SMS, WhatsApp, storage, recording, or other provider
  was contacted.
- No synced reference material (`sources/`, etc.) was modified.
- Work stops after Phase 27A, as instructed.
