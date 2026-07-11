# Phase 14.3 — Compensation Pre-Enable Operational Hardening

> **⚠️ Superseded (Phase 14.4).** Content remains accurate; consolidated into the canonical document. The canonical, current
> reference is [docs/financial-domain-architecture.md](../financial-domain-architecture.md);
> this file remains as a historical phase record only.

Targeted hardening on top of the Phase 14.2 agreement model — no
compensation redesign. Three deliverables: the canonical
agreement-resolution timestamp, an exception/recovery pipeline for
blocked lessons, and a server-side activation preflight with a separate
periodic-compensation rollout gate.

## 1. Canonical resolution timestamp: scheduled lesson start

The agreement (and override) that pays for a lesson is the one in force
at the lesson's **scheduled start** (`lessons.starts_at`) — never the
completion time, auto-completion time, queue processing time, or
earning-creation time. A lesson scheduled 31 July but marked complete
1 August pays the July rate.

Implementation: `InstructorCompensationResolver` resolves against the
**approved agreement lineage** — `active`, `ended` (historical windows
stay payable for service delivered inside them), and due `scheduled`
rows — matching the window covering the scheduled instant; drafts and
cancellations never pay. Most recent window start wins.

Every hourly earning snapshot now persists:
`lesson_scheduled_start_at`, `agreement_effective_timestamp` (the
resolution instant), `agreement_id`, `agreement_version`, `override_id`
(when applied), `pay_basis`, `rate_minor`, `eligible_minutes`,
`rounding_policy`, `calculated_amount_minor`, `currency_id`,
`currency_code` — and still nothing student-priced.

## 2. Exception queue & recovery

Blocks are categorized (`CompensationExceptionCategory`):

| Category | Cause | Retryable |
|---|---|---|
| `missing_agreement` | no approved window covers the scheduled start | yes — after (backdated) configuration |
| `invalid_agreement` | non-positive agreement amount | yes |
| `invalid_currency` | agreement currency inactive | yes |
| `unsupported_duration` | eligible minutes outside 1–480 | yes |
| `transient_failure` | unexpected infrastructure error during creation | yes |
| `permanently_ineligible` | lesson left the eligible state after being queued | **never** |

One open row per lesson in `instructor_compensation_exceptions`
(unique `lesson_id`), updated in place per attempt (category, safe
reason, attempt_count, first/last timestamps), resolved — never
deleted — when the earning exists. `CompensationExceptionService` is
the only writer; recording also writes the audit event
(missing-agreement keeps `earning_blocked_no_agreement`, which still
raises the admin notification).

**Recovery**: `instructor-earnings:retry-blocked-lessons` (scheduled
hourly, also a per-row "Retry Now" action on the admin page) re-invokes
the normal idempotent `createFromLesson()`. Because resolution is
pinned to the scheduled start, a retry running later **cannot** pick up
the currently-active agreement — test-proven: a newer agreement does
not unblock a lesson scheduled before its window; only the correctly
backdated agreement does. The kill switch still gates every attempt;
duplicates are impossible (lesson-unique earnings + idempotent hit).

**Admin surface**: Filament → Earnings → *Compensation Exceptions* —
booking/lesson reference, instructor, scheduled time, category badge,
safe reason, retry eligibility, attempt count, first/last/resolved
timestamps; filters; open-count navigation badge. Read-only apart from
the permission-gated retry (`Configure:InstructorCompensationAgreement`).

## 3. Earnings activation preflight

`CompensationActivationPreflight` runs server-side whenever an admin
flips `earnings_enabled` on (the settings page save path — the only
enabling surface). Activation is refused, naming the exact subjects,
while any of these fail:

1. an active payable instructor (active account, instructor role,
   approved/active profile) lacks a live active agreement window;
2. active/scheduled agreement windows overlap;
3. an active agreement uses an inactive currency;
4. an active agreement has a non-positive amount;
5. upcoming scheduled lessons have unsupported durations (1–480 min);
6. unresolved compensation exceptions exist;
7. daily/weekly/monthly agreements exist while
   `periodic_compensation_enabled` is off.

## 4. Periodic compensation rollout gate

New setting `periodic_compensation_enabled = false` (settings
migration; toggle beside the earnings switch). Daily/weekly/monthly
bases pay fixed contractual amounts per period **regardless of taught
lessons** — that is a salary/retainer semantic whose attendance,
workload, leave, suspension, and partial-period rules are not yet
defined. While the gate is off: periodic agreements can be drafted
(preparation) but not scheduled/activated/promoted (service +
`syncLifecycle` gates), periodic accrual creates nothing (audited
skip), replacement to a periodic basis is refused, and the preflight
blocks earnings activation if any periodic agreement slipped through.
Hourly agreements are untouched. Historical periodic rows stay
readable.

## 5. Financial switches (all remain OFF)

`earnings_enabled = false` · `withdrawals_enabled = false` ·
`periodic_compensation_enabled = false`. Nothing in this phase enables
any of them. Recommended go-live sequence: seed permissions →
configure **hourly** agreements → clear the exceptions queue → let the
preflight pass → enable earnings for a controlled test instructor's
lesson → verify lesson → hold → release → available-withdrawal before
platform-wide enablement. Phase 16 payout execution stays blocked.
