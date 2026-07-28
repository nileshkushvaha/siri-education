# Admin Forms Production-Readiness Program — Stage 1 Audit

Read-only inventory of every admin form surface in the Filament v5.6 `/admin` panel. No code was modified to produce this document. Findings are synthesized from nine parallel domain audits (one per Stage 4 domain grouping) plus direct verification (panel config, test inventory, live baseline screenshots against the running dev server).

Domains covered: People & Access · Academics & Reference Data · Operations & Scheduling · Finance, Wallets, Earnings & Payouts · Growth & Referrals · Content & Communication · Quality & Compliance · Analytics & Reporting · System & Settings.

---

## 1. Total forms found

**≈205 distinct form surfaces**, counting every full-page create/edit form, standalone settings page, modal/table-action/header-action form, and relation-manager form that actually accepts input. Pure list/table pages, read-only infolist ("View") pages, and Livewire filter-bar report pages with no Filament `Form` schema are inventoried separately as informational-only surfaces (not counted here) — see each domain report's "no-form" section.

| Domain | Form surfaces inventoried |
|---|---|
| People & Access | 39 |
| Operations & Scheduling | 33 |
| Finance, Wallets, Earnings & Payouts | 35 |
| Growth & Referrals | 24 |
| Quality & Compliance | 26 |
| Academics & Reference Data | 16 |
| Content & Communication | 15 |
| System & Settings | 8 (row 4 alone bundles the Navigation Menu Builder's multi-panel tree/slide-over UI) |
| Analytics & Reporting | 0 true forms (7 report pages, all filter-bar-only — see below) |

Analytics & Reporting is the one domain where the audit confirmed **zero** create/edit/action forms exist — every page is a read-only, Livewire-filtered report. It is included in the total above as 0, not 7, to keep the "form" count honest.

## 2. Forms by type

Approximate counts, tallied from each domain's inventory using the categories below. Some rows document a create+edit pair as a single line item in the source report; those are counted once per type here (not doubled), so these numbers are directionally accurate rather than exact to the integer.

| Type | Approx. count | Notes |
|---|---|---|
| Modal / table-action / header-action forms | ~75 | By far the largest category — the dominant UI shape across Operations, Finance, Growth, Quality: a `Textarea` "reason" field + `requiresConfirmation()` + a service-delegated try/catch. Roughly another 15–20 "immediate, no-field" confirmation-only actions exist alongside these and are not counted as forms. |
| Full-page Create forms | ~30 | Concentrated in Academics & Reference Data (11), Content & Communication (8), People & Access (4), Operations (3), Growth (2), Quality (1), Finance (1 — `Currency` is the *only* conventional create/edit resource in the entire Finance domain by deliberate design). |
| Full-page Edit forms | ~30 | Mirrors the Create count (nearly every Create-capable resource has a matching Edit page); `StudentLearningPlan`'s Edit page is a notable non-form exception — see §6. |
| Standalone settings pages | ~22 | People & Access (8: AdminProfile, ChangePassword, 6 Security pages) · Finance (6: 4 Payment sub-pages + InstructorEarningSettings + RazorpayXPayoutSettings) · Quality (3) · System & Settings (4) · Content & Communication (1: SEO). All but the Security pages share the `HasSettingsAccess` + `LogsSettingsUpdates` trait pair. |
| Relation-manager forms (actual create/edit/mutate capability) | ~5 | Education/Experience (People & Access, full CRUD), Wallet ledger-entry reversal, Conversation message-report review, SupportCase replies/internal notes. The other ~10 relation-managers in the app are read-only tables with an intentionally empty `form()`. |
| Login form | 1 | `Filament\Auth\Pages\Login`, overridden only to route frontend-only users away via `PortalResolver`. |
| Pure report/filter-bar pages (no Form schema at all) | ~18 | 7 in Analytics & Reporting, 5 Finance report pages, ~6 Operations monitor/report pages (Scheduler/Queue/Cache monitors, Booking reports). Zero of these have a create/edit/action form; several have confirmation-only actions with no input fields. |

## 3. Shared patterns already available

These exist today and should be the starting point for Stage 2 standards — not reinvented:

- **`HasCentralizedNavigation`** (`app/Filament/Navigation/Concerns/HasCentralizedNavigation.php`) — used by essentially every Resource and Page in the panel. Sidebar placement is entirely driven by `NavigationRegistry`; a class's own `$navigationGroup`/`$navigationLabel`/`$navigationSort` properties are dead once this trait is present. Confirmed in every domain.
- **`HasSettingsAccess`** (`app/Filament/Pages/Settings/HasSettingsAccess.php`) — standard `canAccess()`/`shouldRegisterNavigation()` gate (super_admin or `settings.*` permission) used by most settings pages. Two pages opt out deliberately: `ReviewQualitySettingsPage` (own two-tier `.view`/`.update` permission pair) and the 6 Security pages (own `HasSecurityAccess` variant, per-page permission string).
- **`LogsSettingsUpdates`** (`app/Filament/Pages/Settings/LogsSettingsUpdates.php`) — the app-wide save+audit contract for settings: `snapshotSettings()` forces full hydration, `saveSettingsWithAudit()` wraps mutate+save+audit in one `DB::transaction()`, forgets the scoped container instance on failure, reports (never surfaces) exceptions, and `logSettingsUpdate()` diffs before/after while redacting `FINANCIAL_IDENTIFIER_FIELDS` and secret-like field names. This is the correct Audit Trail integration point and is already used correctly everywhere it applies.
- **Reason-gated lifecycle action shape** — `requiresConfirmation()` + `->form([Textarea::make('reason')])` + `->authorize(...)` + service delegation + try/catch → Filament `Notification`, repeated ~75 times across Operations, Finance, Growth, Quality. This is a real, consistent architectural pattern; it is not yet extracted into a shared trait/component (see `reasonField()`, duplicated verbatim 5× in Growth alone).
- **Service-delegation discipline** — confirmed with zero exceptions across every mutating action in every audited domain: no raw Eloquent writes from the Filament layer, matching the CLAUDE.md Controller→FormRequest→Service→Repository→Model rule.
- **Semantic color usage** — `->color('success'|'warning'|'danger'|'gray'|'info')` (or an enum's own `->color()`) is used almost everywhere; hardcoded hex/RGB is the exception, not the rule, and every instance found is listed in §4.
- **Soft-delete action triad** (`DeleteAction`/`RestoreAction`/`ForceDeleteAction`) — standard across most reference-data and content resources.
- **`ExportsReportCsv`** and **`HasFinancialReportFilters`** (`app/Filament/Pages/Concerns/`) — standardize CSV export and period/currency/instructor filter-bar state respectively, but are under-adopted: none of the 7 Analytics & Reporting pages use `HasFinancialReportFilters` (each reimplements the same filter/reset logic inline).
- **Panel-wide `maxContentWidth(Width::Full)`** (`AdminPanelProvider.php`) — an explicit, documented global override already in place. No resource or page anywhere opts out of it. Stage 2's "full content width via framework-native APIs" requirement is therefore **already satisfied at the panel level** — it does not need to be re-applied per page.
- **`AvailabilityImpactConfirmation`** — a reusable session-token confirm-then-repeat helper shared between Teacher Availability and Teacher Leave for "this affects N scheduled lessons" warnings.

## 4. Inconsistencies (cross-domain themes, priority-tagged)

Grouped by theme rather than repeated per domain, since Stage 2 needs one decision per theme, not nine.

**P0 — broken / unsafe / misleading**

- **Wallet `adjustment` action** (`Wallets\Pages\ViewWallet`) — a direct manual credit/debit of a wallet balance, the single most consequential action in the Finance domain, has **no `requiresConfirmation()`** and is colored neutral `gray`, while its sibling actions on the same page (freeze/unfreeze/close) are all confirmation-gated and semantically colored. Recommend treating this as an urgent fix candidate independent of the Stage 2–6 sequencing.
- **Navigation Menu Builder** (`MenuBuilder` Livewire component behind `EditNavigation`) — `deleteItem()` has no confirmation step at all (every other destructive action in the app confirms), and the component loads SortableJS from a public `cdn.jsdelivr.net` script tag at runtime, directly contradicting the panel's own documented practice of self-hosting fonts specifically to avoid third-party CDN dependencies in the authenticated admin panel.
- **`PageForm`/`PostForm` "Advanced Layout" Placeholder** — hardcoded inline HTML with literal hex colors (`#f59e0b`, `#d1d5db`, `#fff`) and inline `style=` attributes, duplicated verbatim across both files. Bypasses Filament's theme/dark-mode system entirely; will render broken/unthemed in dark mode.
- No domain's static review turned up broken/inaccessible forms beyond the above three — every other flagged issue is a P1 or lower consistency/UX gap, not a functional break.

**P1 — inconsistent actions, missing navigation, mobile risk**

- **Reason-field requiredness is inconsistent on equivalent-severity actions**, both within and across domains: Booking's `cancel` (row + bulk) and Lesson's `cancel` leave `reason` optional; Booking's `archive`/`restore`, Reconciliation's `resolve`, and most Growth/Quality reject/dismiss actions require it. A cancellation can currently be recorded with zero audit rationale where an archive cannot.
- **Confirmation-modal usage is inconsistent on danger-colored or terminal actions**: Lesson's `dispute` is `danger`-colored with no `requiresConfirmation()`; `ReportQueueWidget::uphold`/`AlertQueueWidget::resolve` (both of which can hide/reject/archive a review or close a compliance alert) have neither confirmation nor a `modalDescription`, while structurally identical siblings elsewhere do both.
- **"Terminal negative outcome" actions colored neutral `gray` instead of `warning`/`danger`**: Booking's `no_show`, Lesson's `finalize_no_show`, and both Referral/Promotional-Credit campaigns' `archive` actions are all explicitly documented as irreversible in their own code comments yet styled identically to routine/neutral actions.
- **`modalDescription()` coverage is inconsistent between structurally parallel sibling actions** on the same table — e.g. Referral Campaign's `complete`/`archive` have one, `activate`/`pause`/`resume` don't; the mirrored Promotional Credit Campaign table has the opposite gap on `complete`.
- **`EditInstructorOnboarding` can surface up to 11 header actions** with no `ActionGroup` collapsing — a real overflow/crowding risk on mobile and even narrow desktop viewports.
- **Settings-page breadcrumbs are inconsistent**: `PaymentSettingsPage` (and its 4 routed children) explicitly return `[]`; `InstructorEarningSettingsPage`/`RazorpayXPayoutSettingsPage` have no override at all (may fall back to Filament defaults); all 4 System & Settings pages hardcode the *same* mid-crumb (`/admin/settings/general`) regardless of which page is actually open, so "Settings" in the breadcrumb always routes to General.
- **Permission-gating UX is inconsistent between otherwise-parallel settings pages**: `RazorpayXPayoutSettingsPage` hides each sensitive action via `->visible()` for users lacking the specific permission; `InstructorEarningSettingsPage`'s equivalent narrower permission is invisible in the form and only rejected inside `save()` after the fact; `ReviewQualitySettingsPage` implements its own two-tier `.view`/`.update` pair while its two settings-page siblings (Homework, Demo Conversion) use the single generic `settings.*` gate with no re-check inside `save()`.
- **"Create & create another" is present by unmodified Filament default on every single Create page in the panel** (confirmed across all 9 domains, zero exceptions) — this is the exact behavior Stage 2 intends to remove centrally; the audit confirms there is no per-resource variance to reconcile first.
- **Submit-button/action wording for the conceptually identical "save" action varies** across the panel: "Save Settings" (Security pages), default "Save changes" (resources/AdminProfile), "Update Password" (ChangePassword), "Save marketplace settings" (InstructorOnboarding review), "Save {Domain} Settings" (Payment/Finance/Quality/System settings pages).
- **Silent business-rule overrides with no UI disclosure**: Lesson's admin `mark_attendance`/`complete` actions pass `override: true` to the service, bypassing no-show-grace-period/attendance/early-completion guards, with no label, help text, or modal copy indicating an override is happening.
- **`SubjectTopicForm` diverges functionally, not just cosmetically, from its four sibling Academic forms**: siblings hide the slug on create and validate uniqueness via `GeneratePageSlugAction`; `SubjectTopicForm` shows the slug field and derives it with plain `Str::slug()` with no DB-backed uniqueness check.
- **`StudentLearningPlan`'s Edit page form is entirely non-functional as a form** — every field is `disabledOn('edit')->dehydrated(false)`; all real mutation happens through 6 header-action modals. A presentation-only pass that treats the base Edit form as "the form" would miss the actual editing surface.
- **`PaymentGatewayPage` and the abstract `PaymentSettingsPage` define two near-duplicate action sets** (validate/test/generate-webhook/reset-credentials) that have already begun to diverge (one has a 6th action the other lacks); `PaymentSettingsPage` itself appears unrouted — likely dead code masquerading as a live shared base.

**P2 — inconsistent headings, spacing, widths, help text, colors**

- **Help-text (`helperText()`) coverage is uneven inside nearly every form audited**, in every domain — this is the single most common finding across all nine reports (e.g. FAQ Category: 1 of 5 fields; PostCategory/Tag: 0 of any field; Country/StudentLessonPrice: near-universal coverage). No documented convention exists for when a field is expected to have one.
- **Grid column counts (2 vs 3) are mixed with no documented convention**, both within single pages and across sibling pages solving the same layout problem.
- **Section count/complexity varies wildly for structurally similar "reference data"**: Country uses 6 sections each with a `->description()` subheading; every Academic sub-resource uses exactly 1 section with none. FAQ Category (the Stage 3 pilot target) is the flattest pattern in the app — a single Section, no Grid, no sub-grouping between "content" and "meta" fields.
- **A handful of isolated hardcoded/non-semantic decoration**: emoji directly in Select option labels (GeneralSettingsPage); hardcoded Tailwind `purple-*` classes mixed with Filament's semantic tokens (`SchedulerMonitorPage` blade); `theme.css`'s sortable drag/ghost/chosen states hardcoded to an indigo-ish RGB that does not track the panel's actual configured `primary` (Amber) token; 4 hardcoded hex panel-accent colors in the Navigation Menu Builder.
- **Required-ness enforced only in `save()`/service logic, never reflected as `->required()` in the schema**: `HomeworkReminderSettingsPage`'s "at least one offset" rule on its `TagsInput`; `PromotionalCreditIssuances`' conditionally-required amount/currency fields (`requiredIf`), which may not visually indicate required-ness the way an admin would expect.

**P3 — minor polish**

- Duplicated presentation logic that should be a shared trait/component rather than copy-paste: `reasonField()` (5× in Growth & Referrals), the 6 Security pages' identical page scaffold, the `[Delete, ForceDelete, Restore]` header-action triad (6+ times in Content & Communication alone), the PageForm/PostForm hex-color block (already flagged P0 above for its color/dark-mode impact, but also simply duplicated code).
- Header-action ordering/composition has no consistent convention across otherwise-similar Edit pages.
- A few narrow-visibility table-actions (Referral reward `retry_credit`/`complete_reversal`, Referral attribution `correct`) only appear for records in specific rare statuses that may not exist in current seed data.

## 5. Recommended global defaults

Grounded in what the audit found actually exists today, not a theoretical ideal:

1. **Content width**: no change needed. `AdminPanelProvider::maxContentWidth(Width::Full)` already applies panel-wide; Stage 2 should confirm no page-level override is ever added, not introduce a new mechanism.
2. **Centralize "Create & create another" removal** at the panel or a shared base-page level (e.g. a trait applied via a service-provider macro on `CreateRecord`), since every Create page in the panel currently has it via unmodified Filament default — one change point, ~30 pages affected.
3. **Formalize the existing reason-gated lifecycle-action pattern into a shared trait/factory** (e.g. `reasonField()` → a shared `Concerns\HasReasonField` or a `LifecycleAction::make()` factory) that bakes in: required reason by default (with an explicit opt-out), `requiresConfirmation()` + `modalDescription()` required for any `danger`/`warning`-colored or explicitly-irreversible action, and a documented color-severity tier (routine success/info vs. terminal/irreversible = `warning` or `danger`, never `gray`).
4. **Standardize settings-page breadcrumbs and permission-gating** on the pattern already used correctly by `RazorpayXPayoutSettingsPage` (per-action `->visible()` gating) and `PaymentSettingsPage` (explicit, correct breadcrumb per sub-page) — apply both consistently to `InstructorEarningSettingsPage`, `ReviewQualitySettingsPage`/Homework/DemoConversion, and the 4 System & Settings pages.
5. **Standardize submit-button wording** to one convention (e.g. always "Save {X}" for settings pages, Filament default "Create"/"Save changes" for resources) rather than the five variants currently in use.
6. **Add `ActionGroup` collapsing** once a page's header actions exceed a fixed threshold (recommend 4–5), starting with `EditInstructorOnboarding`'s 11.
7. **Adopt a documented help-text policy** (e.g.: every required field with a non-obvious format/constraint gets `helperText()`; purely self-explanatory fields don't) rather than leaving coverage to per-author discretion.
8. **Treat the FAQ-Category flat-Section pattern as the "simple reference data" default**, and the Tabs+Section pattern as the "rich content" default — Stage 2 should pick one of these two as the baseline for each form's classification rather than leaving Section-count/Grid-usage ad hoc per resource (see §6 for which resources genuinely need the heavier pattern).

## 6. Exceptions requiring special treatment

- **`StudentLearningPlan` Edit page** — do not redesign as an ordinary edit form; its 6 header-action modals *are* the real form. Any Stage 4 pass here should focus on those modals, not the disabled base form.
- **`EditInstructorOnboarding`** — the review page's `mount()` unconditionally writes an `instructor_document_viewed` audit-log entry whenever the viewer can see documents. This is a genuine side effect of merely opening the page (relevant to future screenshot passes, not just to redesign work), and its 11 header actions need a bespoke grouping decision rather than the generic global default.
- **Navigation Menu Builder** (`MenuBuilder` Livewire + its Blade view) — not a native Filament schema form; it's a hand-built Alpine/SortableJS tree editor with a slide-over. The P0 CDN-dependency and missing-confirmation issues here should likely be fixed as their own small task before or independent of the general Stage 2–4 rollout, since generic "form standard" changes won't reach this component at all.
- **`Wallets\Pages\ViewWallet`'s `adjustment` action** — flagged P0 above; recommend fixing ahead of the normal batch sequencing given its risk profile (unguarded direct ledger mutation).
- **`PaymentGatewayPage`/`PaymentSettingsPage` duplicate action sets** — needs a reconciliation decision (which one is live, whether the other is dead code to remove) before any visual work touches either file, since Stage 2/4 changes could otherwise be made to a page nothing actually routes to.
- **Analytics & Reporting domain** — has no true forms at all. Its Stage 4/5 scope should be limited to filter-bar visual consistency (grid item counts, Reset-button placement, "no permission" messaging — all flagged P2 above), not form redesign in the create/edit sense.
- **Country's create/edit form** is disproportionately larger (6 sections) than every other "reference data" form in the app, including its own domain siblings — it will not visually match a FAQ-Category-style simple form even after the pilot's conventions are applied; treat it as its own case rather than assuming pilot conventions generalize to it unchanged.
- **A cluster of ~15 forms across Academics, Content, and Finance domains already share FaqCategory's exact flat-Section/no-Grid shape** (AcademicCategory, AcademicLevel, SkillLevel, Subject, PostCategory, Tag, Language, State, and others). Worth an explicit decision before Stage 4 batching: if the FAQ Category pilot's outcome is designed to generalize, these can likely be rolled out as one low-risk batch rather than re-litigated individually.

## 7. Proposed implementation batches

The Stage 4 spec already defines a 9-domain batch order (People & access · Academics · Operations and scheduling · Finance/wallets/earnings/payouts · Growth and referrals · Content and communication · Quality and compliance · Analytics and reporting · System and settings). Nothing found in this audit contradicts that grouping. Two refinements worth the user's explicit sign-off before Stage 4 begins:

- **A Batch 0, before any domain rollout**: extract the cross-cutting shared components identified in §5 (reason-field/lifecycle-action trait, settings breadcrumb/permission-gating fix, "Create & create another" removal mechanism, `ActionGroup` collapsing threshold). Doing this once, centrally, avoids re-deriving it independently in each of the 9 domain batches.
- **Risk-based resequencing within Stage 4**: consider moving Finance/Wallets/Earnings/Payouts earlier (it contains the one live safety-relevant P0, the wallet adjustment action) and Analytics & Reporting to last (zero true forms, lowest-risk, filter-bar polish only) — a suggestion for the user to accept or keep the spec's original order.

No batch beyond what Stage 4 already specifies is proposed here; Stage 3's pilot (FAQ Category + 4 more forms) should validate the Batch-0 shared components before they're applied panel-wide.

## 8. Baseline screenshots of representative forms

Captured directly against the running dev server (`http://127.0.0.1:8000`, `composer dev` stack already up) using Playwright's already-cached CLI driving the system's installed Google Chrome — no new package was added to the project (nothing changed in `package.json`/`composer.json`/`node_modules`) and nothing was installed for this pass beyond what was already resolvable from npm's local cache.

Logged in as the seeded `super_admin` (`admin@mailinator.com`), which the app's `Gate::before()` bypasses for all permission checks, guaranteeing every gated section renders instead of showing a permission fallback.

20 screenshots (10 pages × desktop 1440×900 + mobile 390×844), covering:

- FAQ Category create + edit (`/admin/faq/faq-categories/create`, `/1/edit`) — the Stage 3 pilot target; confirms the "single flat Section, no Grid" structure exactly as reported.
- Users create (`/admin/users/create`) — the heaviest People & Access form (7-tab layout).
- Country create (`/admin/countries/create`) — the heaviest reference-data form (6 sections); mobile screenshot confirms clean single-column stacking.
- Instructor Onboarding list (`/admin/instructor-onboarding`).
- Navigation Menu list (`/admin/navigations`).
- Settings → General (`/admin/settings/general`).
- Security → Session (`/admin/security/session`) — confirms the "Force Logout All Devices" destructive action's correct danger styling + confirmation, in contrast to the Wallet adjustment gap flagged in §4.
- Reviews & Quality dashboard (`/admin/reports/reviews-quality`).
- Reporting Hub (`/admin/reporting-hub`).

One page could not be captured automatically: the bare `/admin` Dashboard aborted navigation (`net::ERR_ABORTED`), likely a Livewire client-side navigation quirk rather than an app bug — it is not a form page, so this does not block Stage 1, but is noted as a gap in the baseline set (see §10).

Viewable gallery (filterable by viewport, click to enlarge): https://claude.ai/code/artifact/03809c18-8330-479d-b50f-cced41baeb2b — private by default; share it from the artifact page if others need access. The underlying PNGs live only in this session's scratchpad, not the repo, per "no code changes during Stage 1."

## 9. Relevant existing tests

~89 test files touch Filament admin surfaces:

- `tests/Feature/Filament/**` (21 files) — direct resource/page coverage: `AcademicResourceCrudTest`, `BookingAdminPanelTest`, `CountryFeatureFlagsAdminTest`, `InstructorOnboardingResourceTest`, `RoleReplicateActionTest`, `SuperAdminProtectionEditUserTest`/`RolesTest`/`UsersTableTest`, `UserResourceProfileTest`, `ReviewModerationWidgetActionsTest`, `ReviewReportWidgetActionsTest`, `ReviewTagResourceTest`, `RedirectFilamentTest`, `NotificationTemplateFilamentTest`, `OperationalAlertFilamentTest`, and others.
- `tests/Feature/Settings/**` (18 files) — one per settings page (`SeoSettingsTest`, `MailSettingsLoadUpdateTest`, `MeetingSettingsPageTest`, `PaymentSettingsAtomicityTest`/`AuditTest`, `RazorpayXPayoutSettingsAuditTest`, `InstructorEarningSettingsAuditTest`, `ReviewQualitySettingsPageTest`, `HomeworkReminderSettingsPageTest`, `DemoConversionIncentiveSettingsPageTest`, `PlatformSettingsFeatureFlagsTest`, `SettingsAuditArchitectureTest`, etc.).
- `tests/Feature/Security/**` (7 files) — one per Security page (`AccountProtectionSettingsTest`, `AuthenticationSettingsTest`, `LoginSecuritySettingsTest`, `PasswordPolicySettingsTest`, `RegistrationSettingsTest`, `SessionSettingsTest`, plus `IdleSessionTimeoutTest`/`ForcePasswordChangeTest`/`LoginHistoryTest`).
- `tests/Feature/Reporting/**` (9 files) — one per Analytics & Reporting page plus export/hub coverage.
- `tests/Feature/Roles/**` (6 files) — role/permission form + audit-trail behavior.
- Domain-specific Filament tests scattered elsewhere: `Compliance/SuspiciousActivityFlagFilamentTest`, `Earnings/InstructorCompensationFilamentTest`, `Referral/ReferralOperationsFilamentTest`/`ReferralCampaignAdminTest`, `Instructor/InstructorOnboardingWizardTest`/`InstructorForceApproveOverrideTest`, `Admin/QueueMonitorFailedJobsTableTest`/`CacheManagerPageTest`/`SchedulerMonitorTest`/`PulseNavigationTest`, `Booking/PaymentGatewaySettingsAdminTest`, `Student/LearningPlanHardeningTest`/`StudentAdminTabTest`, `ActivityLogResourceTest`.

None of these were read in depth for this Stage 1 pass (that's Stage 2+ work once actual changes are proposed), but their existence confirms every domain has some automated coverage of its admin forms' functional behavior — presentation-only changes in later stages must keep these passing, and a few (e.g. `RoleReplicateActionTest`, `SuperAdminProtectionUsersTableTest`) assert on specific Filament component structure closely enough that they're worth checking early in Stage 3's pilot for brittleness against purely visual changes.

## 10. Risks and unresolved questions

- **No checked-in browser-automation tooling.** This audit's screenshots were captured using Playwright's already-cached npx package driving the system's pre-installed Google Chrome, without adding anything to the project. That path is not reproducible by anyone else (or by CI) without the same local cache/Chrome install. Decide before Stage 6 (final re-audit) whether to add a proper `devDependency` + downloaded browser (a real dependency change, outside Stage 1's boundary) or keep using this kind of ad hoc, session-local approach each time.
- **Real financial/PII data exposure risk for future screenshot passes.** Multiple domains (Finance/Wallets/Payouts, Growth & Referrals, People & Access) explicitly flagged that their forms render real bank account numbers (when decrypted), real wallet balances, and real compensation amounts. Any Stage 2/3/6 screenshot pass must use seeded/placeholder records — never a production data snapshot.
- **Some table-actions require rare-status fixtures to screenshot at all** (Referral reward `retry_credit`/`complete_reversal`, Referral attribution `correct` on a zero-reward record). Producing that seed data is itself a (test-only) code change — confirm this is acceptable within whichever stage attempts to screenshot those specific actions.
- **`PaymentGatewayPage`/`PaymentSettingsPage` look like live-vs-dead duplicate code**, not just a presentation inconsistency. Confirm which is actually routed before any redesign work touches either, since Stage 1's global safety rules forbid deleting/renaming resources without that being a deliberate, separate decision.
- **The bare `/admin` Dashboard could not be captured automatically** in this pass (client-side navigation aborted the request). Not a form page and not blocking, but flagged so it isn't mistaken for a deliberate omission.
- **Whether the FAQ Category pilot's outcome is intended to generalize** to the ~15 other forms sharing its exact flat-Section shape (see §6) is a scoping question worth answering before Stage 4 batching is finalized — it could substantially shrink the work in Academics/Content/Finance's simple-CRUD batches if so.
- **`InstructorOnboarding` review page writes a real audit-log row every time it's opened** (by design, for compliance — not a bug), which means repeated screenshot passes through it will add real rows to the activity log in whatever database they're run against. Worth the user's awareness, not necessarily a blocker.

---

No code was modified to produce this document. Stopping here per Stage 1's instruction — awaiting approval before Stage 2 (establishing the shared standards) begins.
