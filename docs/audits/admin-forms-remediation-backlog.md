# Admin Forms — Functional & Security Remediation Backlog

Findings from the Stage 1 audit (and one found live during Stage 2) that are **not** presentation issues — fixing any of them means changing validation, confirmation requirements, action execution, permissions, or database behavior, all explicitly out of scope for the Admin Forms Production-Readiness Program's Stage 2. Nothing in this document has been implemented. Each item needs its own separate approval before work starts.

Each item below is self-contained (where, issue, what a fix would require). The original Stage 1 audit that surfaced most of these has since concluded and moved to historical archive at [`docs/archive/audits/admin-forms-stage1-audit.md`](../archive/audits/admin-forms-stage1-audit.md) — it is retained for historical context only and is not required to act on any item here.

## P0 — safety-relevant, recommend fixing ahead of the normal stage sequence

### 1. Wallet `adjustment` action has no confirmation and is colored neutral
**Where:** `app/Filament/Resources/Wallets/Pages/ViewWallet.php` — the `adjustment` header action (direct manual credit/debit of a wallet balance).
**Issue:** No `requiresConfirmation()`, colored `gray` — the single most consequential action in the Finance domain is the least guarded. Its siblings on the same page (`freeze`/`unfreeze`/`close`) are all confirmation-gated and semantically colored.
**Fix would require:** adding a confirmation requirement and recoloring — both explicitly listed as "not allowed" in Stage 2 ("Adding or removing confirmation requirements," "Do not recolor lifecycle actions in Stage 2").

### 2. Navigation Menu Builder: no confirmation on delete, loads a script from a public CDN
**Where:** `App\Livewire\Navigation\MenuBuilder` (behind `EditNavigation`) and its Blade view.
**Issue:** `deleteItem()` removes a tree item with no confirmation step at all, unlike every other destructive action in the app. Separately, the component loads SortableJS from `cdn.jsdelivr.net` at runtime, contradicting the panel's own documented practice of self-hosting fonts specifically to avoid third-party CDN dependencies in the authenticated admin panel.
**Fix would require:** custom-component work outside a generic Filament schema (it's a hand-built Alpine/SortableJS tree editor, not a Form) — explicitly called out in Stage 2 as its own separate task, and its hardcoded colors are excluded from Stage 2's color-standardization work for the same reason.

### 3. `PageForm`/`PostForm` "Advanced Layout" block uses hardcoded hex colors and inline styles
**Where:** `app/Filament/Resources/Pages/Schemas/PageForm.php` and `app/Filament/Resources/Posts/Schemas/PostForm.php` — duplicated verbatim in both files.
**Issue:** Literal hex (`#f59e0b`, `#d1d5db`, `#fff`) and inline `style=` attributes bypass Filament's theme/dark-mode system; will render broken/unthemed in dark mode.
**Fix would require:** editing form component content, which Stage 2 restricts to infrastructure only — this is real per-form schema content, deferred to whichever Stage 4 batch covers Content & Communication.

## P1 — inconsistent actions, confirmation, or permission handling

### 4. Reason-field requiredness is inconsistent on equivalent-severity actions
Booking's `cancel` (row + bulk) and Lesson's `cancel` leave `reason` optional; Booking's `archive`/`restore`, Reconciliation's `resolve`, and most Growth/Quality reject/dismiss actions require it. Explicitly deferred — Stage 2 forbids "Making an optional reason required."

### 5. Confirmation-modal usage is inconsistent on danger-colored/terminal actions
Lesson's `dispute` (danger-colored, no confirmation); `ReportQueueWidget::uphold`/`AlertQueueWidget::resolve` (neither confirmation nor `modalDescription`, unlike structurally identical siblings elsewhere); `SuspiciousActivityFlagsTable::begin_review`/`AlertQueueWidget::start_review` (no confirmation) vs. `ReportQueueWidget::startReview` (confirms). Explicitly deferred — "Adding or removing confirmation requirements" is not allowed in Stage 2.

### 6. "Terminal negative outcome" actions colored neutral gray instead of warning/danger
Booking's `no_show`, Lesson's `finalize_no_show`, both Referral/Promotional-Credit campaigns' `archive` — all documented as irreversible in their own code comments, styled like routine actions. Deferred — "Do not recolor lifecycle actions in Stage 2."

### 7. Settings-page permission-gating UX is inconsistent
`RazorpayXPayoutSettingsPage` hides each sensitive action via `->visible()` for users lacking the specific permission; `InstructorEarningSettingsPage`'s equivalent permission is invisible in the form and only rejected inside `save()` after the fact; `ReviewQualitySettingsPage` implements its own two-tier `.view`/`.update` permission pair while its Homework/Demo-Conversion siblings use a single generic gate with no re-check in `save()`. Deferred — "Changing permissions or visibility rules" is explicitly out of scope for Stage 2.

### 8. Silent business-rule overrides with no UI disclosure
Lesson's admin `mark_attendance`/`complete` actions pass `override: true` to the service, bypassing no-show-grace-period/attendance/early-completion guards, with no label, help text, or modal copy indicating an override is happening. Deferred — this is a workflow/business-logic communication gap, not a presentation one; "Fixing business workflows" is out of scope for Stage 2.

### 9. `SubjectTopicForm` slug handling diverges functionally from its siblings
`AcademicCategoryForm`/`AcademicLevelForm`/`SubjectForm` hide the slug on create and validate uniqueness via `GeneratePageSlugAction`; `SubjectTopicForm` shows the slug field and derives it with plain `Str::slug()`, with no DB-backed uniqueness check. This is a validation gap, not a cosmetic one — deferred, since "Changing validation" is out of scope for Stage 2.

### 10. `InstructorSubjectTopicForm` create fails — `subject_id` never reaches the database
**Found live during Stage 2** (a user hit this while exploring the app; unrelated to any Stage 2 change). **Where:** `app/Filament/Resources/Academic/Schemas/InstructorSubjectTopicForm.php:84-87` — the `subject_id` field is `->hidden()->dehydrated()`, meant to be populated from the `subject_topic_id` picker's `afterStateUpdated` callback (`$set('subject_id', SubjectTopic::find($state)?->subject_id)` at line 58). In practice the insert omits `subject_id` entirely (`SQLSTATE[HY000]: General error: 1364 Field 'subject_id' doesn't have a default value`), meaning the field's dehydrated value isn't reaching `Model::create()` — every create of an Instructor Topic Coverage record currently fails.
**Severity note:** unlike the other items in this backlog, this isn't a visual inconsistency — it's a broken create form. Recommend prioritizing this one independently of the presentation program's sequencing, since it blocks a real admin workflow today. Root cause not fully diagnosed (candidates: `Select::relationship()` interacting with a `hidden()` + programmatically-`$set()` field in a way that drops it from the dehydrated state; needs a focused look at the `subject_topic_id` → `subject_id` sync, not a Stage 2 change).

### 11. Livewire test harness breaks for any authenticated user whose `canViewAny()` is false
**Found live during Stage 3** while writing the pilot's own test suite; unrelated to any Stage 2/3 change. Calling any `assertActionExists()`/`assertActionDoesNotExist()`-family assertion (Filament's `TestsActions::parseNestedActions()`) against a Livewire-tested Filament page throws `ErrorException: Attempt to read property "mountedActions" on null` whenever the acting test user's `canViewAny()` for that resource is `false` — reproduced even when asserting the absence of a made-up action name, and independent of whether the permission is held via a role or a direct grant. `Livewire\Testable::instance()` (`lastState->getComponent()`) returns `null` in this state. Root cause not diagnosed (candidates: something in the page's render/mount path — possibly navigation or portal resolution — throwing when a nav-relevant `canViewAny()` check fails, in a way the test harness swallows). Workaround used in this program's own tests: assert the underlying authorization-gated factory/logic directly (e.g. `BackAction::toResourceIndex()`) rather than through a rendered page, for any test scenario needing a `canViewAny()`-false user. Worth a dedicated investigation since it could mask real bugs in future tests that use an intentionally under-permissioned test user.

## P2 — inconsistent headings, spacing, widths, help text, colors (informational — most of this is exactly what Stage 3/4's form-by-form work already addresses)

- Help-text coverage is uneven inside nearly every form audited, in every domain (e.g. FAQ Category: 1 of 5 fields; PostCategory/Tag: 0 of any field; Country/StudentLessonPrice: near-universal coverage). No documented convention exists for when a field is expected to have one.
- Grid column counts (2 vs. 3) mixed with no documented convention — Stage 2 §2 of the presentation conventions doc now documents the intended convention; applying it form-by-form is Stage 4 work.
- Section count/complexity varies wildly for structurally similar "reference data" (Country: 6 sections; Academic sub-resources: 1 section each).
- Isolated hardcoded/non-semantic decoration: emoji in Select option labels (`GeneralSettingsPage`); hardcoded Tailwind `purple-*` classes on `SchedulerMonitorPage`; `theme.css`'s sortable drag-state colors not tracking the panel's actual `primary` (Amber) token; 4 hardcoded hex panel-accent colors in the Navigation Menu Builder (see item 2 above).
- Required-ness enforced only in `save()`/service logic, never reflected as `->required()` in the schema: `HomeworkReminderSettingsPage`'s "at least one offset" rule; `PromotionalCreditIssuances`' conditionally-required amount/currency fields.
- Analytics & Reporting's 7 report pages are filter-bar-only (no true forms) — their remaining P2 scope is limited to filter-bar visual consistency: Reset-button placement, "no permission" messaging consistency, and grid-fill/item-count consistency across the 7 pages. No form-redesign work applies to this domain.

## Confirmed during Stage 2 (investigation only, no fix) — Decision #9

**Which Payment Settings page is actually routed:** `PaymentSettingsNavigationPage` owns `/admin/payment-settings` (the pure nav-hub with four link buttons). The four real settings pages are routed at their own sub-slugs (`payment-settings/{bank-account,gateways,configuration,advanced}`), each extending the **abstract** `PaymentSettingsPage` base but overriding `content()` itself. Confirmed by reading source: the abstract base's own `content()` (`app/Filament/Pages/Settings/PaymentSettingsPage.php:203`, which includes a 6th `mark_production_reviewed` action `PaymentGatewayPage` doesn't have) is never reached by any routed page, since `PaymentGatewayPage::content()` (line 50) — and each of its three siblings — overrides it. **This confirms the Stage 1 audit's suspicion that the abstract base's `content()` is dead code.** Per Stage 2's explicit instruction, it has not been removed or refactored; that decision (reconcile the two action sets, or delete the unreachable one) needs its own separate approval.

## Not in this backlog

Items already fixed as part of Stage 2 (not functional/security issues — pure presentation infrastructure): "Create & create another" removal, the breadcrumb/heading/back-action/color conventions documented in [docs/architecture/admin-forms-presentation-conventions.md](../architecture/admin-forms-presentation-conventions.md). Analytics & Reporting's P2 items are tracked above in this backlog (not a separate document) since that domain has no true forms and stays out of this program's form-foundation work per your decision #10.
