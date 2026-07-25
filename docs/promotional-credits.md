# Promotional Wallet Credit Campaigns (Phase 33, GAP-041 remainder)

SRS §13.20, §16.17-§16.19. Admin-managed promotional wallet credits — either issued from a campaign (fixed amount, per-student limit, optional budget cap) or as an ad-hoc manual goodwill credit — always through the existing immutable wallet ledger. Referral reward notifications (the other half of GAP-041) were already complete before this phase and were not touched.

## Scope

Version 1 promotional credits behave as a standard wallet credit (SRS §13.20/§16.19) — no expiry, restricted spending, category-specific usage, or separate balance. Explicitly out of scope: referral reward duplication, discount coupons, promo codes at checkout, automatic mass issuance, wallet debit/reversal, payment-provider calls, external marketing integrations.

## Reuse (requirement: extend, don't duplicate)

- **Ledger**: every credit goes through the existing `WalletLedgerService::credit()` with the existing `WalletLedgerEntryType::PromotionalCredit` case (already present, previously unused — SRS-16-16's exact "enum case exists, never referenced anywhere else" finding). `PromotionalCreditService` never touches a `*_minor` column or writes a ledger row itself.
- **Wallet resolution**: `WalletService::getOrCreateWallet($student, null, $student)` — the student's own natural default-currency wallet, actor = the student themselves (mirroring `ReferralRewardService::creditReward()`'s identical `getOrCreateWalletForExistingObligation($referrer, null, $referrer)` pattern). This deliberately avoids requiring the admin to separately hold the broad, deliberately-ungranted `Manage:Wallet` permission just to issue a credit — `IssuePromotionalCredit` is its own standalone grant.
- **Campaign shape**: `PromotionalCreditCampaign` and `PromotionalCreditService`'s campaign-lifecycle methods mirror `ReferralCampaign`/`ReferralCampaignService` almost exactly (same status enum shape, same rules-frozen-after-first-use rule, same transition-with-mandatory-reason pattern) — a deliberate sibling, not a new pattern.
- **Notifications**: reuses the existing `App\Listeners\Wallet\SendWalletNotifications` listener (one new method added, not a new listener class) and the existing `NotificationIdempotencyGuard` — no parallel notification pipeline.
- **Compliance/audit**: `AuditTrailService` under `log_name = 'promotional_credits'` — no new audit mechanism.
- **Reporting**: `WalletFinancialReportRepository::movements()` already includes promotional-credit rows for free (it groups by `entry_type` across all of `wallet_ledger_entries`, unfiltered) — verified by test, zero code change needed there. Two new bounded methods were added to that *same* repository (`promotionalCreditCampaignSummary()`, `manualPromotionalCreditTotal()`) rather than a new reporting module.

## Schema

```
promotional_credit_campaigns (name unique, status, starts_at, ends_at, amount_minor, currency_id/currency_code, per_student_limit, total_budget_minor nullable, created_by/updated_by)
promotional_credit_issuances (campaign_id nullable, student_id, wallet_ledger_entry_id, amount_minor, currency_code, issuance_type, issued_by, reason, idempotency_key unique, issued_at)
```

`PromotionalCreditIssuance` is fully immutable (`PreventsHardDeletion` + `PreventsUpdates` — no legitimate later mutation exists, unlike `Message.read_at`). `PromotionalCreditCampaign` uses `PreventsHardDeletion` only (status/rules legitimately change until frozen by first issuance, mirroring `ReferralCampaign`).

**Interpretation flagged, not silently decided**: SRS §20.17 marks "Campaign budget cap" as `future`, but this phase's own explicit requirement asks for `total_budget_minor` as a V1 field. Building it doesn't violate any V1 restriction (it's optional/nullable, additive, reversible) — implemented as requested, noted here for transparency. "Status" and "enabled/disabled state" (both listed in requirement #2) are the same single `PromotionalCreditCampaignStatus` column — a separate boolean would only duplicate it.

## Authoritative service boundary

`App\PromotionalCredits\Services\PromotionalCreditService` is the only writer of both tables. Campaign management (`createCampaign`/`updateCampaign`/`activateCampaign`/`pauseCampaign`/`resumeCampaign`/`completeCampaign`/`archiveCampaign`) and issuance (`issueCampaignCredit`/`issueManualCredit`, sharing one private `issue()` implementation) live in the same class per the phase's explicit requirement (unlike Referral, which splits campaign vs. reward into two services).

## Eligibility and limits (requirement #5, all enforced in `issue()`)

1. Active, verified student (`User::STATUS_ACTIVE` + `hasVerifiedEmail()` + `StudentStatus::Active`).
2. Wallet/campaign (or wallet/manual-target) currency match — rejected outright, never converted, never a second wallet minted to force it through.
3. Active, usable currency — reused via `WalletService`'s own `CurrencyEligibilityPolicy` resolution.
4. `FeatureSettings::promotional_credit_enabled` (global switch, the one-switch-per-module convention).
5. `IssuePromotionalCredit` permission.
6. Positive amount.
7. Campaign: `Active` status, current instant within `[starts_at, ends_at)`, currency match.
8. Campaign: budget cap (sum of prior issuances + new amount ≤ `total_budget_minor`, when set).
9. Campaign: per-student limit (count of prior issuances for that campaign+student < `per_student_limit`).
10. Database-backed duplicate prevention: unique `idempotency_key` on both `promotional_credit_issuances` and the `wallet_ledger_entries` row it produced.

Concurrency: campaign-linked issuance locks the campaign row (`lockForUpdate()`) for the duration of the budget/per-student-limit check + insert, serializing concurrent attempts against the same campaign (mirrors `ReferralCampaignService`'s overlap-check locking and `TransitionSupportCaseStatusAction`'s row-lock pattern) — verified by a concurrent-attempt test.

## Admin surface

Two Filament resources under the existing "Referral" navigation group:
- **Promotional Campaigns** — list/create/edit/view, lifecycle actions (activate/pause/resume/complete/archive, each with a mandatory reason), a read-only issuance-history relation manager, and a usage-summary infolist panel (issued count/amount, budget remaining).
- **Promotional Credit Issuances** — bounded, filterable (student/campaign/type/date) read-only history, plus the single "Issue Credit" action (student + optional campaign + reason; amount/currency fields appear only when no campaign is selected). No create/edit/delete route exists for issuances at all.

## Notifications and audit

`SendWalletNotifications::handlePromotionalCreditIssued()` sends `PromotionalCreditIssuedNotification` (mail + database) to the student only — amount, currency, and campaign name if applicable; never the admin's identity or the internal reason. Queued, after-commit (`PromotionalCreditIssued implements ShouldDispatchAfterCommit`), idempotent via the shared `NotificationIdempotencyGuard`. Every campaign lifecycle action and every issuance is audit-logged under `log_name = 'promotional_credits'`.
