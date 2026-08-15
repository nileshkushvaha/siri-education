# SIRI Education — Main Administrative Dashboard Audit

**Audit type:** Read-only. No code, schema, config, docs or tests were modified while producing these findings.
**Repository:** `/Users/nileshkushvaha/Sites/enterprise-app` @ branch `master` (`c27775f`)
**Date:** 2026-08-01
**Purpose:** Determine what should appear on the redesigned main administrative dashboard (`/admin`), using code evidence only.

**Scope note:** This is a dated snapshot, like `docs/SRS_Compliance_Audit.md`. Cross-check any specific finding against current code before treating it as still true.

**Status:** The redesign this audit recommended has since been implemented (Dashboard command centre, `app/Dashboard/**`). Read [§0](#0-implementation-time-corrections-to-this-audit) first — five findings below were refined or corrected while building against them.

---

## 0. Implementation-time corrections to this audit

Discovered while implementing the recommendations. Each supersedes the corresponding claim later in this document.

| # | Audit said | Actually true | Where it matters |
|---|---|---|---|
| 1 | Booking/lesson operations figures are gated by `ViewOperationalReports` (the report definition's permission). | `BookingLessonMeetingOperationsReportService::bookingSummary()` and `::lessonOutcomeSummary()` **additionally require `ViewBookingLessonReports`** and throw `AuthorizationException` without it (`authorizeBookingLesson()`). The definition permission alone is not sufficient. | Any caller of those two methods. Implemented as `DashboardPermissions::canViewOperationsSummaries()`; the weaker `canViewOperations()` still gates the repository-level current-state metrics (`disputed_lessons`, `unfinalized_past_due_lessons`), whose registry `requiredPermission` genuinely is `ViewOperationalReports` alone. |
| 2 | The Conversations drill-down requires `ViewAny:Conversation`. | That permission **does not exist**. `App\Policies\ConversationPolicy::viewAny()` checks **`ViewAny:Messaging`**. | §8 drill-down map, "Pending message reports" row. |
| 3 | Instructor-onboarding drill-down should be `?filters[instructor_status][value]=submitted`. | That filter is single-valued and would match **one of the four** statuses `InstructorStatus::needsReview()` covers, undercounting against the sidebar badge. `ListInstructorOnboarding` already defines a **`needs_review` tab** backed by the same `pendingReviewQuery()`, and `ListRecords` binds `activeTab` to `?tab=`. The correct link is **`?tab=needs_review`**. | §8 drill-down map, "Instructor applications pending" row. |
| 4 | `ViewInstructorCompensationReports` is deliberately independent of `ViewFinanceReports`. | Still correct in the direction that matters — finance never *implies* compensation. But `ReportCategory::EarningsSettlements::requiredPermission()` is `ViewFinanceReports`, and `ReportAccessContext::canView()` checks category **and** definition, so opening the Earnings & Settlements report needs **both**. | §4 and §6 permission columns. |
| 5 | `operational_alerts` dedup is carried by `active_fingerprint`. | There is also a non-nullable **`fingerprint`** column. Rows must be written through `OperationalAlertService::createOrMerge()`, not inserted directly — as the service's docblock already required. | Anything seeding or writing alerts, including tests. |

Two further notes that were correct but proved load-bearing in practice:

- **`ReportFilters::toSafeArray()` / `fromSafeArray()`** already existed and are explicitly designed for query-string round-tripping. The report-page URL bindings reuse that key vocabulary (`ReportFilterKey` values) rather than inventing a second one.
- **`ReportingPeriod::custom()` throws** on an out-of-range or inverted pair. Correct for a programmatic caller, but once period state is URL-bound a hand-edited URL could 500 a report page. `App\Reporting\Support\ReportPeriodResolver` was added as the single place that converts those exceptions into a safe fallback; validation itself stays in the value object.

---

## Table of contents

0. [Implementation-time corrections to this audit](#0-implementation-time-corrections-to-this-audit)
1. [Executive summary](#1-executive-summary)
2. [Evidence and confidence](#2-evidence-and-confidence)
3. [Module inventory](#3-module-inventory)
4. [Role and scope matrix](#4-role-and-scope-matrix)
5. [Report inventory](#5-report-inventory)
6. [Dashboard-ready metric inventory](#6-dashboard-ready-metric-inventory)
7. [Recommended main-dashboard sections](#7-recommended-main-dashboard-sections)
8. [Drill-down map](#8-drill-down-map)
9. [Chart recommendations](#9-chart-recommendations)
10. [Attention and alert rules](#10-attention-and-alert-rules)
11. [Useful links and quick actions](#11-useful-links-and-quick-actions)
12. [Content to remove or relocate](#12-content-to-remove-or-relocate)
13. [Recommended dashboard hierarchy](#13-recommended-dashboard-hierarchy)
14. [Data and implementation gaps](#14-data-and-implementation-gaps)
15. [Final prioritized recommendation](#15-final-prioritized-recommendation)
16. [Information needed before generating the final dashboard prompt](#information-needed-before-generating-the-final-dashboard-prompt)

---

## 1. Executive summary

**What this application actually is.** SIRI Education is a **global one-to-one online tutoring marketplace**, not a school/campus ERP. There is no campus, branch, academic session, academic year, or tenant concept anywhere in the codebase (verified by exhaustive grep over `app/` and `database/migrations/` — zero hits for `campus`, `branch_id`, `academic_session`, `academic_year`, `tenant_id`, `school_id`). Its unit of work is a **booking → lesson → outcome → money movement** chain between an independent instructor and a self-registering student, mediated by a platform that sets prices, holds a wallet, and pays instructors.

**What the platform already has that the current dashboard ignores completely.** The repository contains a mature, purpose-built reporting foundation — `app/Reporting/` — with:

- **20 registered report definitions** (`app/Reporting/Registry/ReportRegistry.php`), 19 available with real routes, 1 (`meeting_reliability`) formally and deliberately marked unavailable.
- **76 registered metric definitions** (`app/Reporting/Registry/MetricRegistry.php`), each carrying an exact calculation, included/excluded statuses, timestamp field, timezone behaviour, permission, supported dimensions, zero-denominator policy, and a named `calculationOwner`.
- **14 dedicated Filament report pages** with permission-scoped composition and 7 CSV exports.
- **15 `getNavigationBadge()` exception counters** already computed on every admin page load.
- A **durable operational-alert system** (`app/Alerts/`) with deduplication, fingerprinting, severity, acknowledge/resolve lifecycle, and audit trail.

**What the current dashboard actually contains.** `app/Filament/Pages/Dashboard.php:38-44` registers five widgets: `QuickActionsWidget`, `StatsOverviewWidget`, `RecentUsersWidget`, `RecentLoginsWidget`, `RecentAuditTrailWidget`. Every single figure on it is an **identity/security-system metric** — total users, active users, role count, today's logins, recent registrations, recent logins, recent audit rows. **Not one business metric of the marketplace appears on it.** An administrator cannot learn from this dashboard whether lessons happened today, whether any instructor no-showed, whether payouts are stuck, whether meetings failed to create, or whether money moved.

**What the redesigned main dashboard should help administrators accomplish.** Three jobs, in priority order:

1. **"Is anything broken or waiting on me right now?"** — operational alerts, unfinalized past-due lessons, missing meeting links, reconciliation issues, withdrawal approvals, instructor applications, disputes, critical support cases. All of these already have authoritative queries and real destinations.
2. **"Is the marketplace healthy this period?"** — bookings, lessons completed, no-show/technical-issue rate, demo→paid conversion, student engagement, instructor supply vs. demand. All available live from existing report services.
3. **"Where do I go next?"** — a permission-aware launchpad into the 19 available reports and the 57 admin resources, with pre-applied filters where the destination supports them.

The dashboard must be a **summary-and-exception surface that links into the existing Reporting Hub and resource indexes**, not a second calculation owner. The codebase already enforces this principle explicitly: `ExecutiveKpiOverviewData`'s docblock states *"the executive overview is a COMPOSITION, never a second calculation owner"* (`app/Reporting/DTOs/Marketplace/ExecutiveKpiOverviewData.php:22-32`). The main dashboard must obey the same rule.

**A blunt caveat that must shape the finance section.** Per `docs/financial-provider-activation-handoff.md:15-28`, both real-money providers are code-complete but **account-unverified**: *"Production financial activation: Not ready."* Neither RazorpayX nor Stripe has ever been exercised against a real account. Finance KPIs on the dashboard will therefore render structurally correct but commercially near-empty numbers until activation. This is a product-honesty issue, not a bug — see [§14](#14-data-and-implementation-gaps).

---

## 2. Evidence and confidence

### Documents inspected

| Document | Status |
|---|---|
| `docs/index.md` | Read in full — canonical doc catalogue |
| `docs/SRS.md` (30,095 lines) | Table of contents read in full; Book 1 Ch. 4 (domains), Ch. 5 (personas), Book 2 §19.7 (dashboard types), §19.8 (KPIs) read in detail; all 80 "dashboard" occurrences located and sampled |
| `docs/Roadmap.md` | Read in full |
| `docs/SRS_Compliance_Audit.md` | Header + executive summary + compliance-matrix sample read |
| `docs/financial-provider-activation-handoff.md` | Status section read in full |
| `CLAUDE.md`, `AGENTS.md`, `README.md` | Read |

### Project directories inspected

`app/Filament/{Pages,Widgets,Resources,Navigation}`, `app/Reporting/**` (all 99 files enumerated; registries, all summary DTOs, all service public APIs, `ReportingPeriod`, `ReportAccessContext` read), `app/Alerts/**`, `app/Quality/**`, `app/Booking/{Services,Repositories,Gateways,Meetings}`, `app/SupportCases/Enums`, `app/Compliance/`, `app/Messaging/`, `app/Settings/`, `app/Services/PortalResolver.php`, `app/Services/Mail/EmailLogService.php`, `app/Listeners/{Mail,Alerts}`, `app/Models/` (110 models enumerated), `database/seeders/` (59 seeders), `database/migrations/` (181 migrations), `routes/{web,api,console}.php`, `config/`, plus `vendor/filament/**` to verify URL-binding behaviour.

### Does the SRS match the implementation?

**Partially, with three classes of divergence.**

| Divergence | Evidence |
|---|---|
| **SRS specifies more dashboards than exist.** SRS §19.7 (`docs/SRS.md:18309-18400`) names six dashboard types (Executive, Operations, Finance, Marketplace, Learning, Instructor Quality). All six exist **as separate report pages**, none as the main dashboard. The main dashboard is not described anywhere in the SRS. | `app/Filament/Pages/{ExecutiveKpiOverview,BookingLessonMeetingOperations,FinanceOverview,MarketplaceSupplyDemand,LearningAnalytics,ReviewsQualityDashboard}.php` |
| **SRS specifies KPIs the platform deliberately refuses to compute.** SRS §19.8 lists "Student retention", "Instructor utilization", "Total revenue", "Instructor profile views", "Search-to-profile conversion", "Profile-to-demo conversion", "Favorite additions", "Search activity". The `MetricRegistry` explicitly and repeatedly refuses several of these by name with stated reasons. | `MetricRegistry.php:576` (retention: *"never labeled retention (§6.4 Outcome C: no authoritative retention definition exists)"*); `:685` (utilization: *"historical availability is not reconstructable… so NO utilization rate is derived"*); `:758` (revenue: *"COMMERCIAL VALUE, deliberately never labeled revenue (§7 Outcome B — no revenue-recognition definition exists)"*) |
| **SRS-listed marketplace analytics are simply not implemented.** Profile views, search logging, recently-viewed, search analytics: zero code. Verified by grep for `profile_view`, `profileView`, `search_log`, `SearchAnalytic`, `recently_viewed` across `app/` and `database/migrations/` — **no matches**. | grep, no hits |

### Stale documentation flagged

`docs/SRS_Compliance_Audit.md` self-identifies as *"a dated, one-time snapshot… as of 2026-07-19"* and is **materially out of date**. Three of its five headline blockers are now false:

- It states *"There is no support/dispute case system and no student-instructor messaging at all"* — both now exist (`app/SupportCases/` with 6 status cases, 25 categories, 4 priorities; `app/Messaging/` with `Conversation`/`Message`/`MessageReport`/`MessagingRestriction` models and a registered `MessagingStatsWidget`).
- It states *"no suspicious-activity/compliance-monitoring layer"* — `app/Compliance/Services/ComplianceMonitoringService.php` and the `SuspiciousActivityFlag` model/resource now exist.
- It states *"There is no recharge flow (button says 'Coming soon')"* — `WalletRecharge` model, `RechargeMonitoring` report page, `ReconcileWalletRecharges` scheduled command, and a live recharge webhook (`routes/api.php:27`) now exist.

**Do not use that document as a current gap list.** `docs/Roadmap.md` is the accurate one, and it explicitly names this audit's subject as an open gap: line 35, *"Dashboard improvements (charts, richer widgets)."*

### Missing or inaccessible evidence

- **No production data was inspected.** All volume/performance judgements below are structural (index coverage, query shape), not measured.
- **`docs/audits/admin-forms-remediation-backlog.md`** is referenced by `docs/index.md:100` but was not read; it is scoped to admin-form validation/confirmation issues, not dashboard content.
- **Blade views** (`resources/views/filament/{pages,widgets}/**`) were not read. Widget/page *behaviour* was determined from PHP classes; exact rendered layout was not verified.
- **Frontend-portal dashboards** (`app/DTOs/StudentDashboard`, `app/DTOs/InstructorDashboard`, `/dashboard` Blade routes) were identified but not audited — they are out of scope for the **admin** dashboard and belong to the Frontend Portal per `PortalResolver`.
- **Test suite was not run.** No claim below rests on test outcomes.

### Audit limitations

1. Menu labels were treated as insufficient proof throughout; every claim of "implemented" is anchored to a class, method, migration, route, or seeder.
2. Query cost is assessed from index definitions and query shape only.
3. Where the SRS and code conflict, both are reported separately; no reconciliation is assumed.

### Overall confidence

**High** for: module inventory, report inventory, metric availability and calculation semantics, permission model, route/drill-down existence, absence of tenant scoping, absence of historical snapshots, absence of URL state on report pages.

**Medium** for: relative business priority of recommended sections (a product judgement, informed by but not determined by code), and query-performance severity (structural inference, not measured).

---

## 3. Module inventory

| Module | Implemented features | Main entities | Important workflows | Existing reports | Key routes | Permissions | Implementation status |
|---|---|---|---|---|---|---|---|
| **Identity & Access** | Registration, login, 2FA, password reset, email verification, account lock, force password change, session tracking, login history, roles/permissions (Spatie + Shield) | `User`, `UserProfile`, `UserSession`, `LoginHistory`, `UserPasswordHistory`, Spatie `Role`/`Permission` | Register → verify → approve → activate; login → session track → audit | Login History resource; no analytic report | `filament.admin.resources.users.index`, `.login-history.index`, `.roles.index`, `.permissions.index` | `ViewAny:User`, `ViewAny:Role`, `ViewAny:LoginHistory` | **Implemented and dashboard-ready** (but low business value — see [§12](#12-content-to-remove-or-relocate)) |
| **Instructor Management & Onboarding** | 11-state lifecycle (`draft→submitted→under_review→documents_pending→interview_required→approved→active→vacation→suspended→archived→rejected`), document requirements, KYC evidence, approval workflow, education/experience records | `UserProfile.instructor_status`, `InstructorDocumentRequirement`, `UserEducation`, `UserExperience`, `TeacherSubject`, `InstructorSubjectTopic` | Apply → review → docs → approve → activate | **Instructor Performance** report | `filament.admin.resources.instructor-onboarding.index` (+ `/{record}/edit`), `filament.admin.pages.instructor-performance` | `ViewInstructorReports`; onboarding resource perms | **Implemented and dashboard-ready** (badge query already exists) |
| **Student Management** | Student status lifecycle, learning goals, learning plans, preferred subjects, favourites, profile | `UserProfile.student_status`, `StudentLearningGoal`, `StudentLearningPlan`, `StudentFavoriteInstructor` | Register → verify → set goals → plan → book | **Student Engagement** report | `filament.admin.pages.student-engagement`, `.resources.student-learning-goals.index`, `.student-learning-plans.index` | `ViewStudentReports` | **Implemented and dashboard-ready** |
| **Academic Taxonomy** | Categories, subjects, subject topics, academic levels, instructor topic coverage | `AcademicCategory`, `Subject`, `SubjectTopic`, `AcademicLevel`, `InstructorSubjectTopic` | Configure taxonomy → link to instructors/pricing | None (reference data) | `filament.admin.resources.academic.*` | `ViewAny:Subject` etc. | **Implemented** — reference data, not dashboard content |
| **Availability & Scheduling** | Weekly availability rules, exceptions, leave, holidays, slot generation, buffers, vacation mode, DST-safe timezones | `TeacherAvailability`, `TeacherUnavailability`, `Holiday` | Publish schedule → generate slots → reserve → book | Feeds **Marketplace Supply & Demand** (`active_instructors_without_availability`) | `.resources.teacher-availability.index`, `.teacher-leave.index` | `ViewAny:TeacherAvailability` | **Implemented but requires aggregation work** — current-state only, no history (see [§14](#14-data-and-implementation-gaps)) |
| **Booking Engine** | Demo/paid/recurring bookings, concurrency-safe slot consumption, reservation expiry, reschedule, cancel, activity log | `Booking`, `BookingType`, `BookingActivity`, `BookingPayment` | Reserve → pay → confirm → meeting → lesson | **Booking Reports**, **Booking/Lesson/Meeting Operations** | `.resources.bookings.index` (+ `/{record}/edit`), `.pages.booking-reports`, `.pages.booking-lesson-meeting-operations` | `ViewBookingLessonReports`, `ViewOperationalReports`, `ViewAny:Booking` | **Implemented and dashboard-ready** |
| **Lesson Lifecycle & Attendance** | Status + separate finalized outcome, attendance records/events/confirmations, technical-issue reports, disputes, auto-completion | `Lesson`, `LessonAttendanceRecord`, `LessonAttendanceEvent`, `LessonAttendanceConfirmation`, `LessonTechnicalIssueReport` | Scheduled → live → attendance → finalize outcome → settle | **Booking/Lesson/Meeting Operations** | `.resources.lessons.index` (**index only, no record page**) | `ViewOperationalReports`, `ViewAny:Lesson` | **Implemented and dashboard-ready** |
| **Meeting Management** | Manual / Google Meet / Zoom / Fake providers, creation + sync + cancellation, attendance webhooks, recordings | `BookingMeeting`, `Recording`, `MeetingAttendanceProviderEvent` | Booking confirmed → create meeting → sync → capture attendance/recording | Operations report (`meetingSummary`) | `.resources.recordings.index` | `ViewMeetingReports` | **Implemented and dashboard-ready**; `meeting_reliability` report formally unavailable by design |
| **Wallet Ledger** | Append-only ledger, single-writer balances, recharge, refund credits, promotional credits, referral credits, reversals, balance/ledger mismatch detection | `Wallet`, `WalletLedgerEntry`, `WalletRecharge` | Recharge → credit → debit for booking → refund credit | **Wallet & Refunds**, **Recharge Monitoring**, **Refund Report** | `.pages.wallet-refunds`, `.pages.recharge-monitoring`, `.resources.wallets.index` | `ViewWalletReports` | **Implemented and dashboard-ready** |
| **Payment Collection** | Razorpay + Stripe checkout, webhook idempotency, provider events, reconciliation issues, invoices | `BookingPayment`, `BookingPaymentProviderEvent`, `BookingPaymentReconciliationIssue`, `Invoice` | Checkout → authorize → capture → reconcile | **Payments & Reconciliation** | `.pages.payments-reconciliation`, `.resources.booking-payments.index`, `.booking-payment-reconciliation-issues.index` | `ViewPaymentReports` | **Implemented, real-money activation blocked** (`financial-provider-activation-handoff.md:15-28`) |
| **Instructor Earnings & Payouts** | Compensation agreements/exceptions/overrides/periods, earnings lifecycle, settlement batches, withdrawal requests, payout methods, payout attempts, payout reconciliation, RazorpayX adapter | `InstructorEarning`, `InstructorCompensationAgreement`, `InstructorSettlementBatch`, `InstructorWithdrawalRequest`, `InstructorPayoutMethod`, `InstructorPayoutAttempt`, `InstructorPayoutReconciliationIssue` | Lesson completes → earning → release → settle → withdraw → payout → reconcile | **Instructor Financials**, **Earnings & Settlements** | `.pages.instructor-financials`, `.resources.instructor-{earnings,withdrawal-requests,settlement-batches,payout-attempts,payout-methods,payout-reconciliation-issues}.index` | `ViewInstructorCompensationReports` (**deliberately independent of `ViewFinanceReports`**) | **Implemented, real-money activation blocked** |
| **Reviews & Ratings** | Eligibility windows, submission, revisions, moderation queue, review reports, rating aggregates, review tags | `LessonReview`, `LessonReviewEligibility`, `LessonReviewRevision`, `ReviewReport`, `InstructorRatingAggregate`, `ReviewRatingContribution`, `ReviewTag` | Lesson completes → eligibility window → submit → moderate → publish → aggregate | **Reviews & Quality Dashboard**, **Review & Quality Analytics** | `.pages.reports.reviews-quality`, `.resources.review-tags.index` | `ViewReviewQualityReports`, `ViewQualityDashboard`, `ViewReviewMetrics` | **Implemented and dashboard-ready** |
| **Quality Alerts** | Automated detectors (low rating, instructor no-show, repeated no-shows, instructor-attributed cancellations, serious review reports), severity policy, fingerprint dedup, alert lifecycle | `InstructorQualityAlert` | Signal detected → alert created/merged → review → resolve/dismiss/escalate | Reviews & Quality Dashboard (`AlertQueueWidget`) | `.pages.reports.reviews-quality` | `ViewQualityDashboard` | **Implemented and dashboard-ready** |
| **Instructor→Student Feedback** | Private per-lesson instructor feedback | `InstructorStudentFeedback` | Lesson completes → instructor writes feedback | None | — | Feedback perms seeded | **Implemented, no report** |
| **Controlled Messaging** | Conversations, messages, leakage-policy flagging, message reports, restrictions | `Conversation`, `Message`, `MessageReport`, `MessagingRestriction` | Booking → conversation opens → messages → flag/report → restrict | `MessagingStatsWidget` on Conversations index | `.resources.conversations.index` (+ `/{record}`) | `ViewAny:Conversation` | **Implemented and dashboard-ready** |
| **Support & Dispute Cases** | 25 categories, 4 priorities, 6 statuses, 3 case types, replies with visibility control, assignment, sequence-numbered references | `SupportCase`, `SupportCaseReply`, `SupportCaseNumberSequence` | Open → assign → in-progress → escalate → resolve → close | None (resource only) | `.resources.support-cases.index` (+ `/{record}`, `/create`) | Support-case perms | **Implemented and dashboard-ready** (badge exists), **no analytic report** |
| **Compliance / Fraud Monitoring** | Rule-coded suspicious-activity detection across Auth/Booking/Referral/Wallet, severity, decision, status | `SuspiciousActivityFlag` | Signal → flag → review → decision | None (resource only) | `.resources.suspicious-activity-flags.index` (+ `/{record}`) | `ViewAny:SuspiciousActivityFlag` | **Implemented and dashboard-ready**, **no analytic report** |
| **Operational Alerts** | 8 alert types, 4 categories, 4 severities, 3 statuses, fingerprint dedup, acknowledge/resolve with audit, notifications | `OperationalAlert` | Domain failure → signal → create/merge → acknowledge → resolve | None (resource only) | `.resources.operational-alerts.index` (+ `/{record}`) | `ViewAny:OperationalAlert`, `Acknowledge:`, `Resolve:` | **Implemented and dashboard-ready** |
| **Referral Program** | Campaigns, codes, attributions, rewards, wallet-credit crediting, reversals | `ReferralCampaign`, `ReferralCode`, `ReferralAttribution`, `ReferralReward` | Share code → attribute → qualify → credit wallet | **Referral Activity** (in Referrals & Communications) | `.pages.referral-communication-reports`, `.resources.referral-*.index` | `ViewReferralReports` | **Implemented**; conversion rate **formally unavailable** (no agreed denominator — `ReportRegistry.php:359`) |
| **Promotional Credits** | Campaigns, issuances, wallet crediting | `PromotionalCreditCampaign`, `PromotionalCreditIssuance` | Create campaign → issue → credit wallet | Surfaced inside wallet movements | `.resources.promotional-credit-{campaigns,issuances}.index` | Promotional-credit perms | **Implemented**, no dedicated report |
| **Waitlist** | Instructor waitlist entries, fulfilment linkage | `InstructorWaitlistEntry` | No slots → join waitlist → slot opens → notify → fulfil | None | `.resources.instructor-waitlist-entries.index` (+ `/{record}`) | Waitlist perms | **Implemented but requires aggregation work** — no report or metric exists |
| **Homework** | Assignments, submissions, grading, overdue tracking, resources + versions, due reminders with channel delivery | `HomeworkAssignment`, `HomeworkResource`, `HomeworkResourceVersion`, `HomeworkDueReminder`, `HomeworkReminderChannelDelivery` | Assign → submit → grade; reminder scheduler | **Homework Report** (section of Learning Analytics) | `.pages.learning-analytics` | `ViewLearningReports` | **Implemented and dashboard-ready** |
| **Learning Plans & Progress** | Plans, milestones, assessments, progress reviews, adjustments, review-due tracking | `StudentLearningPlan`, `LearningPlanMilestone`, `LearningPlanReview`, `LearningPlanAssessment`, `LearningPlanAdjustment` | Goal → plan → milestones → reviews → complete | **Learning Analytics**, **Learning Plan Report** | `.pages.learning-analytics`, `.resources.student-learning-plans.index` | `ViewLearningReports` | **Implemented and dashboard-ready** |
| **CMS / Content** | 23 block types, pages, posts, categories, tags, navigation (NestedSet, 11 link types), redirects, SEO, FAQ | `Page`, `Post`, `PostCategory`, `Tag`, `NavigationMenu`, `NavigationItem`, `Redirect`, `Faq`, `FaqCategory` | Draft → schedule → publish | None | `.resources.{pages,posts,post-categories,tags,redirects,faq.*,navigation.*}.index` | `ViewAny:Page`, `ViewAny:Post` etc. | **Implemented** — **not marketplace-operational; does not belong on the main dashboard** |
| **Communication / Email** | Resend transactional email with **real provider delivery outcomes** (`sent`/`delivered`/`failed`/`bounced`/`complained`/`suppressed`/`delayed`), email logs, notification templates, newsletter subscribers, contact inquiries, WhatsApp click-to-chat | `EmailLog`, `NotificationTemplate`, `NewsletterSubscriber`, `ContactInquiry` | Notification → mail → provider webhook → status update | **Notification Activity** (in-app only) | `.resources.email-logs.index` (+ `/{record}`), `.notification-templates.index`, `.contact-inquiries.index`, `.newsletter-subscribers.index` | `ViewNotificationReports`, `ViewAny:EmailLog` | **Implemented but requires aggregation work** — email deliverability is captured (`app/Listeners/Mail/LogResendEmailEvent.php`, `email_logs.{status,delivered_at,failed_at}`) but **no metric or report covers it** |
| **Localization** | Countries, states, currencies, languages, per-country config, currency master | `Country`, `State`, `Currency`, `Language` | Configure region → route pricing/gateway | Country dimension on most reports | `.resources.{countries,states,currencies,languages}.index` | Localization perms | **Implemented** — reference data |
| **Platform Administration** | 30 settings classes, feature flags, security pages ×6, cache manager, scheduler monitor, queue monitor, Pulse, activity log, media | `SchedulerHistory`, `FailedJob`, `Activity` | Configure → monitor → clear | Scheduler/Queue monitor pages | `.pages.system.{cache-manager,scheduler,queue-monitor}`, `.pages.settings.*`, `.pages.security.*` | `cache_manager.view`, `scheduler_monitor.view`, `queue_monitor.view`, `security.*.view` | **Implemented** — belongs in a **secondary system-health strip**, super-admin only |
| **Reporting & Analytics** | Report registry (20), metric registry (76), 14 report pages, permission-scoped access context, CSV export with audit, DST-safe reporting periods | `ReportDefinition`, `MetricDefinition` (code-level) | Pick period → view report → drill → export | **The Reporting Hub itself** | `.pages.reporting-hub` + 13 report pages | 15 `View*Reports` + 3 `Export*Reports` permissions | **Implemented and dashboard-ready** — this is the dashboard's primary data supplier |
| **Media Library** | Spatie Media Library attached to models | (polymorphic) | Upload via owning model | None | **No admin resource** | — | **Partially implemented** — `docs/Roadmap.md:34` confirms no Filament resource exists |
| **Search / Discovery Analytics** | — | — | — | — | — | — | **Not found in the project** — SRS §19.8 lists profile views / search-to-profile / profile-to-demo conversion; zero code |

---

## 4. Role and scope matrix

Four roles exist, seeded by `database/seeders/DefaultRolesAndUsersSeeder.php:36-48`: `super_admin`, `manager`, `instructor`, `student`.

**Portal membership is decided solely by `app/Services/PortalResolver.php:27-30`:**

```php
public function usesAdminPortal(User $user): bool
{
    return $user->isSuperAdmin() || $user->hasRole('manager');
}
```

**Only `super_admin` and `manager` can reach the admin dashboard.** `instructor` and `student` are routed to the Blade Frontend Portal at `/dashboard`. Any dashboard section gated on "instructor role" or "student role" would be dead code on this page.

| Role | Primary responsibilities | Data scope | Recommended dashboard focus | Restricted information/actions |
|---|---|---|---|---|
| **`super_admin`** | Everything. `Gate::before()` bypass (`CLAUDE.md`, `ReportAccessContext.php:92`) | **Unrestricted.** No country, currency, subject, or instructor scoping exists anywhere | All three tiers: attention items → business KPIs → **plus** the secondary system-health strip (queue, scheduler, cache, failed jobs, provider activation status) | Nothing restricted. System-health actions (cache clear, queue retry) must be **visually separated** into a secondary strip, not in the primary area |
| **`manager`** | Day-to-day platform operations: onboarding review, booking/lesson operations, finance reporting, moderation, support | **Unrestricted by data**, restricted by permission. Seeded with all 15 `View*Reports` + all 3 `Export*Reports` (`ReportingPermissionSeeder.php:31-50`), plus operational-alert acknowledge/resolve (`OperationalAlertPermissionSeeder.php:24-27`) | Attention items + operational KPIs + business charts + report launchpad. This is the **primary persona** for the redesigned dashboard | Should **not** see Cache Manager / Queue Monitor / Scheduler (not seeded with `cache_manager.view`, `queue_monitor.view`, `scheduler_monitor.view`). Currently the `QuickActionsWidget` correctly hides these behind those permissions (`QuickActionsWidget.php:65`) |
| **`instructor`** | Teaching, availability, earnings | Frontend Portal only | **N/A — cannot reach this page.** Seeded only with CMS post/page permissions (`DefaultRolesAndUsersSeeder.php:104-108`) | All admin dashboard content |
| **`student`** | Learning, booking, wallet | Frontend Portal only | **N/A — cannot reach this page** | All admin dashboard content |

### Scoping boundaries that DO exist (and that the dashboard's global filters should use)

There is **no campus/branch/session/tenant scope**. The real dimensions, taken from `app/Reporting/Filters/ReportFilterKey.php` usage in `ReportRegistry`, are:

`Country`, `Currency`, `Subject`, `EducationLevel`, `Instructor`, `Student`, `BookingType`, `BookingStatus`, `LessonStatus`, `LessonOutcome`, `MeetingStatus`, `PaymentStatus`, `WalletTransactionType`, `WalletTransactionStatus`, `EarningStatus`, `SettlementStatus`, `WithdrawalStatus`, `LearningPlanStatus`, `LearningGoalStatus`, `HomeworkStatus`, `ReviewStatus`, `ReviewReportStatus`, `QualityAlertStatus`, `InstructorStatus`, `StudentStatus`, `RecurrenceType`.

Plus a **reporting timezone** resolved by `app/Reporting/Support/ReportingTimezoneResolver.php` and a **period** (`Today`, `Yesterday`, `Last7Days`, `Last30Days`, `ThisMonth`, `PreviousMonth`, `Custom` ≤366 days — `ReportingPeriod.php:24,44-70`).

### Metrics containing sensitive information

Per `sensitive: true` / `financial: true` flags in `MetricRegistry`:

- **Financial, strictest gate (`ViewInstructorCompensationReports`, deliberately NOT implied by `ViewFinanceReports` — `ReportAccessContext.php:52-59`):** `instructor_earnings_created`, `instructor_earning_liability_by_status`, `settlement_allocation_integrity`, `withdrawals_by_status`, `payout_attempts_by_status`.
- **Financial (`ViewPaymentReports` / `ViewWalletReports`):** `successful_payment_collections`, `gross_paid_booking_value`, `wallet_current_liability`, `wallet_ledger_movements`, `wallet_refunds_executed`, `wallet_balance_ledger_mismatches`.
- **Student PII (`ViewStudentReports`):** all `students_*` metrics. `ReportAccessContext::shouldMaskPersonalData()` (line 66-69) masks identity for users lacking it.
- **Learning data (`ViewLearningReports`):** all goal/plan/homework metrics are `sensitive: true`.

---

## 5. Report inventory

All 20 definitions from `app/Reporting/Registry/ReportRegistry.php:85-441`. Route names verified against `php artisan route:list --name=filament.admin`.

| Report/page | Module | Measures | Filters | Route | Permission | Export | Suitable drill-down target? |
|---|---|---|---|---|---|---|---|
| **Executive KPI Overview** | Executive | Composition of 14 sub-DTOs: students, instructor lifecycle, instructor activity, bookings, lessons, learning plans, homework, milestones/reviews, quality, payments, wallet, refunds, instructor financials, notifications | Period only (deliberately — *"partial executive totals mislead"*, `ExecutiveKpiOverview.php:26`) | `filament.admin.pages.executive-kpi-overview` | `ViewExecutiveReports` | ✗ | **Yes — the single best "see everything" destination.** But it duplicates a lot; the dashboard must not become a second copy of it |
| **Booking, Lesson & Meeting Operations** | Operations | `bookingSummary` (total/byType/byStatus/byRecurrence/rescheduled), `lessonOutcomeSummary` (scheduled/finalized/byOutcome/disputed/unfinalizedPastDue), `meetingSummary` (created/failed/missingMeeting/studentJoined/instructorJoined/bothJoined/technicalIssueReports), plus paginated actionable lesson, meeting-issue and no-show tables | Country, Subject, EducationLevel, Instructor, BookingType, BookingStatus, LessonStatus, LessonOutcome, MeetingStatus | `filament.admin.pages.booking-lesson-meeting-operations` | `ViewOperationalReports` | ✓ (`ExportReports`) | **Yes — the primary operational drill-down.** Highest-value destination for the attention tier |
| **Student Engagement** | Students | 15 fields incl. totalStudents, newInPeriod, verified, engagedInPeriod, withActiveLearningPlans, withoutRecentLearningActivity, lifetimeBookingBuckets, recurringParticipation; plus `registrationTrend` (daily series), byCountry, byAcademicLevel, byPreferredSubject, byBookedSubject | Country, EducationLevel, Student, StudentStatus | `filament.admin.pages.student-engagement` | `ViewStudentReports` | ✓ (`ExportReports`) | **Yes** |
| **Instructor Performance** | Instructors | `lifecycleSummary` (total/byStatus/newAccounts/applicationsSubmitted/approvals), `activitySummary` (12 fields), `qualitySummary`, `demoConversion` | Country, Instructor, Subject, InstructorStatus, BookingType, LessonOutcome | `filament.admin.pages.instructor-performance` | `ViewInstructorReports` | ✓ (`ExportReports`) | **Yes** |
| **Booking Reports** (legacy) | Bookings/Lessons | `kpis` (total, demoRequests, demoBookers, convertedBookers, conversionRate, revenue, refunded, cancelled, cancellationRate, completed), daily trend, popular subjects, popular time slots, teacher utilization, top teachers | BookingType, RecurrenceType, BookingStatus, Country, Subject | `filament.admin.pages.booking-reports` | `ViewBookingLessonReports` / `viewAny Booking` | ✓ (`ExportReports`) | **Yes, with a caveat** — its `revenue` sums across currencies (see [§14](#14-data-and-implementation-gaps)) and `teacherUtilization` is an admitted approximation |
| **Meeting Reliability** | Meetings | — | Instructor | **none** | `ViewMeetingReports` | ✗ | **No — `available: false` by design.** `ReportRegistry.php:178`: *"Version 1 records meeting creation lifecycle only — no attendance callbacks, uptime or provider delivery outcomes exist, and reliability is never inferred from creation status alone"* |
| **Learning Analytics** | Learning | `planSummary` (12 fields), `goalSummary` (11 fields), `homeworkSummary` (13 fields), `milestoneReviewSummary` (7 fields), `trends` (7 daily series), plus plan-review and homework-attention tables | Student, Instructor, Subject, EducationLevel, LearningPlanStatus, LearningGoalStatus, HomeworkStatus | `filament.admin.pages.learning-analytics` | `ViewLearningReports` | ✓ (`ExportReports`) | **Yes** |
| **Learning Plan Report** | Learning | Section of Learning Analytics | Student, Instructor, Subject, EducationLevel, LearningPlanStatus, LearningGoalStatus | `filament.admin.pages.learning-analytics` (same page) | `ViewLearningReports` | ✗ | **Duplicate route** — see "Duplicated reports" below |
| **Homework Report** | Learning | Section of Learning Analytics | Student, Instructor, HomeworkStatus | `filament.admin.pages.learning-analytics` (same page) | `ViewLearningReports` | ✗ | **Duplicate route** |
| **Finance Overview** | Finance | Composed wallet + payment + earnings figures (INR-first) | Currency, Country, PaymentStatus | `filament.admin.pages.finance-overview` | `ViewFinanceReports` | ✗ | **Yes** |
| **Wallet Activity** | Wallet | `walletSummary`: movements, currentLiabilityByCurrency, liabilityAsOf, walletCount, positive/zero/lowBalanceWallets, reversalCount, balanceMismatchCount | Currency, WalletTransactionType, WalletTransactionStatus | `filament.admin.pages.wallet-refunds` | `ViewWalletReports` | ✗ | **Yes** |
| **Refund Report** | Wallet | `refundSummary`: refundDecisionsInPeriod, decisionsByStatus, pendingExecution, executedCount, executedAmountByCurrency, manualReviewCount; plus refund-linkage table | Currency, LessonOutcome | `filament.admin.pages.wallet-refunds` (**same page as Wallet Activity**) | `ViewWalletReports` | ✓ (`ExportFinancialReports`) | **Duplicate route** |
| **Recharge Monitoring** | Wallet | providerCreated, awaitingConfirmation, capturedCreditPending, capturedCreditFailed, succeeded, providerTerminalFailures, stale | Currency | `filament.admin.pages.recharge-monitoring` | `ViewWalletReports` | ✗ | **Yes — strong operational-exception target** (`capturedCreditFailed`, `stale`) |
| **Payment Outcomes** | Payments | attempts, captured, failed, pending, cancelledOrExpired, successRate, capturedAmountByCurrency, averageCapturedByCurrency, grossPaidBookingValueByCurrency, byProviderStatus, duplicateProviderEvents, openReconciliationIssues | Currency, PaymentStatus | `filament.admin.pages.payments-reconciliation` | `ViewPaymentReports` | ✓ (`ExportFinancialReports`) | **Yes** |
| **Earnings & Settlements** | Earnings | 14 fields incl. earningsCreated, earningLiabilityByStatusCurrency, unallocatedReleasableByCurrency, settlementsByStatus, settlementAllocationMismatchCount, withdrawalsByStatus, payoutAttemptsByStatus, openPayoutReconciliationIssues | Currency, Instructor, EarningStatus, SettlementStatus, WithdrawalStatus | `filament.admin.pages.instructor-financials` | `ViewInstructorCompensationReports` | ✗ | **Yes — strictest permission** |
| **Referral Activity** | Referrals | creditsExecutedInPeriod, creditedAmountByCurrency, distinctRecipients, reversals, attributions, rewardsByStatus, heldOrFailedRewardsOpen, reversalRequiredOpen | Currency | `filament.admin.pages.referral-communication-reports` | `ViewReferralReports` | ✗ | **Yes** — but note: *"Conversion rate remains unavailable (no agreed qualifying-event denominator)"* |
| **Review & Quality Analytics** | Reviews/Quality | submissionRate, demoSubmissionRate, paidSubmissionRate, concluded/used/revoked/manual-review windows, platformAverageRating, publishedEligibleReviewCount, pendingReviewReports, openQualityAlerts | Instructor | `filament.admin.pages.referral-communication-reports` (**same page as Referral Activity**) | `ViewReviewQualityReports` | ✗ | **Duplicate route** |
| **Marketplace Supply & Demand** | Marketplace | `marketplaceSupply` (10 fields), `marketplaceDemand` (10 fields incl. byWeekday), `marketplaceComparison` (demandPerActiveInstructor, subjectGaps, activeInstructorsWithoutBookings, countriesWithDemandNoSupply) | Country, Subject, Instructor | `filament.admin.pages.marketplace-supply-demand` | `ViewMarketplaceReports` | ✗ | **Yes — best growth/gap target** |
| **Reviews & Quality Dashboard** | Reviews/Quality | Moderation queue, report queue, alert queue (with mutation actions), rating health top/bottom lists | ReviewStatus, ReviewReportStatus, QualityAlertStatus | `filament.admin.pages.reports.reviews-quality` | `ViewQualityDashboard` | ✗ | **Yes — the moderation-workload owner** |
| **Notification Activity** | Notifications | inAppCreatedInPeriod, inAppReadOfPeriodCohort, readRate, currentUnread, byType, dedupClaimsInPeriod, dedupByClass | none | `filament.admin.pages.referral-communication-reports` (**same page**) | `ViewNotificationReports` | ✗ | **Duplicate route.** Note: *"No delivery attempt/provider outcome is recorded in Version 1"* — this is about **in-app** notifications only |

### Duplicated reports

Four report *definitions* share a page with another definition, and one page hosts three:

- `filament.admin.pages.learning-analytics` serves `learning_progress`, `learning_plan_report`, `homework_report`.
- `filament.admin.pages.wallet-refunds` serves `wallet_activity`, `refund_report`.
- `filament.admin.pages.referral-communication-reports` serves `referral_activity`, `review_quality_analytics`, `notification_delivery`.

**Consequence for the dashboard:** a card linking to "Homework overdue" and a card linking to "Learning plans review-due" land on the *same URL* with no way to scroll to or filter the right section, because report pages have no URL state ([§8](#8-drill-down-map)). Fix that before promising section-level drill-down.

### Gaps in reporting (features with no report)

| Feature | Has data | Has report |
|---|---|---|
| Support & dispute cases | ✓ (`SupportCase`, 6 statuses, 4 priorities, 25 categories) | **✗** |
| Compliance / suspicious activity | ✓ (`SuspiciousActivityFlag`) | **✗** |
| Operational alerts | ✓ (`OperationalAlert`, 8 types) | **✗** |
| Waitlist demand | ✓ (`InstructorWaitlistEntry`) | **✗** |
| Email deliverability | ✓ (`email_logs.status/delivered_at/failed_at`, Resend webhooks) | **✗** |
| Messaging / leakage flags | ✓ (`MessagingReportingService`) | Widget only, on Conversations index |
| Instructor→student feedback | ✓ (`InstructorStudentFeedback`) | **✗** |
| Promotional credits | ✓ | Folded into wallet movements only |
| Recordings | ✓ (`Recording`) | **✗** |
| Media library | ✓ (Spatie) | **✗ no admin resource at all** |

### Dashboard recommendations that would unnecessarily reproduce an existing report

Do **not** put on the main dashboard: a full booking-status breakdown (Booking Reports owns it), a payment-provider status matrix (Payments & Reconciliation owns it), an earning-liability-by-status table (Instructor Financials owns it), a moderation queue (Reviews & Quality Dashboard owns it and carries the mutation actions), a per-instructor performance ranking (Instructor Performance owns it), the full subject supply/demand gap list (Marketplace Supply & Demand owns it). Each of these should appear as **one headline number plus a link**, never as its own breakdown.

---

## 6. Dashboard-ready metric inventory

The `MetricRegistry` contains **76** metrics. Below is the subset appropriate for a main dashboard. Every calculation is quoted from the registry's own `description` and `calculationOwner`, not invented.

**Comparison period: none of these have one.** `ReportingPeriod` exposes only `forPreset()` and `custom()` (`app/Reporting/ValueObjects/ReportingPeriod.php:36,90`) — there is **no `previous()` helper anywhere**. Every "vs. previous period" delta below is marked *requires new work*.

### 6.1 Attention / exception metrics (highest dashboard value)

| KPI/metric | Business meaning | Exact calculation | Data source | Filters | Comparison period | Destination route | Permission | Readiness | Risks |
|---|---|---|---|---|---|---|---|---|---|
| **Open operational alerts** | Automated cross-domain failures needing an admin | `OperationalAlert::where('status','open')->count()` — the exact `getNavigationBadge()` query at `OperationalAlertResource.php:64-70`. Severity split available via `severity` column (`info/warning/high/critical`) | `operational_alerts` | severity, category, type | Not applicable (current-state) | `filament.admin.resources.operational-alerts.index` **+ `?filters[status][value]=open`** | `ViewAny:OperationalAlert` | **Implemented and dashboard-ready** | Resolved alerts clear `active_fingerprint`, so recurrence creates a *new* row (`OperationalAlertService.php:31-36`) — count is episodes, not distinct subjects |
| **Unfinalized, past-due lessons** | Lessons whose end time passed with no finalized outcome — the "stuck" indicator | *"Lessons scheduled in the period whose end time has passed without a finalized outcome"*; scoping `starts_at`, past-due test `ends_at` vs. server clock; includes `pending`, excludes all finalized outcomes (`MetricRegistry.php:303-319`) | `lessons` | Country, Instructor | *requires new work* | `filament.admin.pages.booking-lesson-meeting-operations` | `ViewOperationalReports` | **Implemented and dashboard-ready** | Period-scoped by `starts_at` but past-due by server clock — a mixed frame; label carefully |
| **Confirmed bookings missing a meeting** | Booking confirmed, no meeting created — student cannot join | *"Confirmed bookings whose scheduled start falls in the period with no successfully-created meeting"* (`MetricRegistry.php:357-373`); owner `meetingSummary()->missingMeeting` | `bookings` + `booking_meetings` | Instructor | *requires new work* | `filament.admin.pages.booking-lesson-meeting-operations` | `ViewMeetingReports` | **Implemented and dashboard-ready** | Also independently detected by `CheckMissingMeetingLinksCommand` (every 15 min) → raises a `missing_meeting_link` operational alert. **Two counts of the same problem** — pick one and say which |
| **Meeting creation failures** | Provider call failed | `booking_meetings.status = Failed`, by `created_at`. Authoritative column note: *"`bookings.meeting_status` no longer exists (dropped by migration 2026_07_19_100000)"* (`MetricRegistry.php:339-355`) | `booking_meetings` | Instructor | *requires new work* | `filament.admin.pages.booking-lesson-meeting-operations` | `ViewMeetingReports` | **Implemented and dashboard-ready** | Also raises `meeting_creation_failed` operational alerts — same double-count concern |
| **Disputed lessons** | Lessons under active dispute right now | `LessonStatus::Disputed`, **not period-scoped**, always "as of now" (`MetricRegistry.php:285-301`). Same query as `LessonResource.php:53-58` badge | `lessons` | Country, Instructor | Not applicable | `filament.admin.resources.lessons.index?filters[status][value]=disputed` | `ViewOperationalReports` | **Implemented and dashboard-ready** | Lessons resource has **no record page** — drill-down ends at a filtered list with row actions |
| **Open payment reconciliation issues** | Payment state cannot be reconciled with provider | `BookingPaymentReconciliationIssue::query()->open()->count()` (`BookingPaymentReconciliationIssueResource.php:55-60`); also `PaymentFinancialSummaryData::$openReconciliationIssues` | `booking_payment_reconciliation_issues` | status, severity, type | Not applicable | `filament.admin.resources.booking-payment-reconciliation-issues.index?filters[status][value]=open` | `ViewPaymentReports` / `ViewAny:BookingPaymentReconciliationIssue` | **Implemented and dashboard-ready** | Near-zero until real payment traffic |
| **Open payout reconciliation issues** | Payout state cannot be reconciled | `InstructorPayoutReconciliationIssue::query()->open()->count()`; also `InstructorFinancialSummaryData::$openPayoutReconciliationIssues` | `instructor_payout_reconciliation_issues` | status, severity, type | Not applicable | `filament.admin.resources.instructor-payout-reconciliation-issues.index` | `ViewInstructorCompensationReports` | **Implemented and dashboard-ready** | Zero until RazorpayX activation |
| **Withdrawals awaiting review** | Instructors waiting to be paid | `status IN (Submitted, UnderReview)` (`InstructorWithdrawalRequestResource.php:56-63`) | `instructor_withdrawal_requests` | status, instructor, currency | Not applicable | `.resources.instructor-withdrawal-requests.index?filters[status][value]=submitted` | `ViewInstructorCompensationReports` | **Implemented and dashboard-ready** | **Highly sensitive** — must be hidden without the compensation permission |
| **Instructor applications pending review** | Onboarding queue | `InstructorOnboardingResource::pendingReviewQuery()->count()` | `user_profiles.instructor_status` | instructor_status | *requires new work* | `.resources.instructor-onboarding.index?filters[instructor_status][value]=submitted` | Onboarding perms | **Implemented and dashboard-ready** | Resource's own docblock warns the badge runs *"on every admin page load"* |
| **Critical support cases open** | Escalated user problems | `priority = Critical AND status NOT IN (Resolved, Closed)` (`SupportCaseResource.php:81-89`) | `support_cases` | type, category, priority, status, assigned_to | *requires new work* | `.resources.support-cases.index?filters[priority][value]=critical` | Support-case perms | **Implemented and dashboard-ready** | Only *critical* is badged; "all open" needs a new count |
| **Open suspicious-activity flags** | Fraud/abuse signals | `SuspiciousActivityFlag::where('status','open')->count()` | `suspicious_activity_flags` | status, severity, rule_code, category | Not applicable | `.resources.suspicious-activity-flags.index?filters[status][value]=open` | `ViewAny:SuspiciousActivityFlag` | **Implemented and dashboard-ready** | Sensitive — restrict to super_admin or explicit permission |
| **Pending review moderation** | Reviews awaiting a decision | `submittedReviews + flaggedReviews` from `AdminQualityDashboardService::summary()` (`QualityStatsWidget.php:30-32`) | reviews/quality | ReviewStatus | *requires new work* | `filament.admin.pages.reports.reviews-quality` | `ViewReviewMetrics` / `ViewQualityDashboard` | **Implemented and dashboard-ready** | — |
| **Open instructor quality alerts** | Instructors flagged by automated detectors | `openAlerts + alertsUnderReview`, with `highSeverityAlerts` / `criticalSeverityAlerts` split | `instructor_quality_alerts` | QualityAlertStatus | Not applicable | `filament.admin.pages.reports.reviews-quality` | `ViewQualityDashboard` | **Implemented and dashboard-ready** | — |
| **Wallet recharge credit failures + stale** | Money captured but not credited | `WalletRechargeMonitoringSummary::$capturedCreditFailed`, `$stale`, `$capturedCreditPending` | `wallet_recharges` | Currency | Not applicable | `filament.admin.pages.recharge-monitoring` | `ViewWalletReports` | **Implemented and dashboard-ready** | **The single highest-severity money bug class** — captured but uncredited |
| **Wallet balance/ledger mismatches** | Stored balance ≠ latest ledger `balance_after_minor` | `WalletFinancialReportRepository::balanceMismatchCount` — *"a read-only reconciliation signal, never repaired from a report"* (`MetricRegistry.php:810-826`) | `wallets` + `wallet_ledger_entries` | none | Not applicable | `filament.admin.pages.wallet-refunds` | `ViewWalletReports` | **Implemented and dashboard-ready** | Should be **0**; any non-zero is critical |
| **Settlement allocation mismatches** | Batch total ≠ sum of allocated earnings | Non-cancelled batches only (`MetricRegistry.php:882-898`) | `instructor_settlement_batches` | none | Not applicable | `filament.admin.pages.instructor-financials` | `ViewInstructorCompensationReports` | **Implemented and dashboard-ready** | Should be **0** |
| **Payout attempts needing attention** | `Unknown` or `Failed` provider attempts | `status IN (Unknown, Failed)` (`InstructorPayoutAttemptResource.php:55-62`) | `instructor_payout_attempts` | provider | Not applicable | `.resources.instructor-payout-attempts.index` | `ViewInstructorCompensationReports` | **Implemented and dashboard-ready** | Zero until activation |
| **Homework currently overdue** | Learning obligations missed | `HomeworkAnalyticsData::$currentlyOverdue` — current-state | `homework_assignments` | Student, Instructor, HomeworkStatus | Not applicable | `filament.admin.pages.learning-analytics` | `ViewLearningReports` | **Implemented and dashboard-ready** | Sensitive (student learning data) |
| **Learning plans currently review-due** | Academic governance overdue | `MilestoneReviewAnalyticsData::$plansCurrentlyReviewDue` / metric `plans_currently_review_due` | `student_learning_plans` | plan filters | Not applicable | `filament.admin.pages.learning-analytics` | `ViewLearningReports` | **Implemented and dashboard-ready** | Same page as homework — no section anchor ([§8](#8-drill-down-map)) |
| **Pending message reports** | Reported messages awaiting moderation | `MessageReport::where('status', Pending)->count()` (`ConversationResource.php:74-79`) | `message_reports` | — | Not applicable | `.resources.conversations.index` | `ViewAny:Conversation` | **Implemented and dashboard-ready** | Drill-down lands on Conversations index, **not** on the reports themselves |

### 6.2 Business KPIs

| KPI/metric | Business meaning | Exact calculation | Data source | Filters | Comparison period | Destination route | Permission | Readiness | Risks |
|---|---|---|---|---|---|---|---|---|---|
| **Bookings scheduled** | Demand volume | *"Every booking created in the period, regardless of eventual outcome"*, `bookings.created_at`, reporting timezone (`MetricRegistry.php:69-85`) | `bookings` | Country, Subject, Instructor, BookingType | *requires new work* | `.pages.booking-lesson-meeting-operations` | `ViewBookingLessonReports` | **Implemented and dashboard-ready** | — |
| **Demo vs. paid bookings** | Funnel mix | `byType[free_demo]` / `byType[paid_one_to_one]` | `bookings` + `booking_types` | Country, Subject | *requires new work* | same | `ViewBookingLessonReports` | **Implemented and dashboard-ready** | — |
| **Lessons completed** | Delivered value | Outcome `Completed` by `outcome_finalized_at`. *"Authoritative source: `LessonOutcome::Completed` — never `LessonStatus::Completed` alone, which precedes finalization"* (`MetricRegistry.php:195-211`) | `lessons` | Country, Subject, Instructor | *requires new work* | `.pages.booking-lesson-meeting-operations` | `ViewBookingLessonReports` | **Implemented and dashboard-ready** | Easy to get wrong — use the outcome column, never the status |
| **Lesson outcome mix** | Delivery quality: completed / student no-show / instructor no-show / technical issue / both absent / cancelled | `lessonOutcomeSummary()->byOutcome`, all cases distinct | `lessons` | Country, Instructor | *requires new work* | same | `ViewOperationalReports` (no-shows/technical) | **Implemented and dashboard-ready** | Mixed permission — `lessons_completed` needs `ViewBookingLessonReports`, no-shows need `ViewOperationalReports`. A single stacked chart must gate on **both** |
| **Demo-to-paid conversion** | Marketplace conversion | *"distinct demo-booking students in period (any status), converted when ANY paid-type booking is created at or after the demo (any instructor/subject, unbounded window, once per student)"* — reuses `BookingAnalyticsRepository::conversion()` verbatim (`MetricRegistry.php:701-717`) | `bookings` | **none** | *requires new work* | `.pages.instructor-performance` or `.pages.booking-reports` | `ViewInstructorReports` | **Implemented and dashboard-ready** | **Returns `null`, not 0%, with zero demo bookers** — the card must render "—", not "0%". Per-instructor/subject/country conversion is **UNAVAILABLE, not invented** |
| **New students** | Acquisition | Student-role accounts by `users.created_at` in period | `users` + roles | Country, EducationLevel | *requires new work* | `.pages.student-engagement` | `ViewStudentReports` | **Implemented and dashboard-ready** | — |
| **Engaged students in period** | Real activity, not account status | *"students with ≥1 booking created OR ≥1 lesson finalized Completed in the period"* — *"Deliberately named distinctly from the `student_status` account attribute"* (`MetricRegistry.php:501-518`) | `users`, `bookings`, `lessons` | Country, EducationLevel | *requires new work* | `.pages.student-engagement` | `ViewStudentReports` | **Implemented and dashboard-ready** | Do **not** relabel as "active students" — that is a different, existing concept |
| **Students without recent learning activity** | Churn-risk exception | *"non-suspended, non-archived student accounts with zero bookings created and zero lessons finalized in the period. Never a predictive risk score"* (`MetricRegistry.php:520-536`) | `users`, `bookings`, `lessons` | Country | *requires new work* | `.pages.student-engagement` | `ViewStudentReports` | **Implemented and dashboard-ready** | **Do not label "at-risk students"** — the registry explicitly forbids the predictive framing |
| **Instructors by lifecycle status** | Supply health | All 11 `instructor_status` cases *"reported distinctly, none collapsed"* | `user_profiles` | Country | Not applicable (current-state) | `.pages.marketplace-supply-demand` or `.pages.instructor-performance` | `ViewInstructorReports` / `ViewMarketplaceReports` | **Implemented and dashboard-ready** | 11 categories is too many for one dashboard chart — group visually, keep the report authoritative |
| **Active instructors without published availability** | Supply that cannot be booked | `MarketplaceSupplyData::$activeWithoutPublishedAvailability` | `user_profiles` + `teacher_availability` | Country, Subject | Not applicable | `.pages.marketplace-supply-demand` | `ViewMarketplaceReports` | **Implemented and dashboard-ready** | **Excellent exception KPI** — actionable and unambiguous |
| **Booking demand per active instructor** | Marketplace balance | `MarketplaceComparisonData::$demandPerActiveInstructor` (nullable) | bookings ÷ active instructors | Country, Subject | *requires new work* | `.pages.marketplace-supply-demand` | `ViewMarketplaceReports` | **Implemented and dashboard-ready** | Nullable — render "—" not 0 |
| **Subject supply/demand gaps** | Where to recruit | `MarketplaceComparisonData::$subjectGaps` | teacher_subjects, bookings, learning goals, preferred subjects | Country, Subject | Not applicable | `.pages.marketplace-supply-demand` | `ViewMarketplaceReports` | **Implemented and dashboard-ready** | Registry note: *"compared on compatible dimensions only — no score, no ranking"* — do not rank |
| **Payment success rate** | Collection health | *"Captured-at-some-point attempts ÷ TERMINAL attempts (captured/failed/cancelled/expired/refunded)"*; in-flight excluded from denominator (`MetricRegistry.php:738-754`) | `booking_payments` | Currency, provider | *requires new work* | `.pages.payments-reconciliation` | `ViewPaymentReports` | **Implemented, near-empty until activation** | **Null, never 0%, with no terminal attempts** |
| **Successful external collections** | Money actually captured | `booking_payments` in Captured or Refunded; *"Integer minor units, grouped by currency, never summed across currencies"* | `booking_payments` | Currency, provider | *requires new work* | `.pages.payments-reconciliation` | `ViewPaymentReports` | **Implemented, near-empty until activation** | **Per-currency only** — a single "total revenue" number is prohibited |
| **Current wallet liability** | What the platform owes students right now | `SUM(wallets.balance_minor)` per currency, *"AS-OF-NOW… Never period-scoped — the report-period filter does not change it"* (`MetricRegistry.php:774-790`) | `wallets` | Currency | Not applicable | `.pages.wallet-refunds` | `ViewWalletReports` | **Implemented and dashboard-ready** | Must display an **as-of timestamp** (`WalletFinancialSummaryData::$liabilityAsOf`) and must **not** respond to the global period filter |
| **Current earning liability by status** | What the platform owes instructors, by lifecycle bucket | Current-state amounts per `(status, currency)` across `pending_hold/releasable/disputed_hold/settled/reversed/cancelled` | `instructor_earnings` | Currency, Instructor | Not applicable | `.pages.instructor-financials` | `ViewInstructorCompensationReports` | **Implemented and dashboard-ready** | Strictest permission; per-currency |
| **Homework on-time submission rate** | Learning discipline | Metric `homework_on_time_submission_rate` / `HomeworkAnalyticsData::$onTimeSubmissionRate` (nullable) | `homework_assignments` | Student, Instructor | *requires new work* | `.pages.learning-analytics` | `ViewLearningReports` | **Implemented and dashboard-ready** | Nullable |
| **Platform average rating** | Trust signal | `ReviewQualityRatesData::$platformAverageRating` (nullable), over eligible published reviews | `instructor_rating_aggregates` / reviews | Instructor | *requires new work* | `.pages.reports.reviews-quality` | `ViewReviewQualityReports` | **Implemented and dashboard-ready** | Nullable — "No ratings yet" |
| **Review submission rate** | Feedback loop health | `ReviewQualityRatesData::$submissionRate` with demo/paid split | review eligibilities | Instructor | *requires new work* | `.pages.reports.reviews-quality` | `ViewReviewQualityReports` | **Implemented and dashboard-ready** | Nullable |
| **Referral wallet credits** | Growth-program cost/effect | *"Wallet-ledger-confirmed referral credits"*, per currency | `wallet_ledger_entries` | Currency | *requires new work* | `.pages.referral-communication-reports` | `ViewReferralReports` | **Implemented and dashboard-ready** | **Referral conversion rate does not exist** — do not promise it |

### 6.3 Metrics deliberately unavailable — do not put these on the dashboard

| Metric the SRS asks for | Why it is unavailable | Evidence |
|---|---|---|
| **Total revenue / recognized revenue / net revenue / margin** | No revenue-recognition definition exists. Only "gross paid-booking value" (commercial value) exists, per currency | `MetricRegistry.php:756-772` |
| **Student retention** | *"no authoritative retention definition exists"* — only a lifetime-booking-count distribution | `MetricRegistry.php:574-590` |
| **Instructor utilization rate** | Historical availability is not reconstructable (schedule rows updated in place, no versioning) | `MetricRegistry.php:683-699` |
| **Same-instructor retention** | No definition with a complete observation window; only "repeat paid students" as an activity proxy | `MetricRegistry.php:646-663` |
| **Meeting reliability / uptime** | No attendance callbacks or provider delivery outcomes recorded | `ReportRegistry.php:178` |
| **Referral conversion rate** | No agreed qualifying-event denominator | `ReportRegistry.php:359` |
| **Notification delivery rate (in-app)** | No delivery attempt/provider outcome recorded for in-app | `ReportRegistry.php:427` |
| **Instructor profile views / search analytics / search-to-profile / profile-to-demo conversion / favourite additions** | **No implementation at all** | grep: zero matches for `profile_view`, `search_log`, `SearchAnalytic`, `recently_viewed` |
| **Historical wallet liability** | *"ledger reconstruction unproven against snapshots"* | `MetricRegistry.php:776` |

---

## 7. Recommended main-dashboard sections

Priority order. Each section respects the global context bar ([§13](#13-recommended-dashboard-hierarchy)). **No section is a data table.**

---

### Section 1 — Needs Attention (highest priority)

- **Purpose:** Answer "is anything broken or waiting on me?" in under three seconds. This is the single largest gap in the current dashboard, and 100% of its data already exists.
- **Intended roles:** `super_admin`, `manager`.
- **Cards or charts:** A row of **alert tiles**, each a count with a severity colour. Recommended maximum **6 visible**, with the rest collapsed under "N more".
  1. Open operational alerts (with critical/high split as the sub-line)
  2. Unfinalized past-due lessons
  3. Confirmed bookings missing a meeting *or* meeting creation failures (**pick one — see [§14](#14-data-and-implementation-gaps) double-count**)
  4. Withdrawals awaiting review *(compensation permission only)*
  5. Wallet recharges: captured-but-uncredited + stale *(wallet permission only)*
  6. Critical support cases open
  - Plus **hard-zero integrity tiles** rendered only when non-zero: wallet balance/ledger mismatches, settlement allocation mismatches, open payment/payout reconciliation issues.
- **Underlying metric:** [§6.1](#61-attention--exception-metrics-highest-dashboard-value) rows.
- **Global filters respected:** **None.** Every tile here is current-state. The period selector must visibly not apply — the section header should read "As of now, {timestamp}".
- **Empty/loading/error state:** Empty → a single positive line: "Nothing needs attention." Loading → skeleton tiles. Error → the tile shows "—" with a retry, never a silent zero (a false zero here is a safety failure).
- **Click behaviour:** Whole tile is the link.
- **Drill-down destination:** Filtered resource index ([§8](#8-drill-down-map)), which supports pre-applied filters via `?filters[...]`.
- **Permission behaviour:** Each tile renders only if the viewer holds its permission; a hidden tile must **not execute its query** (mirror `Dashboard::getWidgets()`'s existing defence-in-depth pattern at `Dashboard.php:88-94`).
- **Why here and not in Reports:** Reports answer "what happened over a period". These are current-state exceptions with a required human action. There is no report that aggregates across all of them — today they are scattered across 15 sidebar badges the admin must notice individually.

---

### Section 2 — Operations Today

- **Purpose:** "Is today's delivery going to plan?"
- **Intended roles:** `super_admin`, `manager` with `ViewOperationalReports`.
- **Cards or charts:** 3–4 KPI cards: lessons scheduled today · lessons completed (period) · no-show + technical-issue count (period) · bookings created (period).
- **Underlying metric:** `lessons_scheduled`, `lessons_completed`, `student_no_shows` + `instructor_no_shows` + `technical_issue_lessons`, `bookings_scheduled`.
- **Global filters respected:** Period, Country, Subject, Instructor (all supported by the Operations report definition).
- **Empty/loading/error state:** Empty → "No lessons scheduled in this period." Loading → skeleton. Error → "—" + retry.
- **Click behaviour / destination:** `filament.admin.pages.booking-lesson-meeting-operations`. **Filters cannot currently be carried in the URL** — see [§14](#14-data-and-implementation-gaps).
- **Permission behaviour:** Section hidden entirely without `ViewOperationalReports`.
- **Why here:** These four numbers are the platform's pulse. The Operations report has them but is three clicks away and defaults to Last 30 Days.

---

### Section 3 — Lesson Outcome Quality (chart)

- **Purpose:** Show whether delivery quality is drifting, not just volume.
- **Intended roles:** `super_admin`, `manager` with **both** `ViewBookingLessonReports` **and** `ViewOperationalReports`.
- **Cards or charts:** One **stacked horizontal bar** (single bar, 100%-width) of finalized lesson outcomes: completed / student no-show / instructor no-show / both absent / technical issue / cancelled. Plus a small "unfinalized past-due: N" annotation.
- **Underlying metric:** `lessonOutcomeSummary()->byOutcome` + `->unfinalizedPastDue`.
- **Global filters:** Period, Country, Subject, Instructor.
- **Empty state:** "No lessons finalized in this period."
- **Click behaviour:** Each segment links to the Operations report; the segment label names the outcome.
- **Permission:** Hidden without both permissions (mixed-permission metric — see [§6.2](#62-business-kpis)).
- **Why here:** A single glance at delivery health. The Operations report shows this as numbers; the dashboard's job is the shape.

---

### Section 4 — Growth & Conversion (chart + KPIs)

- **Purpose:** Is the marketplace acquiring and converting?
- **Intended roles:** `super_admin`, `manager` with `ViewStudentReports` / `ViewInstructorReports`.
- **Cards or charts:**
  - **Line chart:** daily new-student registrations across the period — `StudentEngagementReportService::registrationTrend()` returns a gap-filled daily array, ready to plot.
  - **KPI cards:** new students · engaged students in period · demo-to-paid conversion % (**render "—" when null**) · instructor applications approved in period.
- **Global filters:** Period, Country, EducationLevel.
- **Empty state:** Flat zero line with "No registrations in this period" — not a hidden chart.
- **Click behaviour / destination:** Chart → `.pages.student-engagement`; conversion card → `.pages.instructor-performance`.
- **Permission:** Cards individually gated.
- **Why here:** One genuine time series that already exists, gap-filled, timezone-correct.

---

### Section 5 — Marketplace Supply & Demand

- **Purpose:** Where the marketplace is out of balance.
- **Intended roles:** `manager`/`super_admin` with `ViewMarketplaceReports`.
- **Cards or charts:**
  - **Grouped horizontal bar:** top 5 subjects, active instructors vs. bookings in period.
  - **KPI cards:** active instructors without published availability · demand per active instructor (nullable) · countries with demand but no supply.
- **Global filters:** Period, Country, Subject.
- **Empty state:** "No instructor supply data yet."
- **Click behaviour / destination:** `.pages.marketplace-supply-demand`.
- **Permission:** Section hidden without `ViewMarketplaceReports`.
- **Why here:** "Active instructors without published availability" is a directly actionable exception that exists nowhere else in the UI.

---

### Section 6 — Money Position (permission-gated, currency-honest)

- **Purpose:** What the platform holds and owes, right now.
- **Intended roles:** `super_admin`; `manager` per-card by permission.
- **Cards or charts:** 3 KPI cards, **each rendered per currency, never summed across currencies**:
  1. Current wallet liability (as-of timestamp shown) — `ViewWalletReports`
  2. Current earning liability, releasable bucket — `ViewInstructorCompensationReports`
  3. Payment success rate (period) — `ViewPaymentReports`
- **Global filters:** Currency and Period apply to card 3 only. Cards 1–2 are as-of-now and must visibly ignore the period.
- **Empty state:** "No wallet activity yet" / "Payment providers not yet activated" — the latter is the honest state today.
- **Click behaviour / destination:** `.pages.wallet-refunds`, `.pages.instructor-financials`, `.pages.payments-reconciliation`.
- **Permission:** Card-level. `ViewFinanceReports` must **not** unlock the compensation card (`ReportAccessContext.php:52-59`).
- **Why here:** Liability is a standing obligation, not a period report. But **see [§14](#14-data-and-implementation-gaps)** — until provider activation, cards 1 and 3 will be near-empty; consider a "provider activation pending" ribbon rather than a misleadingly healthy zero.

---

### Section 7 — Learning & Academic Health

- **Purpose:** Is the educational side functioning?
- **Intended roles:** `manager`/`super_admin` with `ViewLearningReports`.
- **Cards or charts:** 3 KPI cards: active learning plans · homework currently overdue · plans currently review-due. Optionally one small line chart of homework assigned vs. submitted (`LearningTrendsData` provides both as daily arrays).
- **Global filters:** Period, Subject, EducationLevel, Student, Instructor.
- **Empty state:** "No learning plans yet."
- **Click behaviour / destination:** `.pages.learning-analytics` — **note all three land on the same URL with no section anchor** ([§8](#8-drill-down-map)).
- **Permission:** Section hidden without `ViewLearningReports`. All three metrics are `sensitive: true`.
- **Why here:** Overdue homework and review-due plans are exceptions with deadlines. The rest belongs in Learning Analytics.

---

### Section 8 — Quality & Trust

- **Purpose:** Moderation workload and instructor quality risk.
- **Intended roles:** `manager`/`super_admin` with `ViewQualityDashboard` / `ViewReviewQualityReports`.
- **Cards or charts:** 3 KPI cards: awaiting moderation · open quality alerts (critical/high sub-line) · platform average rating (nullable).
- **Global filters:** Period affects nothing here except rating recency — treat as current-state.
- **Empty state:** "No reviews yet."
- **Click behaviour / destination:** `.pages.reports.reviews-quality` — which owns the mutation actions.
- **Permission:** Section hidden without the quality permission.
- **Why here:** Three numbers summarising a queue the admin must not let grow. The queue itself stays in its own dashboard.

---

### Section 9 — Report Launchpad (replaces the current Quick Actions grid)

- **Purpose:** Get to the right report/resource in one click.
- **Intended roles:** All admin-portal users.
- **Cards or charts:** A compact link grid built from `ReportRegistryInterface::availableFor($user)` — the exact mechanism `ReportingHub::categories()` already uses (`ReportingHub.php:66-88`). Group by `ReportCategory`. Show `available: false` entries as "planned", not links — the Hub already does this correctly.
- **Global filters:** None.
- **Empty state:** Hidden if the user has no report permissions.
- **Click behaviour / destination:** `ReportDefinition::$routeName::getUrl()`.
- **Permission:** Automatic — the registry filters by permission.
- **Why here:** The current `QuickActionsWidget` hardcodes six CMS/identity destinations and four system tools (`QuickActionsWidget.php:81-193`) and knows nothing about the 19 available reports. Replacing hardcoded links with the registry means new reports appear automatically.

---

### Section 10 — System Health (secondary, super-admin only)

- **Purpose:** Infrastructure status, visually subordinate.
- **Intended roles:** `super_admin` only (manager is not seeded with `queue_monitor.view` / `scheduler_monitor.view` / `cache_manager.view`).
- **Cards or charts:** A single low-emphasis strip: failed jobs count · scheduler last-run status · **provider activation state** (RazorpayX / Stripe — read from settings, currently "not activated"). No charts.
- **Global filters:** None.
- **Empty state:** "All systems normal."
- **Click behaviour / destination:** `.pages.system.queue-monitor`, `.pages.system.scheduler`.
- **Permission:** Strictly permission-gated; the whole strip disappears for managers.
- **Why here:** Per the brief, technical actions do not belong in the primary area. But an unnoticed failed queue silently breaks earnings release, lesson finalization, refunds and reconciliation (13 of the 23 scheduled commands are financial or lifecycle-critical) — so it must be *visible*, just not *prominent*.

---

## 8. Drill-down map

**Critical mechanical finding.** Two different destination types with two different capabilities:

| Destination type | URL state support | Evidence |
|---|---|---|
| **Filament Resource list pages** | ✓ Full — `?filters[<name>][value]=`, `?search=`, `?sort=`, `?tab=` | `vendor/filament/filament/src/Resources/Pages/ListRecords.php:33-54` — `#[Url(as: 'filters')] public ?array $tableFilters` |
| **Custom report Pages (all 14)** | ✗ **None** | Verified: zero `#[Url]` attributes and zero `queryString` declarations anywhere under `app/Filament/`. `ExecutiveKpiOverview::$periodPreset`, `$customStart`, `$customEnd` are plain public properties (`ExecutiveKpiOverview.php:44-48`) |

**Consequence:** dashboard context can be preserved into resource indexes today, but **not** into any report page. Every "→ report page" row below lands on the page's *default* period (mostly Last 30 Days, ThisMonth for finance/executive), silently discarding the dashboard's selected period. This is the single most important prerequisite for the promised "connected drill-down navigation".

| Dashboard component | First click destination | Applied filters | Second-level destination | Final record destination | Required permission |
|---|---|---|---|---|---|
| Open operational alerts tile | `filament.admin.resources.operational-alerts.index` | `?filters[status][value]=open` ✓ | Filter by severity/category/type in-page | `.operational-alerts.view` (`/{record}`) ✓ | `ViewAny:OperationalAlert` |
| Unfinalized past-due lessons | `filament.admin.pages.booking-lesson-meeting-operations` | **✗ none carried** | In-page lesson table | `.resources.lessons.index` filtered — **no lesson record page exists** | `ViewOperationalReports` |
| Missing meeting / meeting failures | `.pages.booking-lesson-meeting-operations` | **✗ none carried** | In-page meeting-issues table | `.resources.bookings.edit` (`/{record}/edit`) ✓ | `ViewMeetingReports` |
| Disputed lessons | `.resources.lessons.index` | `?filters[status][value]=disputed` ✓ | — | **None — index only, row actions only** | `ViewAny:Lesson` |
| Withdrawals awaiting review | `.resources.instructor-withdrawal-requests.index` | `?filters[status][value]=submitted` ✓ | — | **None — index only, row modal actions** | `ViewInstructorCompensationReports` |
| Instructor applications pending | `.resources.instructor-onboarding.index` | `?filters[instructor_status][value]=submitted` ✓ | — | `.instructor-onboarding.edit` ✓ | Onboarding perms |
| Critical support cases | `.resources.support-cases.index` | `?filters[priority][value]=critical` ✓ | `?filters[status][value]=open` | `.support-cases.view` ✓ | Support-case perms |
| Suspicious activity flags | `.resources.suspicious-activity-flags.index` | `?filters[status][value]=open` ✓ | severity/rule_code filters | `.suspicious-activity-flags.view` ✓ | `ViewAny:SuspiciousActivityFlag` |
| Payment reconciliation issues | `.resources.booking-payment-reconciliation-issues.index` | `?filters[status][value]=open` ✓ | severity/type | **None — index only** | `ViewAny:BookingPaymentReconciliationIssue` |
| Payout reconciliation issues | `.resources.instructor-payout-reconciliation-issues.index` | `?filters[status][value]=open` ✓ | severity/type | **None — index only** | `ViewInstructorCompensationReports` |
| Payout attempts needing attention | `.resources.instructor-payout-attempts.index` | ✓ (status filter exists) | — | **None — index only** | `ViewInstructorCompensationReports` |
| Recharge failures / stale | `.pages.recharge-monitoring` | **✗ none carried** | In-page recharge table | `.resources.wallets.view` ✓ | `ViewWalletReports` |
| Wallet balance/ledger mismatch | `.pages.wallet-refunds` | **✗ none carried** | In-page | `.resources.wallets.view` ✓ | `ViewWalletReports` |
| Settlement allocation mismatch | `.pages.instructor-financials` | **✗ none carried** | In-page | `.resources.instructor-settlement-batches.index` — **index only** | `ViewInstructorCompensationReports` |
| Pending moderation / quality alerts | `.pages.reports.reviews-quality` | **✗ none carried** | In-page queue widgets with actions | Actions resolve in-place (modals) | `ViewQualityDashboard` |
| Pending message reports | `.resources.conversations.index` | **✗ no report-status filter on this table** | — | `.conversations.view` ✓ | `ViewAny:Conversation` |
| Homework overdue | `.pages.learning-analytics` | **✗ none carried; no section anchor** | In-page homework-attention table | **None** | `ViewLearningReports` |
| Plans review-due | `.pages.learning-analytics` (**same URL as above**) | **✗** | In-page plan-review table | `.resources.student-learning-plans.edit` ✓ | `ViewLearningReports` |
| Lessons completed / scheduled | `.pages.booking-lesson-meeting-operations` | **✗** | In-page | `.resources.lessons.index` — no record page | `ViewBookingLessonReports` |
| Lesson-outcome chart segment | `.pages.booking-lesson-meeting-operations` | **✗ — segment identity lost** | In-page outcome filter (manual) | — | both `ViewBookingLessonReports` + `ViewOperationalReports` |
| New-student trend chart | `.pages.student-engagement` | **✗** | In-page engagement table | `.resources.users.view` ✓ | `ViewStudentReports` |
| Demo→paid conversion | `.pages.instructor-performance` | **✗** | In-page | `.resources.users.view` ✓ | `ViewInstructorReports` |
| Supply/demand bar segment | `.pages.marketplace-supply-demand` | **✗ — subject identity lost** | In-page subject filter (manual) | `.resources.users.index?filters[instructor_status][value]=active` ✓ | `ViewMarketplaceReports` |
| Active instructors w/o availability | `.pages.marketplace-supply-demand` | **✗** | — | `.resources.teacher-availability.index` ✓ | `ViewMarketplaceReports` |
| Wallet liability | `.pages.wallet-refunds` | **✗** | — | `.resources.wallets.view` ✓ | `ViewWalletReports` |
| Earning liability | `.pages.instructor-financials` | **✗** | — | `.resources.instructor-earnings.index` — **index only** | `ViewInstructorCompensationReports` |
| Payment success rate | `.pages.payments-reconciliation` | **✗** | — | `.resources.booking-payments.view` ✓ | `ViewPaymentReports` |
| Platform average rating | `.pages.reports.reviews-quality` | **✗** | In-page rating-health widgets | `.resources.users.view` ✓ | `ViewReviewQualityReports` |
| Report launchpad entry | `ReportDefinition::$routeName::getUrl()` | **✗** | — | Per report | Per `ReportDefinition::$requiredViewPermission` |
| Failed jobs (system strip) | `.pages.system.queue-monitor` | **✗** | In-page failed-job table | Retry action in-place | `queue_monitor.view` |
| Scheduler status (system strip) | `.pages.system.scheduler` | **✗** | In-page history | — | `scheduler_monitor.view` |

### Destinations that do not exist

| Wanted destination | Status |
|---|---|
| Lesson record page | **Does not exist** — `app/Filament/Resources/Lessons/Pages/` contains only `ListLessons.php` |
| Instructor-earning record page | **Does not exist** — index only |
| Withdrawal-request record page | **Does not exist** — index only |
| Settlement-batch record page | **Does not exist** — index only |
| Payout-attempt record page | **Does not exist** — index only |
| Reconciliation-issue record pages (both) | **Do not exist** — index only |
| Media library admin | **Does not exist at all** (`docs/Roadmap.md:34`) |
| Report page with pre-applied period/filters | **Not supported** — no `#[Url]` on any report page |
| Section anchor within Learning Analytics / Wallet & Refunds / Referrals & Communications | **Not supported** — three definitions per page, one URL |

---

## 9. Chart recommendations

Five charts maximum. Every chart below has a verified data source. **No pie/donut charts** — all categorical comparisons use bars.

| Chart title | Best chart type | Dimension | Measure | Time grain | Segments | Interaction | Destination | Reason for chart choice |
|---|---|---|---|---|---|---|---|---|
| **Lesson outcomes** | Single stacked horizontal bar (100%) | Lesson outcome (6 cases) | Count of finalized lessons | Period total (no grain) | completed / student no-show / instructor no-show / both absent / technical issue / cancelled | Hover shows count + %; click a segment → Operations report | `.pages.booking-lesson-meeting-operations` | One bar reads as a *composition* at a glance and stays legible with 6 categories. A pie would make the small failure segments — the ones that matter — unreadable. Segment order should be fixed (best→worst), never sorted by size, so drift is visible across page loads |
| **New student registrations** | Line | Day | New student-role accounts | Daily, gap-filled | Single series | Hover shows date + count; click → Student Engagement | `.pages.student-engagement` | The only genuine gap-filled daily series available (`StudentEngagementRepository::registrationTrend`, lines 247-268, with `CONVERT_TZ` and an explicit zero-fill loop). Line is right for continuous time; a bar chart over 30 days would be noise |
| **Bookings per day** | Line (or bar for ≤14 days) | Day | Bookings created | Daily, gap-filled | Optionally 2 series: demo / paid | Hover; click → Booking Reports | `.pages.booking-reports` | `BookingAnalyticsService::trend()` already returns a zero-filled daily collection and is **cached 300s** — the cheapest chart on the page. Two series show funnel mix without a second chart |
| **Subject supply vs. demand (top 5)** | Grouped horizontal bar | Subject (top 5 only) | Active instructors / bookings in period | Period total | 2 groups | Hover; click a bar → Marketplace report | `.pages.marketplace-supply-demand` | Two measures on one categorical axis is exactly what grouped bars are for. Horizontal because subject names are long. Capped at 5 — the full gap list belongs in the report, and the registry explicitly forbids ranking/scoring |
| **Homework assigned vs. submitted** | Dual line | Day | Homework assigned / submitted | Daily | 2 series | Hover; click → Learning Analytics | `.pages.learning-analytics` | `LearningTrendsData` supplies both as daily arrays. The *gap between the two lines* is the insight (unsubmitted work accumulating); a bar chart hides it |

### Charts explicitly not recommended

| Rejected chart | Why |
|---|---|
| Instructor lifecycle donut (11 statuses) | 11 slices is unreadable; the registry requires all 11 reported distinctly, which a dashboard cannot do. Use a KPI card ("N active instructors") + link |
| Revenue over time | No revenue definition exists; `bookings.price` sums across currencies ([§14](#14-data-and-implementation-gaps)) |
| Instructor utilization gauge | Explicitly refused by `MetricRegistry.php:685` |
| Payment provider pie | Near-empty until activation; and Payments & Reconciliation owns the breakdown |
| Booking status donut | Booking Reports owns it; a status breakdown is not a dashboard-level question |
| Wallet liability over time | Historical liability is not implemented (`MetricRegistry.php:776`) |

---

## 10. Attention and alert rules

**Operational** = a system/integration failure. **Academic/business** = a human-behaviour or workload signal.

| Alert | Trigger condition | Severity | Intended role | Data source | Click destination | Dismissal/resolution behaviour |
|---|---|---|---|---|---|---|
| **OPERATIONAL — Open operational alerts** | `operational_alerts.status = 'open'`, any of 8 types (`meeting_creation_failed`, `meeting_cancellation_failed`, `missing_meeting_link`, `critical_failed_job`, `payment_reconciliation_issue`, `payout_reconciliation_issue`, `wallet_recharge_credit_failed`, `recording_capture_failed`) | From the row's own `severity` (`info`/`warning`/`high`/`critical`) | `manager`, `super_admin` | `OperationalAlert` | `.resources.operational-alerts.index?filters[status][value]=open` | **Real lifecycle exists**: acknowledge → resolve (with mandatory reason), both audited via `OperationalAlertService::acknowledge()/resolve()`. Resolving clears `active_fingerprint`, so a recurrence opens a **new** alert, never resurrects the old one |
| **OPERATIONAL — Wallet recharge captured but uncredited** | `WalletRechargeMonitoringSummary::$capturedCreditFailed > 0` or `$stale > 0` | Critical | `super_admin`, `manager` w/ `ViewWalletReports` | `wallet_recharges` | `.pages.recharge-monitoring` | Auto-clears when `ReconcileWalletRecharges` (every 5 min) succeeds. Also raises a `wallet_recharge_credit_failed` operational alert |
| **OPERATIONAL — Wallet balance/ledger mismatch** | `balanceMismatchCount > 0` | Critical | `super_admin` | `wallets` vs `wallet_ledger_entries` | `.pages.wallet-refunds` | **No dismissal.** Registry: *"a read-only reconciliation signal, never repaired from a report"*. Clears only when the underlying data is corrected |
| **OPERATIONAL — Settlement allocation mismatch** | `settlementAllocationMismatchCount > 0` | Critical | `super_admin` w/ compensation permission | `instructor_settlement_batches` | `.pages.instructor-financials` | No dismissal; clears when data is corrected |
| **OPERATIONAL — Open payment/payout reconciliation issues** | Either `open()` scope count > 0 | High | `manager`, `super_admin` | reconciliation-issue tables | Filtered resource index | Resource has `assign` / `investigate` / `resolve` / `reconcile_now` row actions |
| **OPERATIONAL — Confirmed bookings missing a meeting** | `meetingSummary()->missingMeeting > 0` | High | `manager` w/ `ViewMeetingReports` | `bookings` + `booking_meetings` | `.pages.booking-lesson-meeting-operations` | Auto-clears when `SyncPendingMeetings` (5 min) or `CheckMissingMeetingLinksCommand` (15 min) resolves it |
| **OPERATIONAL — Critical failed jobs** | `critical_failed_job` alerts, classified by `App\Alerts\Support\CriticalJobClassifier` | Critical | `super_admin` | `failed_jobs` → `OperationalAlert` | `.pages.system.queue-monitor` | Retry action in Queue Monitor; alert resolves independently |
| **OPERATIONAL — Payout attempts Unknown/Failed** | `status IN (Unknown, Failed)` | High | compensation permission | `instructor_payout_attempts` | `.resources.instructor-payout-attempts.index` | `cancel` / `reconcile_now` row actions |
| **BUSINESS — Withdrawals awaiting review** | `status IN (Submitted, UnderReview)` | Warning (High if aged — **aging is not currently computed**) | compensation permission | `instructor_withdrawal_requests` | `.resources.instructor-withdrawal-requests.index?filters[status][value]=submitted` | Clears on approve/reject via row actions |
| **BUSINESS — Instructor applications pending** | `InstructorOnboardingResource::pendingReviewQuery()` | Warning | onboarding permission | `user_profiles.instructor_status` | `.resources.instructor-onboarding.index?filters[instructor_status][value]=submitted` | Clears on approve/reject |
| **BUSINESS — Critical support cases open** | `priority = Critical AND status NOT IN (Resolved, Closed)` | Critical | support-case permission | `support_cases` | `.resources.support-cases.index?filters[priority][value]=critical` | Clears on resolve/close |
| **BUSINESS — Open suspicious-activity flags** | `status = 'open'` | From the row's `severity` | `super_admin` (recommend restricting) | `suspicious_activity_flags` | `.resources.suspicious-activity-flags.index?filters[status][value]=open` | Flag has a decision + status lifecycle |
| **ACADEMIC — Unfinalized past-due lessons** | `lessonOutcomeSummary()->unfinalizedPastDue > 0` | Warning | `ViewOperationalReports` | `lessons` | `.pages.booking-lesson-meeting-operations` | Auto-clears via `FinalizeDueLessons` (5 min) / `AutoCompleteLessons` (15 min). **Defers if `lessons.automated_finalization_enabled` is off** — a persistent non-zero may mean automation is disabled, not that lessons are stuck |
| **ACADEMIC — Disputed lessons** | `LessonStatus::Disputed` | High | `ViewOperationalReports` | `lessons` | `.resources.lessons.index?filters[status][value]=disputed` | Row actions on the lessons list |
| **ACADEMIC — Homework currently overdue** | `HomeworkAnalyticsData::$currentlyOverdue > 0` | Info/Warning | `ViewLearningReports` | `homework_assignments` | `.pages.learning-analytics` | No admin dismissal — clears on submission. `SendHomeworkDueReminders` runs on schedule |
| **ACADEMIC — Learning plans review-due** | `plansCurrentlyReviewDue > 0` | Warning | `ViewLearningReports` | `student_learning_plans` | `.pages.learning-analytics` | Clears when a progress review is recorded |
| **QUALITY — Reviews awaiting moderation** | `submittedReviews + flaggedReviews > 0` | Warning | `ViewQualityDashboard` | reviews | `.pages.reports.reviews-quality` | Approve/reject/hide actions on the moderation widget |
| **QUALITY — Open instructor quality alerts** | `openAlerts + alertsUnderReview > 0`, split by high/critical | From alert severity | `ViewQualityDashboard` | `instructor_quality_alerts` | `.pages.reports.reviews-quality` | start-review / resolve / dismiss / escalate, all via `InstructorQualityAlertService` |
| **QUALITY — Pending message reports** | `MessageReport::status = Pending` | Warning | `ViewAny:Conversation` | `message_reports` | `.resources.conversations.index` | Handled in the Conversations resource |
| **NOT IMPLEMENTED — Notification/email delivery failures** | Would be `email_logs.status IN (failed, bounced, complained, suppressed)` | — | — | `email_logs` (**data exists**, `LogResendEmailEvent.php`) | Would be `.resources.email-logs.index` | **No metric, no report, no alert exists.** Recommended new work — see [§14](#14-data-and-implementation-gaps) / [§15](#15-final-prioritized-recommendation) Phase 2 |

---

## 11. Useful links and quick actions

Because only `super_admin` and `manager` reach this page, the matrix has two real rows. All routes verified against `route:list`.

| Role | Action | Existing route | Permission | Context required | Recommended placement |
|---|---|---|---|---|---|
| `manager`, `super_admin` | Review instructor applications | `filament.admin.resources.instructor-onboarding.index` | Onboarding perms | Pending filter | **Primary** — inside the Needs Attention tile |
| `manager`, `super_admin` | Open today's operations | `filament.admin.pages.booking-lesson-meeting-operations` | `ViewOperationalReports` | Period | **Primary** — Operations Today section header |
| `manager`, `super_admin` | Open operational alerts | `filament.admin.resources.operational-alerts.index` | `ViewAny:OperationalAlert` | `status=open` | **Primary** — Needs Attention tile |
| `manager`, `super_admin` | Review withdrawal requests | `filament.admin.resources.instructor-withdrawal-requests.index` | `ViewInstructorCompensationReports` | `status=submitted` | **Primary, permission-gated** |
| `manager`, `super_admin` | Moderate reviews & quality alerts | `filament.admin.pages.reports.reviews-quality` | `ViewQualityDashboard` | — | **Primary** — Quality section header |
| `manager`, `super_admin` | Open support cases | `filament.admin.resources.support-cases.index` | Support-case perms | `priority=critical` | **Primary** |
| `manager`, `super_admin` | Reporting Hub (all reports) | `filament.admin.pages.reporting-hub` | any `View*Reports` | — | **Report launchpad header** |
| `manager`, `super_admin` | Executive KPI Overview | `filament.admin.pages.executive-kpi-overview` | `ViewExecutiveReports` | Period | **Report launchpad** |
| `manager`, `super_admin` | Browse bookings | `filament.admin.resources.bookings.index` | `ViewAny:Booking` | Optional status filter | **Report launchpad** (secondary) |
| `manager`, `super_admin` | Browse users | `filament.admin.resources.users.index` | `ViewAny:User` | Optional role filter | **Report launchpad** (secondary) |
| `super_admin` | Create user | `filament.admin.resources.users.create` | `Create:User` | — | **Secondary** — demote from its current primary slot; user creation is not a daily marketplace task |
| `super_admin` | General settings | `filament.admin.pages.settings.general` | `View:GeneralSettingsPage` | — | **Secondary** |
| `super_admin` | Security settings | `filament.admin.pages.security.authentication` | `security.authentication.view` | — | **Secondary** |
| `super_admin` | Queue Monitor | `filament.admin.pages.system.queue-monitor` | `queue_monitor.view` | — | **System-health strip only** — never primary (per brief) |
| `super_admin` | Scheduler Monitor | `filament.admin.pages.system.scheduler` | `scheduler_monitor.view` | — | **System-health strip only** |
| `super_admin` | Cache Manager | `filament.admin.pages.system.cache-manager` | `cache_manager.view` | — | **System-health strip only** |
| `super_admin` | Activity Log | `filament.admin.resources.activity-logs.index` | `ViewAny:Activity` | — | **Secondary** — move out of primary (see [§12](#12-content-to-remove-or-relocate)) |
| `super_admin` | Login History | `filament.admin.resources.login-history.index` | `ViewAny:LoginHistory` | — | **Secondary** |
| `super_admin`, `manager` | New Page / New Post / Create Role | `.resources.pages.create`, `.posts.create`, `.roles.create` | `Create:Page` / `Create:Post` / `Create:Role` | — | **Remove from dashboard.** These belong in their own modules (see [§12](#12-content-to-remove-or-relocate)) |

---

## 12. Content to remove or relocate

Current dashboard inventory from `app/Filament/Pages/Dashboard.php:38-44`, `StatsOverviewWidget.php:52-77`, and `QuickActionsWidget.php:81-193`.

| Current item | Classification | Reason | Replacement |
|---|---|---|---|
| **Recent registrations table** (`RecentUsersWidget`, 8 rows) | **Remove from dashboard and link to its module** | Brief prohibits tables. Eight rows of arbitrary recency answers no question — an administrator cannot act on "someone registered". Users resource already lists this, sortable and filterable | "New students in period" KPI + the daily registration line chart ([§9](#9-chart-recommendations)), linking to `.pages.student-engagement`; raw list stays at `.resources.users.index` |
| **Recent login activity table** (`RecentLoginsWidget`, 6 rows) | **Remove from dashboard and link to its module** | Six recent logins is a security-forensics view, not a management view, and six rows is too few to detect anything | Nothing on the main dashboard. If login anomaly detection is wanted, the **existing `SuspiciousActivityFlag`** module already produces actionable flags — surface that count instead |
| **Recent audit trail table** (`RecentAuditTrailWidget`, 8 rows) | **Remove from dashboard and link to its module** | Same reasoning. `activity_log` is retained 365 days and pruned weekly; eight rows is a random sample of a compliance archive | Nothing on the main dashboard. Link retained in the secondary launchpad → `.resources.activity-logs.index` |
| **Total Users** stat | **Keep but redesign — replace the metric** | Counts *all* users including admins, instructors, students, blocked and suspended, in one number. Not a marketplace metric | Replace with **two** registry-backed KPIs: `total_students` and instructor supply (`activeInstructors` from `MarketplaceSupplyData`) — both permission-scoped and both with real destinations |
| **Active Users** stat | **Remove — semantically misleading** | This is `users.status = active`, an *account* status, and the sub-line mixes in blocked/suspended. The registry has a deliberately distinct concept — `engaged_students_in_period` — precisely so account status is never mistaken for engagement (`MetricRegistry.php:504`) | `engaged_students_in_period` KPI → `.pages.student-engagement` |
| **Roles / permissions configured** stat | **Remove from dashboard and link to its module** | A count of configuration rows. It changes maybe twice a year and implies no action | Nothing. Roles remain at `.resources.roles.index` |
| **Today's Logins** stat | **Move to secondary/system-health section, super-admin only** | Not a business metric. Also a **performance problem**: `LoginHistory::whereDate('logged_in_at', today())->count()` (`StatsOverviewWidget.php:50`) is non-sargable and `login_histories` has no standalone `logged_in_at` index — only `['user_id','logged_in_at']` and `['user_id','status']` (`2026_06_26_195727_create_login_histories_table.php:39-40`). This is a full scan on every dashboard load | If kept at all, move to the system strip. Otherwise remove |
| **Create user** quick action | **Role-restrict and demote to secondary** | Legitimate but not a daily task on a marketplace where students and instructors self-register | Secondary launchpad, `Create:User` only |
| **New page** quick action | **Remove from dashboard and link to its module** | CMS authoring is not marketplace operations | Content & Communication sidebar group already owns it |
| **New post** quick action | **Remove from dashboard and link to its module** | Same | Same |
| **Create role** quick action | **Remove from dashboard and link to its module** | Rare configuration task | Settings → Identity & Access |
| **Activity log** quick action | **Move to secondary** | Investigative tool, not a daily entry point | Secondary launchpad |
| **Login history** quick action | **Move to secondary** | Same | Secondary launchpad |
| **Settings** quick action | **Move to secondary, role-restrict** | Configuration, not operations | Secondary launchpad, `View:GeneralSettingsPage` |
| **Security** quick action | **Move to secondary, role-restrict** | Same | Secondary launchpad, `security.authentication.view` |
| **Cache Manager** quick action | **Move to system-health section, super-admin only** | Brief explicitly says technical actions must not be in the primary area for ordinary education administrators. Already correctly gated on `cache_manager.view`, which `manager` does not hold | System-health strip |
| **Queue Monitor** quick action | **Move to system-health section, super-admin only** | Same. But it must **remain visible** to super_admin — 13 of 23 scheduled commands are financial/lifecycle-critical | System-health strip, showing failed-job count rather than a bare link |
| **Greeting header + "View Site"** (`Dashboard.php:46-80`) | **Keep as-is** | Harmless, sets tone, and the "View Site" action is genuinely useful | — |
| **`QuickActionsWidget` hardcoded catalogue** (`:79-151`) | **Keep the pattern, replace the source** | The permission-aware view/create-variant logic is good and worth preserving. The *hardcoded six destinations* are the problem — it knows nothing about the 19 available reports | Drive the launchpad from `ReportRegistryInterface::availableFor($user)` the way `ReportingHub::categories()` already does, so new reports appear automatically |

---

## 13. Recommended dashboard hierarchy

Top to bottom. Information architecture only — no visual design.

**0. Global context bar (sticky)**
- **Period selector** — reuse `ReportingPeriodPreset` exactly: Today, Yesterday, Last 7 Days, Last 30 Days, This Month, Previous Month, Custom (≤366 days). Default **Last 30 Days** to match most report definitions.
- **Reporting timezone indicator** — resolved by `ReportingTimezoneResolver`, displayed not chosen (this is what makes period boundaries meaningful, and every report already labels it).
- **Optional Country filter** — the most broadly supported dimension across report definitions.
- **Explicit "as of {timestamp}" marker** for the current-state sections, so period-scoped and as-of-now figures are never confused. `OperationsReportFreshnessData` already carries `generatedAt`, `reportingTimezone`, `periodLabel` for exactly this.
- **Do not add** Campus / Branch / Academic Session selectors — those concepts do not exist.

**1. Needs Attention** ([§7](#7-recommended-main-dashboard-sections) Section 1) — current-state exceptions. Visually first because a broken payout or an uncreated meeting outranks any trend.

**2. Primary KPIs** ([§7](#7-recommended-main-dashboard-sections) Sections 2 + partial 4) — the period pulse.

**3. Core business charts** ([§9](#9-chart-recommendations)) — outcome mix, registration trend, bookings per day, supply/demand.

**4. Upcoming / in-flight work** — lessons scheduled today, plans review-due, homework overdue. Small ranked lists permitted here, **maximum 5 items each**, and only where every item is actionable.

**5. Domain summaries** ([§7](#7-recommended-main-dashboard-sections) Sections 5–8) — Marketplace, Money, Learning, Quality. Three KPI cards each, each linking to its owning report.

**6. Report launchpad** ([§7](#7-recommended-main-dashboard-sections) Section 9) — registry-driven, permission-filtered.

**7. Secondary system health** ([§7](#7-recommended-main-dashboard-sections) Section 10) — super-admin only, visually subordinate, at the very bottom.

### Recommended maximums

| Element | Maximum | Rationale |
|---|---|---|
| Attention tiles visible | **6** (rest collapsed under "N more") | Beyond six, nothing reads as urgent |
| KPI cards total across the whole page | **12** | The registry has 76 metrics; 12 is the honest ceiling for a scannable page. Everything else is one click away in a report that owns it |
| Charts total | **5** | Matches [§9](#9-chart-recommendations) exactly |
| Items in any ranked list | **5** | Per the brief |
| Data tables | **0** | Per the brief |
| Quick-action links in the primary area | **6** | The rest go to the launchpad |

### Permission-degradation rule

The page must remain coherent when sections disappear. A `manager` without `ViewInstructorCompensationReports` should see a dashboard with no Money section — not an empty box, not a "restricted" placeholder. Follow the existing pattern in `ExecutiveKpiOverviewData`: *"A group is null when the viewer lacks the underlying permission — the restricted section's queries never even execute."*

---

## 14. Data and implementation gaps

### Missing aggregates
- **No dashboard-level aggregate service.** Every KPI must be assembled by calling 6–8 report services individually. There is no `DashboardSummaryService`, and the Reporting module has no composition entry point other than `MarketplaceExecutiveReportService::executiveOverview()` — which is deliberately period-only and permission-heavy.
- **No support-case, compliance-flag, waitlist, email-deliverability, recordings, or feedback metrics** in `MetricRegistry` despite all having data.
- **`InstructorWaitlistEntry` has no aggregate anywhere** — waitlist demand, the SRS's stated marketplace-demand signal, is invisible to reporting.

### Missing historical snapshots
- **There are none.** Verified: no `*_snapshots`, `*_daily`, `*_summary`, or `*_metrics` table among 181 migrations. The only aggregate table is `instructor_rating_aggregates` (2026_08_24), which is current-state, not historical.
- **Consequence:** every trend is recomputed live from event timestamps on each page load. Three real series exist (`registrationTrend`, `BookingAnalyticsService::trend()`, `LearningTrendsData`) — all fine at current volume, all O(rows-in-period).
- **Consequence:** historical wallet liability, historical availability, and any point-in-time restatement are impossible. The registry says so explicitly.

### Missing comparison periods
- **`ReportingPeriod` has no `previous()` method.** Only `forPreset()` (line 36) and `custom()` (line 90). **Every "vs. last period" delta on the dashboard requires new work** — either a `ReportingPeriod::previous()` helper or per-card double-fetch. The current `StatsOverviewWidget` fakes this with `+N this month`, which is not a comparison.

### Missing relationships
- **No lesson record page**, so no metric can drill to an individual lesson. Same for earnings, withdrawals, settlement batches, payout attempts, and both reconciliation-issue resources.
- **`Conversations` table has no report-status filter**, so "pending message reports" cannot deep-link to the actual reports.

### Inconsistent statuses (real traps for a dashboard implementer)
- **`LessonStatus` vs. `LessonOutcome` are different columns with overlapping case names.** `MetricRegistry.php:210` warns: *"Authoritative source: `LessonOutcome::Completed` — never `LessonStatus::Completed` alone, which precedes finalization."* Using the wrong one inflates completed-lesson counts.
- **`bookings.meeting_status` no longer exists** (dropped by migration `2026_07_19_100000`). The live column is `booking_meetings.status`.
- **`user_profiles.instructor_reviewed_at` is unreliable for approval counts** — *"overwritten by every later admin transition"*; the registry counts approvals from the structured `activity_log` event `application_approved` instead (`MetricRegistry.php:613`).
- **`recurrence_frequency` has a historical blind spot.** Bookings created before the column existed are bucketed `unknown_historical` and *"never folded into"* `single`. Any dashboard recurrence chart must render that bucket, not hide it.
- **`account status` vs. `engagement`** — `users.status`, `user_profiles.student_status`, and `engaged_students_in_period` are three different things. The current dashboard's "Active Users" conflates the first two.

### Routes without permission protection
None found. Every report page implements `canAccess()`; `ReportAccessContext::hasPermission()` explicitly closes the Spatie/`Gate::before` super-admin gap (`ReportAccessContext.php:90-101`), and `ReportingHub::canAccess()` requires at least one category permission. `Dashboard::getWidgets()` filters by `canView()` **before** rendering so hidden widgets never execute queries.

**One fragility worth flagging:** `DefaultRolesAndUsersSeeder::managerRole()` uses `syncPermissions()` (line 74), which **wipes** all other manager permissions. It runs first in `DatabaseSeeder` (line 18), so the standard path is safe — but running `php artisan db:seed --class=DefaultRolesAndUsersSeeder` alone would silently strip the manager role of all 15 reporting permissions, all operational-alert permissions, and every domain permission granted by the other 58 seeders.

### Reports without drill-down support
**All 14 report pages.** No `#[Url]` attribute or `queryString` declaration exists anywhere under `app/Filament/`. Dashboard period/filter context cannot be carried into any report. Additionally, three pages host multiple report definitions with no section anchors.

### Query-performance risks

| Risk | Evidence | Severity |
|---|---|---|
| **15 navigation-badge COUNT queries on every admin page load** | 15 resources implement `getNavigationBadge()`. `InstructorOnboardingResource`'s own docblock acknowledges it runs *"on every admin page load, not just this"* page. Adding dashboard tiles that repeat these counts **doubles** them | **High** — this is a whole-panel cost, not a dashboard cost |
| **No caching anywhere in `app/Reporting/`** | grep for `cache`/`remember` across `app/Reporting/`: zero implementation hits (only three doc-comment mentions in `ReportDataFreshness.php`). Every report query is live on every render | **High** for a dashboard that composes 6–8 services |
| **`users` has no `created_at` index** | `0001_01_01_000000_create_users_table.php` defines indexes only on `sessions.user_id` and `sessions.last_activity`. `StatsOverviewWidget.php:28-41` runs a full-table `SUM(MONTH(created_at)=…)` aggregate **plus** a 7-day `GROUP BY DATE(created_at)` | **Medium**, growing with user count |
| **`login_histories` non-sargable today-filter** | `whereDate('logged_in_at', today())` (`StatsOverviewWidget.php:50`) with only `['user_id','logged_in_at']` available — the leading column is wrong for this predicate | **Medium** (mitigated by 90-day pruning) |
| **`BookingAnalyticsService::teacherUtilization()` fans out** | `bookedMinutesPerTeacher` then `weeklyAvailableMinutes(ids)` — bounded (limit 10) and cached 300s, so acceptable, but it is the only cached analytics path | Low |
| **Custom period up to 366 days** | `ReportingPeriod::MAX_CUSTOM_RANGE_DAYS = 366`, described as *"Conservative explicit limit for a synchronous Version 1 report screen"*. A dashboard composing 8 services over 366 days multiplies that cost | **Medium** — consider capping the dashboard's own period below 366 |

### Potential N+1 queries
No N+1 found in the reporting layer — `StudentEngagementRepository::paginatedEngagementRows` documents *"constant query count via aggregate subselects"*, and `RecentUsersWidget` eager-loads `['roles','profile']`. The risk on the dashboard is not N+1 but **N independent aggregate queries**, one per KPI card, with no shared caching.

### Missing indexes
- `users.created_at` — needed for any registration KPI or trend.
- `login_histories.logged_in_at` (standalone) — needed only if "Today's Logins" survives.
- Present and adequate: `bookings.created_at`, `bookings.starts_at` (added by `2026_07_03_150000_add_analytics_indexes_to_bookings_table.php`), `lessons(['outcome','outcome_finalized_at'])`, `lessons(['status','ends_at'])`, `booking_activities.action`.

### Tenant / session scoping risks
**None** — no tenant, campus, branch, or academic-session concept exists, so there is no risk of leaking across a boundary that does not exist. The real scoping risks are **permission** (a dashboard card must never render a figure the viewer's report permission would block) and **currency** (see below).

### Metrics that may expose sensitive data
- Instructor compensation figures — must be gated on `ViewInstructorCompensationReports`, which is **deliberately not implied by `ViewFinanceReports`**. Any dashboard shortcut that treats "finance permission" as one thing violates a documented SRS §12 boundary.
- Student identity in any list — `ReportAccessContext::shouldMaskPersonalData()` exists and must be honoured.
- Suspicious-activity flags — recommend restricting to `super_admin` on the dashboard even though `manager` may hold the resource permission.
- All learning metrics are `sensitive: true`.

### Misleading totals — must not be reproduced on the dashboard
- **Cross-currency summation.** `BookingAnalyticsRepository::overview()` computes `revenue` and `refunded` as `SUM(bookings.price)` with **no currency grouping** (`BookingAnalyticsRepository.php:26-27`). `MetricRegistry.php:758` documents this as a *"documented discrepancy, legacy page untouched"*. **The dashboard must use the per-currency `gross_paid_booking_value`, never the legacy figure.**
- **"Revenue" is the wrong word.** The registry: *"COMMERCIAL VALUE, deliberately never labeled revenue."*
- **Double-counting money.** The registry states gross paid-booking value is *"Never added to collections or wallet consumption (double-counting prohibition)."* Three separate money concepts — booking value, external collections, wallet debits — must never be added.
- **Double-counted alerts.** Meeting creation failures and missing meeting links are counted **twice**: once as a metric in the Operations report, once as an `OperationalAlert` row raised by the scheduled checker. A dashboard showing both will read as twice the problem.
- **Utilization approximation.** `teacherUtilization()`'s own comment: *"Approximation: weekly schedule × weeks in period, ignoring leave/holidays."* Do not promote this to a headline KPI.
- **Zero vs. null.** Nine metrics use `ZeroDenominatorPolicy::ReturnNull` — `demo_to_paid_conversion`, `payment_success_rate`, `published_weekly_availability_hours`, `homework_on_time_submission_rate`, the review-rate family, `demandPerActiveInstructor`, `platformAverageRating`, `averageActiveProgressPercent`. **Rendering these as "0%" instead of "—" is a factual error**, and the existing widgets already handle it correctly (`QualityStatsWidget.php:48`: `'No ratings yet'`).

### Features that should not be promised on the dashboard yet
1. **Any real-money figure presented as business performance.** `docs/financial-provider-activation-handoff.md:26`: *"Production financial activation: Not ready."* Neither provider has been exercised against a real account. Finance cards will be structurally correct and commercially near-empty. Recommend an explicit "payment providers not yet activated" state rather than a zero that reads as "no sales".
2. **Instructor payouts as a completed flow.** The stale compliance audit's claim that *"instructors cannot be paid"* needs re-verification against current code, but RazorpayX is confirmed not activated — so a "payouts completed" KPI would be misleading regardless.
3. **Marketplace discovery funnel** (profile views, search-to-profile, profile-to-demo) — **zero implementation**.
4. **Retention, utilization, revenue, meeting reliability, referral conversion, notification delivery rate** — all formally refused by the registry with stated reasons.
5. **Email deliverability** — the *data* is real (`email_logs.status`, Resend webhooks for delivered/failed/bounced/complained/suppressed), but no metric, report, or permission exists. Promising it means building it. Note it is also gated on `RESEND_WEBHOOK_SECRET` being configured; without the webhook, `status` only ever reaches `sent`.
6. **Media Library** — no admin resource exists at all.

---

## 15. Final prioritized recommendation

### Phase 1 — Uses existing data, routes and reports

| # | Recommendation | Priority | Expected value | Effort | Dependencies | Implementation confidence |
|---|---|---|---|---|---|---|
| 1.1 | **Remove the three table widgets** (`RecentUsersWidget`, `RecentLoginsWidget`, `RecentAuditTrailWidget`) from `Dashboard::WIDGETS` | Critical | Immediately satisfies the "no tables" requirement; removes three unindexed-ish queries | **Low** — delete three lines from `Dashboard.php:41-43` | None. Widgets stay registered in `AdminPanelProvider` for reuse elsewhere | **High** |
| 1.2 | **Build the Needs Attention tile row** from the 15 existing badge queries + operational alerts | Critical | The largest single gap. All queries already exist and are proven | **Medium** — one composition service, permission-gated tiles | Reuse existing resource badge queries verbatim; do not re-derive | **High** |
| 1.3 | **Replace `StatsOverviewWidget`'s four stats** with registry-backed marketplace KPIs (`total_students`, `engaged_students_in_period`, `bookings_scheduled`, `lessons_completed`) | Critical | Turns an identity dashboard into a business dashboard | **Medium** | `StudentEngagementReportService`, `BookingLessonMeetingOperationsReportService` | **High** |
| 1.4 | **Replace `QuickActionsWidget`'s hardcoded catalogue** with `ReportRegistryInterface::availableFor($user)` | High | New reports appear automatically; permission handling becomes free | **Low** — mirror `ReportingHub::categories()` | `ReportRegistry`, `ReportAccessContext` | **High** |
| 1.5 | **Add the lesson-outcome stacked bar** | High | Delivery-quality shape in one glance | **Low** — `lessonOutcomeSummary()->byOutcome` is ready | Must gate on both `ViewBookingLessonReports` and `ViewOperationalReports` | **High** |
| 1.6 | **Add the new-student registration line chart** | High | The one genuinely gap-filled daily series | **Low** — `registrationTrend()` returns a ready array | `ViewStudentReports` | **High** |
| 1.7 | **Add the bookings-per-day chart** | Medium | Cheapest chart available (300s cache) | **Low** | `BookingAnalyticsService::trend()` | **High** |
| 1.8 | **Add the global period selector** using `ReportingPeriodPreset` + timezone indicator | High | Without it, every period-scoped card is ambiguous | **Low–Medium** | `ReportingPeriod`, `ReportingTimezoneResolver` | **High** |
| 1.9 | **Deep-link attention tiles to filtered resource indexes** via `?filters[<name>][value]=` | High | Makes the drill-down promise real for the 11 tiles that target resources | **Low** — verified supported (`ListRecords.php:39`) | Filter names verified in [§8](#8-drill-down-map) | **High** |
| 1.10 | **Move Cache Manager / Queue Monitor / Scheduler / Activity Log / Login History to a secondary strip**, super-admin only | High | Satisfies the brief's explicit instruction | **Low** | Existing permissions already correct | **High** |
| 1.11 | **Add per-currency money cards** (wallet liability, earning liability, payment success rate) with as-of timestamps | Medium | Standing obligations made visible; correct currency handling from day one | **Medium** | `FinancialReportsService`, strict permission separation | **High** |

### Phase 2 — Needs moderate backend or reporting work

| # | Recommendation | Priority | Expected value | Effort | Dependencies | Implementation confidence |
|---|---|---|---|---|---|---|
| 2.1 | **Add `#[Url]` bindings to all 14 report pages** (period preset, custom start/end, key filters) | **Critical** | Unblocks *every* "carry dashboard context into the report" row in [§8](#8-drill-down-map). Without this the connected-navigation requirement is unmet | **Medium** — mechanical but touches 14 pages and needs tests | Livewire `#[Url]`; validate/clamp incoming values against `ReportingPeriodPreset` and `MAX_CUSTOM_RANGE_DAYS` | **High** |
| 2.2 | **Add `ReportingPeriod::previous()`** and wire comparison deltas onto KPI cards | High | Turns numbers into trends; currently impossible | **Medium** | Must handle month-length and DST correctly, matching the existing `addMonthNoOverflow` discipline | **High** |
| 2.3 | **Add section anchors** to Learning Analytics, Wallet & Refunds, and Referrals & Communications (3 definitions each on 2 of them) | High | Fixes the "three cards, one URL" problem | **Low–Medium** | Depends on 2.1 | **High** |
| 2.4 | **Add a short-TTL cache layer to dashboard composition** (mirror `BookingAnalyticsService`'s 300s `remember()` pattern) | High | Directly addresses the "no caching anywhere in `app/Reporting/`" risk once 8 services are composed per load | **Medium** | Must set `ReportDataFreshness::CachedWithTimestamp`, not `Live` — the enum's docblock warns against claiming Live while reading cache | **High** |
| 2.5 | **Add `users.created_at` index**; add `login_histories.logged_in_at` if that stat survives | Medium | Removes two full scans | **Low** | New migration | **High** |
| 2.6 | **Register support-case, compliance-flag, waitlist and email-deliverability metrics** in `MetricRegistry` and add report definitions | Medium | Four implemented modules currently invisible to reporting | **Medium** | Follow the existing `MetricDefinition` discipline: exact calculation, statuses, permission, calculation owner | **Medium** — requires product decisions on exact definitions |
| 2.7 | **Resolve the meeting-failure double count** — decide whether the dashboard reads the metric or the alert, and document it | Medium | Prevents a doubled problem count | **Low** | Product decision | **High** |
| 2.8 | **Add an "aged" dimension to withdrawal / support-case / alert tiles** (e.g. "3 waiting > 48h") | Medium | Turns a queue count into an SLA signal | **Medium** | Timestamps exist (`requested_at`, `opened_at`, `first_observed_at`); no aging computation exists | **High** |
| 2.9 | **Add a provider-activation status indicator** to the system strip, reading the payment/payout settings | Medium | Stops finance zeros from reading as "no business" | **Low** | `PaymentGatewaySettings`, `RazorpayXPayoutSettings` | **High** |

### Phase 3 — Needs new functionality or reliable historical data

| # | Recommendation | Priority | Expected value | Effort | Dependencies | Implementation confidence |
|---|---|---|---|---|---|---|
| 3.1 | **Daily metric snapshot table + nightly rollup job** | High | Enables true period-over-period comparison, cheap long-range trends, and removes the live-recompute cost. Currently impossible — no snapshot table exists | **High** — new table, new scheduled command, backfill strategy, and a decision about which of the 76 metrics to snapshot | New migration + `Schedule` entry; must not become a second calculation owner (snapshot the *result* of the owning service) | **Medium** — the engineering is clear, the metric-selection is a product decision |
| 3.2 | **Availability versioning** to make instructor utilization computable | Medium | Unlocks the SRS's "instructor utilization" KPI, currently explicitly refused because schedule rows are updated in place | **High** — schema change to `teacher_availability` plus historical reconstruction | `MetricRegistry.php:685` documents exactly why this is blocked | **Medium** |
| 3.3 | **Define and implement student retention** | Medium | SRS §19.8 asks for it; registry refuses it for lack of a definition | **High** — needs a product-agreed cohort definition first, then implementation | Product decision is the blocker, not code | **Low** until the definition exists |
| 3.4 | **Marketplace discovery analytics** (profile views, search logging, search→profile→demo funnel) | Medium | SRS §19.8 KPIs with **zero** implementation | **High** — new tables, new instrumentation on public routes, privacy review | Nothing exists to build on | **High** (the work is well-understood, just absent) |
| 3.5 | **Revenue recognition definition + implementation** | Low for dashboard, High for finance | Would allow an actual revenue KPI instead of "commercial value" | **High** — accounting decision first | `MetricRegistry.php:758` | **Low** until the definition exists |
| 3.6 | **Record pages for Lessons, Earnings, Withdrawals, Settlement Batches, Payout Attempts, Reconciliation Issues** | Medium | Completes the drill-down chain to an individual record for six high-value entity types | **Medium–High** — six Filament view pages + infolists + policies | — | **High** |
| 3.7 | **Meeting reliability instrumentation** (attendance callbacks, provider delivery outcomes) | Low | Would make the one formally-unavailable report available | **High** — provider-side integration work | Blocked on provider capabilities | **Low** |

---

## Information needed before generating the final dashboard prompt

These are genuine blockers or product decisions that cannot be settled from the repository.

1. **Who is the primary user of this dashboard — `super_admin` or `manager`?** They have materially different permission sets (`manager` has all 15 reporting permissions but none of the three system-monitoring permissions). The Needs Attention tile set, the presence of the Money section, and whether the system-health strip renders at all all depend on this answer. The repository cannot tell me which persona the redesign is optimising for.

2. **Should finance KPIs be shown at all before provider activation?** Both real-money providers are code-complete and account-unverified (*"Production financial activation: Not ready"*). Options: (a) show them with an explicit "not yet activated" state, (b) hide the Money section until activation, (c) show them normally and accept near-zero values. This is a product-honesty decision, not a technical one.

3. **Is adding `#[Url]` to the 14 report pages in scope?** Without it, "dashboard filters and context preserved in destination URLs" is achievable for the ~11 resource-index destinations and **impossible** for every report-page destination. If it is out of scope, the final design must not promise filter-preserving drill-down into reports.

4. **For meeting failures and missing meeting links, which source is authoritative on the dashboard — the Operations metric or the Operational Alert?** Both count the same underlying problem through different pipelines. Showing both doubles the apparent severity; the repository does not state a preference.

5. **What is the acceptable dashboard load budget, and is a cache layer acceptable?** Composing 6–8 uncached report services on top of 15 existing navigation-badge queries is the main performance question. If a short-TTL cache is acceptable, freshness must be declared as `CachedWithTimestamp`, not `Live` — which changes what the page can claim about its own data.

6. **Should any period-over-period comparison appear in v1?** No comparison mechanism exists (`ReportingPeriod` has no `previous()`). If deltas are wanted on KPI cards, that is Phase 2 work that must be scheduled before the dashboard, not after.

7. **Should the four unreported-but-implemented modules — support cases, compliance flags, waitlist demand, email deliverability — get metrics now, or stay off the dashboard until their definitions are agreed?** All four have real data and no `MetricDefinition`. Adding them to the dashboard without registering them in `MetricRegistry` would violate the codebase's own single-calculation-owner rule.
