# Phase 25A — Next SRS Backlog Requirement Selection and Implementation-Readiness Audit

## Final decision: **READY**

---

## 1. Reconciled unresolved-backlog inventory

`docs/SRS_Compliance_Audit.md` (782 lines) predates all of Phase 24 (24A–24U). Its curated "Actionable Gap Register" (41 GAP-IDs) is the right unit of reconciliation — the full 502-row chapter matrix is not independently re-walked here (disproportionate for a selection phase), but every GAP-ID below was checked against current code, not assumed from the document.

| ID | Requirement | SRS ref | Audit status (2026, pre-24) | Current-code evidence | Updated status |
|---|---|---|---|---|---|
| GAP-001 | Manual instructor payout fallback | SRS-15-14 | Critical, Missing | No `markPaid`-style withdrawal action found in `app/Earnings` | **Still open — BLOCKED** (product decision, audit's own Uncertainty #1) |
| GAP-002 | Wallet payment for lessons | SRS-13-7 | Critical, Missing | No wallet payment provider/method in `app/Booking/Payments`; `WalletLedgerEntryType::BookingPayment` enum case exists but has zero producers | **Still open — actionable** |
| GAP-003 | Wallet recharge via gateway | SRS-13-6 | High, Missing | `wallet-overview.blade.php:34-35` — button still literally disabled, "Coming soon" | **Still open — actionable** |
| GAP-004 | One free demo/instructor | SRS-11-9 | Critical, Missing | `OneFreeDemoPerInstructorRule` wired into `FreeDemoType::rules()`, re-checked under lock | **Closed (Phase 24A)** |
| GAP-005 | Cancellation-window refund | SRS-11-20 | High, Missing | `CancellationRefundPolicy::decide()`, wired into `BookingService::cancel()` | **Closed (24C)** |
| GAP-006 | Reschedule limit | SRS-11-22 | High, Missing | `RescheduleLimitPolicy`, wired into `BookingService::reschedule()` | **Closed (24D)** |
| GAP-007 | Invoice/receipt generation | SRS-14-14/15/16 | High, Missing | No invoice model/route found | **Still open** (Phase 2 scope, XL) |
| GAP-008 | Demo-to-paid conversion incentive | SRS-15-7 | Medium, Missing | No conversion-bonus code | **Still open** (Phase 3 scope) |
| GAP-009 | Payment settings audit | SRS-20-18 | Critical, Partial | `saveSettingsWithAudit()` wired into all governed pages | **Closed (24G/24G.1, extended 24S)** |
| GAP-010 | Last Super Admin guard | SRS-23-7 | High, Missing | `SuperAdminGuardService` | **Closed (24E)** |
| GAP-011 | Role/permission audit via `AuditTrailService` | SRS-23-6 | Medium, Deviation | `RoleAuditRecorder`/`PermissionAuditRecorder` | **Closed (24Q/24Q.1)** |
| GAP-012 | Session idle-timeout enforcement | SRS-1-23 | High, Foundation Only | `TrackUserSession::expireIfIdle()` — race-safe, verified this session | **Closed (24F)** |
| GAP-013 | Student lifecycle write path | SRS-2-20/B1-12 | High, Foundation Only | `StudentLifecycleService`, extensively verified | **Closed (24H series)** |
| GAP-014 | Fraud-detection layer | SRS-B1-9 | High, Missing | No rule-based flag system found | **Still open** (Phase 2, L) |
| GAP-015 | Suspicious-activity/compliance monitoring | SRS-24-11 | High, Missing | None found | **Still open** (Phase 2, L) |
| GAP-016 | Support/dispute case system | SRS-25-1/2/5/6/7 | Critical, Missing | Only a one-way contact form | **Still open** (Phase 2, XL) |
| GAP-017 | Student-instructor messaging | SRS-17-11 | Critical, Missing | No conversation/message model | **Still open** (Phase 2, XL) |
| GAP-018 | Waitlist | SRS-10-25/2-12/16/20-6 | High, Missing | No waitlist model | **Still open** (Phase 2, L) |
| GAP-019 | Availability-change impact warning | SRS-10-12 | High, Missing | `AvailabilityChangeImpactService` | **Closed (24I)** |
| GAP-020 | Homework due-date reminders | SRS-7-11 | Critical, Missing | `HomeworkReminderSettingsPage`, dispatcher, channel-delivery ledger | **Closed (24K/24K.1)** |
| GAP-021 | Homework `learning_plan_id` FK | SRS-7-1/12 | High, Deviation | Migration `2026_07_19_100000_add_learning_plan_context...` with CHECK constraint | **Closed (24J)** |
| GAP-022 | Homework resource/attachment library | SRS-7-3/8 | High, Missing | No media collection for homework | **Still open** (Phase 2) |
| GAP-023 | Learning-plan progress composite | SRS-6-5 | High, Business-rule gap | Still milestone-only | **Still open** (Phase 2) |
| GAP-024 | Localized pricing on discovery | SRS-9-5 | High, Missing | Price resolver still booking-wizard-only | **Still open** (Phase 2) |
| GAP-025 | Recommendation sections | SRS-8-4/8-10 | High, Missing | Only `featured()`/random `related()` | **Still open** (Phase 3) |
| GAP-026 | `demo_lessons_enabled` toggle enforcement | SRS-20-5 | High, Foundation Only | `DemoLessonsEnabledRule` in `FreeDemoType::rules()` | **Closed (24B)** |
| GAP-027 | Platform `recording_enabled` outer-flag plumbing | SRS-20-8 | High, Disconnected | Confirmed via grep: zero readers of `FeatureSettings::recording_enabled` in meeting code | **Still open** (bundled with GAP-028, Phase 3) |
| GAP-028 | Recording capture pipeline | SRS-12-11 | High, Foundation Only | No fetch/store/retention/access code | **Still open** (Phase 3, L) |
| GAP-029 | Country-level feature flags | SRS-20-17/21-11 | High, Dead column | `Country::feature_flags` still unread | **Still open** (Phase 2, L) |
| GAP-030 | Per-recipient notification timezone | SRS-21-6 | Medium, Correctness bug | `RecipientTimezoneResolver` | **Closed (24L)** |
| GAP-031 | Currency active-status re-check at transaction time | SRS-21-4 | Medium, Validation gap | `CurrencyEligibilityPolicy` (24M), locked at payment initiation | **Closed (24M)** |
| GAP-032 | Backup strategy | SRS-27-27 | High, Missing | No backup package in `composer.json` | **Still open** (Category F — ops/deployment, not application code) |
| GAP-033 | Pulse APM activation | SRS-27-29 | High, Foundation Only | Pulse migrated/configured/permission-gated | **Closed (24O series)** |
| GAP-034 | Failed-job retry UI | SRS-26-2 | High, Missing | `FailedJobRetryService`, governed retry action | **Closed (24N)** |
| GAP-035 | `OperationalAlert` model | SRS-26-10 | High, Missing | Still generic activity→notify only | **Still open** (Phase 2, L) |
| GAP-036 | Redirect (301/302) management | SRS-22-16 | High, Missing | No redirect model | **Still open** (Phase 2) |
| GAP-037 | Media conversions | SRS-27-26 | Medium, Missing | No `registerMediaConversions()` | **Still open** (Phase 2) |
| GAP-038 | Real SMS/WhatsApp provider | SRS-17-2/3 | High, Foundation Only | Stubs still log-and-skip | **Still open** (Category F — external provider prerequisite) |
| GAP-039 | Admin-editable notification templates | SRS-17-9 | Medium, Missing | Templates still hard-coded PHP | **Still open** (Phase 3) |
| GAP-040 | Unified revenue report | SRS-19-19 | High, Deviation | Explicit code comment still states no revenue concept exists | **Still open — BLOCKED** (product/finance decision, audit's own Uncertainty #4) |
| GAP-041 | Referral notification listener + promo credits | SRS-16-14/16-16 | Medium, Missing/dead events | Not touched | **Still open** (Phase 2) |

**Net effect of Phase 24:** 16 of the original 41 actionable gaps are now closed (GAP-004, 005, 006, 009, 010, 011, 012, 013, 019, 020, 021, 026, 030, 031, 033, 034). The remaining 25 are unchanged from the original audit.

## 2. Updated classification of every unresolved item

- **A (supported, unimplemented):** GAP-002, 003, 007, 014, 015, 016, 017, 018, 022, 023, 024, 025, 027/028, 029, 035, 036, 037, 039, 041.
- **D (explicitly deferred / blocked product decision):** GAP-001 (manual payout — audit's own Uncertainty #1), GAP-040 (revenue definition — Uncertainty #4).
- **F (operational/deployment, not application implementation):** GAP-032 (backup strategy — infra package + retention policy, not a business-logic gap), GAP-038 (real SMS/WhatsApp provider — blocked on an external vendor account/API key, matching this phase's own "no external provider calls" constraint).
- **C (already implemented, audit stale):** GAP-004, 005, 006, 009, 010, 011, 012, 013, 019, 020, 021, 026, 030, 031, 033, 034 — all with concrete enforcement + focused-test evidence gathered directly in Phase 24V (377/377 tests passing across a representative batch) and re-confirmed by source reads in this phase.
- **G (unable to verify):** none — every item above was resolved to a concrete classification.

## 3. Ranked actionable requirements (Category A only)

| Rank | Gap | Severity | User/financial impact | Dependencies | Scope | Reason for priority |
|---|---|---|---|---|---|---|
| 1 | **GAP-002 — wallet payment for lessons** | Critical | Direct financial-journey blocker; `WalletLedgerEntryType::BookingPayment` already reserved but unused — a half-built financial feature is itself a risk | None (wallet ledger, currency policy, booking-payment finalization pipeline all already sound and reusable) | M | Highest severity of any unblocked item; reuses the most existing, already-audited financial infrastructure of any candidate; directly named as blocker #2 in the audit's executive summary |
| 2 | GAP-003 — wallet recharge via gateway | High | Completes the wallet journey's other half | None | M | Natural follow-on to GAP-002, but rated one severity tier lower and not independently reachable by real students today without GAP-002 to spend it on |
| 3 | GAP-007 — invoice/receipt generation | High | Compliance/trust, not blocking | Payment core (exists) | M | Real gap but not financial-safety-critical the way GAP-002 is |
| 4 | GAP-014/015 — fraud detection + compliance monitoring | High | Security/abuse risk, compounds with the now-fixed demo rule | GAP-004 (satisfied) | L | Large, cross-cutting, best scoped as its own initiative rather than squeezed in next |
| 5 | GAP-016/017 — support cases + messaging | Critical | Real user-facing gap, but XL and shares no existing foundation | none, but naturally sequenced after financial completeness | XL | Correctly deferred by the original roadmap to "Phase 2" — too large to be "the next" single requirement |
| 6+ | remaining Category A items | Medium–High | Real but narrower in scope/impact than the above | varies | S–L | Lower financial/safety impact or narrower journey coverage |

Ranking basis: financial safety and core-journey impact dominate (per the phase's own Step 3 criteria) — GAP-002 outranks GAP-016/017 despite comparable nominal severity because it is independently implementable, has zero open product ambiguity, and directly completes a financial capability the codebase has already half-built (the ledger entry type exists; only the debit call and checkout-entry-point wiring are missing).

## 4. Exactly one selected next requirement

**Selected requirement: GAP-002 — Wallet payment for lessons**
**SRS reference:** §13.13 (SRS-13-7)
**Compliance-audit identifier:** GAP-002

**Why it is next:** It is the highest-severity (Critical) Category A item with no open product ambiguity. The domain groundwork is unusually complete for an "unimplemented" gap — `WalletLedgerEntryType::BookingPayment` already exists as a reserved, unused enum case; `WalletLedgerService::debit()` is already idempotent, row-locked, and balance-checked; `BookingPaymentServiceInterface::markPaid()` already provides the exact booking-finalization choke point a wallet payment needs to reuse (meeting creation, activity logging, status transition) without duplicating any of that logic. It closes a requirement the SRS states in unambiguous, non-conditional language ("Students shall be able to pay for lessons using their wallet balance").

**Why higher-risk-looking alternatives were not selected:**
- **GAP-001** (manual payout) is rated Critical too, but is explicitly **BLOCKED** by an unresolved product decision (manual mark-paid vs. RazorpayX-only) that this phase's own constraints forbid resolving unilaterally ("Do not select... while awaiting an unresolved business decision").
- **GAP-016/017** (support cases, messaging) are real Critical gaps but are XL, share no existing foundation, and the original roadmap itself sequences them after financial completeness — selecting either would violate "do not combine multiple independent requirements" in spirit, since either is really several requirements bundled into one module.
- **GAP-003** (recharge) is a legitimate close second, but is High (not Critical) severity and, unlike GAP-002, has no reserved-but-unused domain scaffolding already in place — GAP-002 is simply further along and lower-risk to build next.
- **GAP-040** (revenue report) is explicitly blocked on a multi-currency product definition the codebase's own authors declined to guess at.

## 5. SRS evidence and accepted interpretation

- **SRS §13.13** (paraphrased per the compliance audit, itself derived from `docs/SRS.md`): a student with a sufficient wallet balance may pay for a lesson using that balance instead of (or as an alternative checkout method to) a payment gateway.
- **SRS §13.7** (wallet single-currency): a wallet's currency is fixed at creation from the student's billing country; already implemented (`WalletService::resolveCurrency()`).
- **SRS §13.9/13.30** (immutable, idempotent, non-negative ledger): already implemented and must not be bypassed — this feature is additive to the existing ledger, never a parallel one.
- **SRS §13.18** (feature flag gates all student wallet UI): `FeatureSettings::wallet_enabled` must gate the new "pay with wallet" checkout option, exactly as it already gates the wallet statement/dashboard UI.
- **Accepted interpretation (not directly stated in the SRS, flagged as an interpretation):** wallet payment is treated as a **third checkout method** alongside the existing gateway-provider flow, resolved at the same choke point (`BookingPaymentService::initiate()`/checkout), not a replacement for gateway payment and not a new parallel booking-confirmation pipeline. This mirrors the existing `refundToWallet()`/`refundViaProvider()` split (wallet-first as the default/simple path, gateway as the alternate path) already established in this exact service for refunds.

## 6. Current implementation baseline

| Layer | Existing component | Current responsibility | Reusable? | Gap |
|---|---|---|---|---|
| Model | `Wallet`, `WalletLedgerEntry` | Balance, ledger, currency | Yes, fully | — |
| Service | `WalletLedgerService::debit()` | Idempotent, locked, balance-checked debit | Yes, fully | Needs a new `WalletLedgerEntryType` producer call at checkout |
| Service | `WalletService::getOrCreateWallet()` / `getOrCreateWalletForExistingObligation()` | Resolve/create the student's wallet, currency policy | Yes, fully (use `getOrCreateWallet()` — NewInitiation intent, since paying is new activity) | — |
| Enum | `WalletLedgerEntryType::BookingPayment` | Already-reserved ledger entry type for exactly this | Yes | Zero current producers — the gap itself |
| Service | `BookingPaymentServiceInterface`/`BookingPaymentService` | `initiate()`, `markPaid()`, `checkoutPayload()`, provider-agnostic | Yes, `markPaid()` reusable as-is for finalization | No wallet branch exists in `initiate()`/`checkoutPayload()` |
| Contract | `PaymentProviderInterface`, `PaymentProviderRegistry`, `PaymentProviderResolver` | Gateway abstraction (Razorpay/Stripe/Fake) | Partially — wallet is NOT a gateway (no redirect/webhook), so it should NOT implement this interface; it needs its own smaller branch | New: a wallet-checkout decision point separate from gateway resolution |
| Service | `CurrencyEligibilityPolicy` (24M) | Locks/validates Currency row at payment-initiation boundary | Yes, fully | Wallet-payment currency must additionally match the wallet's own fixed currency (new check) |
| DB | `booking_payments` table, `provider` varchar(30) | One row per payment attempt | Yes — `provider = 'wallet'` fits the existing column without a type change | Possibly a nullable link column for traceability (see §10) |
| Settings | `FeatureSettings::wallet_enabled` | Gates existing wallet UI | Yes | Must additionally gate the new checkout option |
| UI | `BookingWizard.php` (Livewire), `booking-wizard.blade.php` | Checkout method selection (currently gateway-only) | Partially | Needs a new "Pay with wallet" option, balance display, insufficient-funds messaging |
| UI | `BookingHistory.php` | Student's booking list, likely includes a "pay now" action for a Pending reservation | Partially | Same new option needed here too (second entry point) |
| Tests | `WalletLedgerFoundationTest.php`, `PaymentGatewaySettingsAdminTest.php`, `CountryAwareProviderResolutionTest.php` | Prove existing ledger/provider correctness | Yes, as regression baselines | New dedicated test file needed |

**Requirement status: completely absent at the integration layer, but the domain model was already prepared for it** (reserved enum case, reusable idempotent debit, reusable finalization method) — this is the "implemented but disconnected" pattern the original audit repeatedly found elsewhere in the codebase, now confirmed for this gap too.

## 7. Missing behavior

No wallet-payment checkout path exists at any entry point. A student cannot choose to pay for a lesson from their wallet balance under any circumstance today, even though their wallet may already hold a positive balance from a referral reward or admin credit.

## 8. Authoritative enforcement boundary

**Proposed choke point:** a new method on `BookingPaymentServiceInterface`, e.g. `payWithWallet(Booking $booking): Booking`, implemented in `BookingPaymentService`, reusing `WalletLedgerService::debit()` + the *existing* `markPaid()`'s finalization logic internally (never duplicating booking-status-transition/meeting-trigger/activity-logging code).

| Entry point | Current path | Proposed authoritative service/policy | Bypass risk | Required change |
|---|---|---|---|---|
| Frontend booking wizard (Livewire) | `BookingWizard` → `checkoutPayload()` (gateway only) | Add a "Pay with wallet" option calling `BookingPaymentService::payWithWallet()` | None if the wizard only ever calls the service, never touches `WalletLedgerService` directly | New Livewire action + form option |
| Student's existing-booking "pay now" (`BookingHistory`) | Same gateway-only path | Same new service method | Same | New Livewire action |
| Filament admin (if any admin-initiated payment path exists) | Not found — admin does not pay on a student's behalf today | N/A | None — no admin entry point currently exists for this | No change needed |
| Console commands / queued jobs | None found that initiate a NEW payment (existing ones only reconcile/retry already-initiated gateway payments) | N/A | None | No change |
| API | None found (no public payment API) | N/A | None | No change |
| Recurring booking creation | `WizardBookingService::bookRecurring()` | Same `payWithWallet()` should be reusable per-occurrence | Must confirm each occurrence's own price/currency, not a single bulk debit, to preserve per-occurrence traceability | Design decision documented in the implementation plan |

Avoiding business-rule leakage: the wallet debit, currency match, feature-flag check, and idempotency key generation must all live in `BookingPaymentService`/a new focused method — never in the Livewire component, which should only call the service and render its result.

## 9. Precise business rules

| Rule | SRS support | Note |
|---|---|---|
| Eligible actor: the booking's own student only, matching `LessonReviewEligibilityPolicy`-style "no staff bypass" precedent | §13.13 (implicit — wallet is a personal balance) | Interpretation: no admin-pays-on-behalf-of-student path is introduced |
| Lifecycle prerequisite: student must satisfy the existing `VerifiedActiveStudentRule`/`StudentFinancialVerificationGate` — unchanged, already enforced upstream at booking creation | Phase 24R (unchanged contract) | Not weakened; wallet payment is a *payment method* inside an already-eligible booking, not a new eligibility bypass |
| Valid states: booking must be `Pending` + `payment_status = Pending` (mirrors `markPaid()`'s existing precondition) | §14.17 (booking confirmed only after payment success) | Reuse existing precondition check, don't re-derive it |
| Wallet currency must exactly match the booking's resolved currency; no cross-currency debit | §13.7 (single-currency wallet) | Explicit, not an interpretation |
| Sufficient available balance required; insufficient balance is a clean rejection, never a partial debit | §13.30 (non-negative) | `WalletLedgerService::debit()` already throws `InsufficientBalanceException` — reuse, don't reimplement |
| Idempotency: a retried/duplicate wallet-payment request for the same booking must not double-debit | §13.9/13.33 | Reuse `debit()`'s `idempotencyKey` parameter, keyed on the booking's payment attempt ID, mirroring `booking_payments.idempotency_key`'s existing convention |
| Concurrency/transaction boundary: debit + `markPaid()`'s booking-status transition must commit atomically, inside the SAME transaction the rest of `BookingPaymentService` already uses `DB::transaction()` for | §27.11/27.12 (idempotent, concurrency-safe workflows — accepted Phase 24 pattern) | Not stated per-clause in the SRS but is the established codebase convention (Phase 24M, 24C) — must be preserved |
| Failure behavior: on insufficient balance, no booking-state change, no ledger entry, a clear user-facing message (never a raw exception) | Consistent with `CancellationRefundPolicy`'s error-handling convention | Interpretation, following existing UX pattern |
| Audit: every wallet payment must appear in the ledger (already guaranteed by `debit()`) and in `booking_payments` (existing pattern) — no second audit mechanism needed | §13.9, §24.15 | — |
| Notification: reuse the EXISTING `PaymentSucceeded`/`BookingConfirmed` notification pipeline `markPaid()` already triggers — no new notification class | §17.5 (accepted Phase 17S contract) | Explicit reuse instruction, not a new notification |
| Privacy: no new field on any DTO should expose another user's wallet balance; this is entirely self-service | §2.19 (privacy) | Interpretation, consistent with existing wallet-statement authorization (`WalletPolicy`) |
| Historical treatment: once paid via wallet, the payment record and ledger entry are immutable, exactly like every other payment method | §13.3 (immutable ledger) | Explicit |
| Feature-toggle: `FeatureSettings::wallet_enabled` must gate the new checkout option's visibility, matching every other wallet UI surface | §13.18 (accepted deviation note: crediting side-effects bypass the flag, but this is a NEW debit-initiating UI surface, so it must NOT bypass it) | Explicit, and a deliberate correction of the flag-bypass deviation already flagged for OTHER wallet paths — this new path must not repeat that mistake |
| Admin/system exemptions: none apply — this is exclusively a student self-service action | — | Interpretation |
| Retry/duplicate handling: a second "Pay with wallet" click before the first request completes must resolve to the same idempotent outcome, never a second debit | §27.11/27.24 | Reuse existing idempotency-key convention |

**No rule above required silently resolving a material SRS ambiguity** — every rule either cites explicit SRS text or is labeled as an interpretation following an already-accepted codebase convention (never a novel one).

## 10. Proposed database and migration design

| Table | Change | Reason | Constraint/index | Backfill | Rollback |
|---|---|---|---|---|---|
| `booking_payments` | **Likely none required** — `provider` is already `varchar(30)`, accepts `'wallet'` without a schema change | Existing column is not a DB-level enum | n/a | n/a | n/a |
| `booking_payments` | *Optional, additive:* nullable `wallet_ledger_entry_id` (unsignedBigInteger, FK → `wallet_ledger_entries.id`, `restrictOnDelete`) | Direct traceability from payment → ledger entry (the reverse direction — ledger entry → payment — already exists via `source_type`/`source_id`, so this may be judged unnecessary; a final decision belongs in the implementation phase, not this planning phase) | index on the FK if added | none (nullable, new rows only) | drop column/index/FK — fully reversible |
| `wallet_ledger_entries` | None | `WalletLedgerEntryType::BookingPayment` already exists | — | — | — |

**No new table is required.** This is a low-migration-risk requirement — if the optional traceability column is judged unnecessary during implementation (since `source_type`/`source_id` on the ledger entry already covers it), **zero migrations** would be needed at all. `migrate:fresh` is never used, per project convention; any migration added would be purely additive.

## 11. Lifecycle, authorization, privacy, and audit boundaries

- **StudentStatus:** unchanged — booking creation already enforces `VerifiedActiveStudentRule`/`StudentLifecycleService` upstream; wallet payment does not re-derive or weaken this.
- **Instructor status:** not applicable — this is a student-side payment action.
- **Super Admin invariant:** unaffected.
- **Policies/permissions:** the booking's own student only; reuse the same ownership check already used for `BookingPaymentService::initiate()`/gateway checkout (no new policy class needed, verify at implementation time whether an explicit `Gate`/policy call exists there to mirror, or whether ownership is implicit in the booking lookup).
- **Missing-role resilience (Phase 24T):** not implicated — no role-filtered query is introduced by this feature.
- **Private vs. public data:** wallet balance remains visible only to its owner (existing `WalletPolicy`); no new field exposes cross-user data.
- **Audit/redaction (Phase 24S/S.1 conventions):** this is a domain-event audit (ledger + `booking_activities`), not a Settings mutation — Phase 24S's `LogsSettingsUpdates` machinery is not applicable here; the existing `AuditTrailService`-based booking/wallet audit conventions apply instead.
- **Read-only reporting:** existing wallet/payment reports (`WalletFinancialReportRepository`, `RefundSummaryData`) should pick up wallet-paid bookings automatically once `provider = 'wallet'` rows exist — verify at implementation time that no report explicitly filters `provider IN ('razorpay','stripe')` in a way that would silently exclude wallet payments (a concrete regression risk to check, not yet confirmed either way).
- **Admin/system exemptions:** none required for this feature.

No previously accepted lifecycle or authorization control is weakened by this design.

## 12. Integration impact

| Affected domain | Impact | Required change | Regression risk | Required focused test |
|---|---|---|---|---|
| Bookings (recurring occurrences) | Each occurrence needs its own wallet debit, not one bulk debit | `payWithWallet()` called once per occurrence's own `BookingPayment` row, mirroring how gateway payment already works per-occurrence | Low — no existing recurring-payment code changes | Recurring-booking wallet-payment test (new) |
| Lessons/outcomes | None — unaffected, lessons only care about `payment_status`, not which method produced it | none | None | Existing lesson-outcome tests unaffected (no change needed) |
| Payments/provider routing | New third method alongside gateway | `initiate()`/`checkoutPayload()` gain a wallet branch, OR a dedicated new method bypasses them entirely (recommended, since wallet needs no redirect/webhook lifecycle) | Low if implemented as a sibling method rather than modifying the gateway branch | `PaymentGatewaySettingsAdminTest.php`, `CountryAwareProviderResolutionTest.php` re-run to prove gateway paths are untouched |
| Wallet ledger and refunds | New debit producer; refund-to-wallet already exists as the credit-side mirror | None to `refundToWallet()` itself — a wallet-paid booking's cancellation should refund back to wallet exactly like a gateway-paid one already does | **Must verify**: does `CancellationRefundPolicy`/`SyncPaymentOnCancellation` already handle `provider='wallet'` correctly, or does it assume a gateway payment exists? This is evidence-based, not yet confirmed — flagged as a required verification step in implementation, not assumed safe | `WalletLedgerFoundationTest.php`, `CancellationRefundExecutionTest.php` (existing files, extended) |
| Instructor earnings/settlement | None — earnings are computed from the lesson/booking, never from the payment method | none | None (evidence: `InstructorCompensationResolver` explicitly never reads payment method per the original audit's SRS-15-1 finding) | none new required |
| Reviews/quality alerts | None | none | None | none new required |
| Referrals | None | none | None | none new required |
| Homework/learning plans | None | none | None | none new required |
| Notifications | Reuses existing `PaymentSucceeded`/`BookingConfirmed` — no new notification class | none | Low | Confirm via existing `SendBookingNotifications` test that a wallet-sourced `markPaid()` call still dispatches correctly |
| Reports/exports | Wallet-paid bookings must appear correctly in payment/wallet reports | Verify no report hard-filters by gateway `provider` value (see above) | Medium — genuinely unverified, must be checked in implementation, not assumed | `WalletFinancialReportRepository` test extension if a filtering gap is found |
| Feature settings | `wallet_enabled` must gate the new UI | Wire the flag check into the new Livewire option | Low | New feature-toggle test |
| Operational monitoring | None | none | None | none new required |

"No impact" rows above (lessons, earnings, reviews, referrals, homework, monitoring) are evidence-based, each citing the specific reason no code path reads the payment method for that domain's own logic.

## 13. Focused test plan

New/modified test files (no full suite, no directory sweep):

1. **New:** `tests/Feature/Booking/WalletLessonPaymentTest.php` — happy path (sufficient balance → booking confirmed, wallet debited exactly once, ledger entry type `BookingPayment`), insufficient balance (clean rejection, no state change, no ledger entry), wrong-currency wallet (rejected), feature-flag off (option unavailable/rejected), idempotent duplicate submission (single debit), authorization (only the booking's own student), transaction rollback (forced failure mid-debit leaves booking `Pending` and wallet balance unchanged).
2. **Modified:** `tests/Feature/Wallet/WalletLedgerFoundationTest.php` — add a case proving `BookingPayment` entries compose correctly with existing balance/statement assertions.
3. **Modified:** `tests/Feature/Booking/CancellationRefundExecutionTest.php` — add a case: a wallet-paid booking cancelled before the cutoff refunds correctly back to wallet (verifies the integration-impact concern in §12 empirically rather than assuming it).
4. **New (concurrency):** `tests/Feature/Booking/Concurrency/WalletLessonPaymentConcurrencyTest.php` — two concurrent wallet-payment attempts against the same booking/balance resolve to exactly one successful debit (mirrors `StripePaymentConcurrencyTest.php`'s established cross-process pattern).
5. **Regression, run unmodified:** `tests/Feature/Booking/PaymentGatewaySettingsAdminTest.php`, `tests/Feature/Booking/CountryAwareProviderResolutionTest.php` (prove gateway paths untouched), `tests/Feature/Settings/SettingsAuditArchitectureTest.php` (only if any Settings page is touched — not expected).

No architecture test is needed unless a new settings page is added (not currently planned). Pint on all changed PHP files. No `npm build` (no frontend asset pipeline changes expected — Livewire/Blade only). Migration status/schema inspection required only if the optional traceability column is actually added.

## 14. Dependency-ordered implementation slices

| Slice | Outcome | Files/components | Tests | Completion condition |
|---|---|---|---|---|
| 1 | Domain contract | Add `payWithWallet(Booking $booking): Booking` to `BookingPaymentServiceInterface` + implementation skeleton in `BookingPaymentService` | none yet | Interface compiles, method throws `NotImplementedException`-style placeholder |
| 2 | Migration (if needed) | Optional `wallet_ledger_entry_id` column decision finalized and applied, OR explicitly declared unnecessary | new migration file, if any | Migration status inspection | `php artisan migrate:status` clean |
| 3 | Central service enforcement | Full `payWithWallet()`: currency match, feature-flag check, `WalletLedgerService::debit()` call, reuse of `markPaid()`'s finalization internals, one `DB::transaction()` | `BookingPaymentService.php` | `WalletLessonPaymentTest.php` happy path + insufficient balance + wrong currency + feature-flag-off | All pass |
| 4 | Concurrency/idempotency | Idempotency key wiring, transaction/lock verification | same file | `WalletLessonPaymentConcurrencyTest.php` | Cross-process test proves exactly one debit |
| 5 | Integration with existing entry points | `BookingWizard.php` + `BookingHistory.php` gain a "Pay with wallet" option | Livewire components + Blade partials | Existing checkout tests + new UI-level assertions | Option renders only when `wallet_enabled` + sufficient balance |
| 6 | Cancellation/refund integration | Verify (and fix if needed) that `SyncPaymentOnCancellation`/`CancellationRefundPolicy` correctly handle a `provider='wallet'` payment | `CancellationRefundPolicy.php` / `SyncPaymentOnCancellation.php`, only if a gap is found | `CancellationRefundExecutionTest.php` extension | Wallet-paid cancellation refunds correctly |
| 7 | Reporting regression closure | Confirm/fix any provider-value hard-filter in wallet/payment reports | `WalletFinancialReportRepository` or equivalent, only if a gap is found | targeted report test | Wallet payments appear in existing reports |
| 8 | Focused regression closure | Full named-file batch re-run | — | Steps 1–7's files + `PaymentGatewaySettingsAdminTest.php`, `CountryAwareProviderResolutionTest.php`, `WalletLedgerFoundationTest.php` | All green, reconciled totals |

Slices 6–7 are conditional — they execute only if the investigation in that slice actually finds a gap, per this phase's own "no impact must be evidence-based" instruction; if none is found, the slice closes as "verified, no change needed."

## 15. Risks, assumptions, and blockers

| Risk | Classification |
|---|---|
| Whether `CancellationRefundPolicy`/`SyncPaymentOnCancellation` already handle a wallet-sourced payment correctly, or assume a gateway payment | **Manageable during implementation** — must be verified empirically in Slice 6, not assumed either way |
| Whether any existing wallet/payment report hard-filters by gateway provider value | **Manageable during implementation** — Slice 7 |
| Recurring-booking per-occurrence debit design (one debit per occurrence vs. a bulk debit) | **Manageable during implementation** — this plan recommends per-occurrence (mirrors existing gateway behavior); no blocking ambiguity, just an implementation detail to confirm against `WizardBookingService::bookRecurring()`'s exact occurrence-creation loop |
| Whether the optional `wallet_ledger_entry_id` traceability column is actually needed given `source_type`/`source_id` already exists on the ledger entry | **Manageable during implementation** — non-blocking, additive either way |
| Concurrency risk | **Manageable** — fully addressed by reusing `WalletLedgerService::debit()`'s existing row-lock + idempotency-key pattern, already proven safe elsewhere |
| Financial risk | **Manageable** — no new money-movement primitive is introduced; this composes two already-hardened primitives (`debit()`, `markPaid()`) |
| Authorization/privacy risk | **Manageable** — self-service only, no new cross-user data exposure |
| Notification/provider dependency | **None** — explicitly reuses existing notification classes; no external call of any kind |
| Deployment/configuration dependency | **None** |
| Backward-compatibility risk | **None** — purely additive; no existing gateway-payment behavior changes |
| Interaction with Phase 17–24 accepted behavior | **None found** that isn't already covered by Slices 6–7's verification |
| Blocking product decision | **None** — GAP-002 has no open ambiguity, unlike GAP-001/GAP-040 |

No blocking risk was found. The two "manageable" verification items (Slices 6–7) are investigation tasks with a defined completion condition, not open product questions.

## 16. Recommended phase identifier and title

**Phase 25B — Wallet-as-Lesson-Payment Implementation (SRS §13.13 / GAP-002)**

## 17. One optimized implementation prompt for the selected requirement

*(Provided below for future use — not executed in Phase 25A.)*

> **Phase 25B — Wallet-as-Lesson-Payment Implementation**
>
> **Goal:** Implement SRS §13.13 (GAP-002): a student with a sufficient wallet balance may pay for a lesson booking using that balance as an alternative to gateway checkout.
>
> **Mandatory constraints:** docs/SRS.md is authoritative. Reuse `WalletLedgerService::debit()`, `WalletLedgerEntryType::BookingPayment` (already reserved), and `BookingPaymentService::markPaid()`'s existing finalization logic — never duplicate booking-status-transition, meeting-trigger, or activity-logging code. Do not touch gateway (Razorpay/Stripe) payment paths except to prove they remain unaffected. Do not weaken `VerifiedActiveStudentRule`/`StudentLifecycleService`/`FeatureSettings::wallet_enabled` gating. Do not use real payment/wallet-recharge providers — this feature never calls an external provider by design. Preserve Phase 24C's `CancellationRefundPolicy`/wallet-refund contract. Run only focused, named tests — no full suite, no directory sweep.
>
> **Step 1** — Add `payWithWallet(Booking $booking): Booking` to `BookingPaymentServiceInterface` and implement it in `BookingPaymentService`: verify booking is `Pending`/`payment_status=Pending` and owned by the acting student; resolve the student's wallet via `WalletService::getOrCreateWallet()`; assert wallet currency matches the booking's resolved currency; assert `FeatureSettings::wallet_enabled`; call `WalletLedgerService::debit()` with `WalletLedgerEntryType::BookingPayment`, an idempotency key derived from the `BookingPayment` row, `sourceType='booking_payment'`/`sourceId=<payment id>`; on success, reuse `markPaid()`'s internal finalization (confirm booking, trigger meeting creation, log activity, dispatch existing notifications) inside the same `DB::transaction()` as the debit — a debit must never commit without the booking finalizing, and vice versa. On `InsufficientBalanceException`, roll back cleanly and surface a generic, non-leaking message.
>
> **Step 2** — Determine (do not assume) whether a new nullable `wallet_ledger_entry_id` column on `booking_payments` is needed for traceability, given `source_type`/`source_id` already exists on the ledger entry pointing back to the payment. Add an additive, reversible migration only if genuinely needed.
>
> **Step 3** — Wire a "Pay with wallet" option into `BookingWizard.php` and `BookingHistory.php`'s existing checkout/pay-now UI, gated on `wallet_enabled` and the student's current available balance (fetched read-only, never assumed sufficient client-side — the service re-checks authoritatively).
>
> **Step 4** — Investigate and, only if a genuine gap is found, fix: (a) whether `CancellationRefundPolicy`/`SyncPaymentOnCancellation` correctly refund a wallet-paid booking back to wallet; (b) whether any existing wallet/payment report hard-filters by gateway provider value in a way that would exclude `provider='wallet'` rows.
>
> **Step 5** — Required focused tests: new `WalletLessonPaymentTest.php` (happy path, insufficient balance, wrong currency, feature-flag-off, idempotent retry, authorization, transaction rollback), new `WalletLessonPaymentConcurrencyTest.php` (cross-process, exactly-one-debit), extensions to `WalletLedgerFoundationTest.php` and `CancellationRefundExecutionTest.php` only if Step 4 finds a gap, and unmodified re-runs of `PaymentGatewaySettingsAdminTest.php` + `CountryAwareProviderResolutionTest.php` proving gateway paths are untouched.
>
> **Step 6** — Final report: exact SRS interpretation, files changed, migration decision and reasoning, focused command/results reconciled exactly, confirmation gateway paths are unmodified, confirmation no external provider was called, confirmation `wallet_enabled`/lifecycle gating is intact. Stop after Phase 25B.

---

**Confirmations (Phase 25A):**
- No files were modified during this phase (this report is being saved afterward, at the user's explicit request, as a separate action).
- No tests were added or changed.
- No full suite or directory sweep was run — all verification was source reads and targeted `grep`/`find` against current code.
- No external provider was contacted.
- Only one implementation requirement was selected (GAP-002).
- No unsupported scope was introduced — every business rule in §9 cites either explicit SRS text or an existing, already-accepted codebase convention, with interpretations explicitly labeled as such.
