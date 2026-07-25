# Phase 32 — Controlled Student-Instructor Messaging Implementation Audit

## Final decision: **GO** (one gap found and fixed during this audit)

---

## 0. Method note

Read-only verification followed by one targeted fix. Authoritative sources, in
priority order: `docs/SRS.md` §17.28-§17.48 (every messaging-linked section,
re-read in full against the shipped code, not against the Phase 32 prompt's own
paraphrase); `docs/SRS_Compliance_Audit.md` (GAP-017 = SRS-17-11, and the
messaging-report line SRS-19-25/§19.35); `docs/audits/phase-27a-next-srs-requirement-selection-audit.md`
(pre-implementation state — confirms GAP-017 was "Still open, unaffected" as of
that audit, i.e. this is the first implementation pass); existing project
conventions (Support Case / Compliance / Booking domain patterns) and the Phase
32 focused test suite.

---

## 1. GAP-017 reconciled status

| Field | Phase 27A (pre-implementation) | This audit (post-Phase-32) |
|---|---|---|
| Status | Open, unaffected — "No `Conversation`/`Message` model found" | **Implemented** — `app/Messaging/`, `app/Models/{Conversation,Message,MessageReport,MessagingRestriction}.php` |
| SRS ref | SRS-17-11 (§17.28-36) | Same, confirmed against full text this audit |
| Evidence | `find app -iname "*Conversation*" -o -iname "*Message*"` returned only `SanitizesProviderMessages` (unrelated) | `conversations`/`messages`/`message_reports`/`messaging_restrictions` tables exist and are populated by `MessagingService`, the sole writer |

**SRS-19-25 (§19.35 "Messaging Report", Medium priority) — reconciliation of a claim in the Phase 32 final report:** that report speculated the phase brief's citation of "§19.35" for messaging reporting might be a typo. It is not. §19.35 is the Reports & Analytics chapter's Functional Requirements section, and it contains a literal `### Messaging Report` bullet: *"The system shall report controlled messaging usage, reported messages, and messaging restrictions."* `MessagingReportingService` + `MessagingStatsWidget` implement exactly this (conversation/message counts, flagged-message count, total/pending report counts, active-restriction count). **Corrected finding: no typo, requirement satisfied as originally cited.**

---

## 2. Gap found and fixed: demo-only relationships were incorrectly granting eligibility

**SRS text:** §17.28 "Controlled Messaging Philosophy" — *"Demo-only messaging may be restricted or limited."* This is a direct textual instruction, not merely descriptive scope, and it overrides the Phase 32 prompt's own paraphrase (which never mentioned demo lessons at all).

**What was wrong:** `MessagingEligibilityService::findEligibleContext()`'s first check (confirmed + `BookingPaymentStatus::Paid` booking) already correctly excluded free demo bookings, since a demo booking's `payment_status` is `NotRequired`, never `Paid`. However, the third and fourth checks — "upcoming lesson" and "lesson completed within the configured window" — queried `Lesson` by status/timing alone, with **no constraint on the underlying booking's type being paid**. A student with only a scheduled or recently-completed **free demo lesson** (no paid booking, no active learning plan) would have `isEligible()` return `true` purely from that demo lesson, directly contradicting §17.28.

**Fix applied** (`app/Messaging/Services/MessagingEligibilityService.php`): both the upcoming-lesson and recently-completed-lesson queries now add `->whereHas('booking.type', fn ($q) => $q->where('is_paid', true))`, reusing the existing `BookingType::is_paid` field (the same field `FreeDemoType`/`PaidOneToOneType` already use to distinguish demo from paid booking types elsewhere in the codebase — no new concept introduced).

**Verification:**
- New test `test_an_upcoming_demo_lesson_alone_is_not_eligible` — a student/instructor pair with only a scheduled free-demo lesson is correctly ineligible.
- New test `test_a_paid_upcoming_lesson_alongside_an_unrelated_demo_lesson_is_still_eligible` — confirms the fix is additive (a genuinely paid relationship elsewhere is unaffected).
- All 9 pre-existing `MessagingEligibilityTest` cases still pass unchanged (they all use paid fixtures already).

---

## 3. Full SRS checklist verified (§17.28-§17.46)

| SRS item | Priority | Status | Evidence |
|---|---|---|---|
| §17.28 messaging is controlled/contextual/policy-governed, no unrestricted pre-booking chat | — | ✅ | Eligibility gate on open + every send |
| §17.28 demo-only messaging restricted | — | ✅ **(fixed this audit)** | See §2 above |
| §17.29 eligibility sources (paid booking / active plan / upcoming lesson / completed-within-window) | — | ✅ | `MessagingEligibilityService::findEligibleContext()` |
| §17.29 disabling conditions (no relationship / booking cancelled / instructor or student suspended / admin disables) | — | ✅ | Re-checked fresh on every send (`assertCanSend()`); `MessagingRestriction` for admin-disable |
| §17.29 "abuse detected" disables messaging | — | ✅ (manual, not automatic — see exclusions) | `applyRestriction()` is the human-review action; compliance flag is evidence-only |
| §17.30 context linkage to Booking/Learning Plan | High (FR: "Message Context Linkage") | ✅ | `context_type`/`context_id`; lesson-sourced eligibility resolves to its Booking (an explicitly-permitted type) |
| §17.31 text messages, read status, optional attachments, history | — | ✅ | `Message.read_at`, `PreventsHardDeletion`, media collection |
| §17.32 restriction categories | — | ✅ (policy + reporting, not auto-block, per explicit "V1 may rely on policy, reporting, and admin review") | `LeakageDetector` (technical signals) + `MessageReportReason` (7 categories, exact match to §17.35) |
| §17.33 leakage prevention measures | — | ✅ | Platform-only messaging, eligibility gate, report feature, admin moderation all present; body never mutated |
| §17.34 attachment types/restrictions | — | ✅ | PDF/JPEG/PNG/WebP allow-list, 5MB cap, executables/unknown types rejected |
| §17.35 report reasons | High (FR) | ✅ exact match | `MessageReportReason`: OffPlatformSolicitation, AbuseOrHarassment, Spam, InappropriateContent, PaymentRequest, ContactSharing, Other |
| §17.36 view reported conversations / search flagged messages / review history / restrict / suspend / take policy action | — | ✅ (flagged-message filter added this audit) | `MessageReportsRelationManager`, `MessagesRelationManager` (+ new `TernaryFilter` on `flagged_leakage`), `applyRestriction`/`removeRestriction`, `reviewReport` |
| §17.36 "export conversation for investigation, where permitted" | — | **Deferred** (descriptive "may include" list, not an FR; not requested by the phase brief's "minimal reporting" scope) | Documented, not built |
| §17.37 communication audit (message reported, messaging disabled for user) | — | ✅ | `AuditTrailService` under `log_name = 'messaging'` |
| §17.37 "admin viewed reported conversation" | — | **Not logged** (view-only actions are not audited anywhere else in this codebase either — Invoice/SupportCase admin views aren't logged; consistent, not a regression) | — |
| §17.39 FR: Controlled Messaging (High), Eligibility Check (Critical), Context Linkage (High), History (High), Read Status (Medium), Reporting (High), Admin Review (High), Restriction (High) | — | ✅ all eight | See `docs/messaging.md` |
| §17.40 business rules (messaging-specific, 6 bullets) | — | ✅ all | — |
| §17.41 validation rules (messaging-specific, 5 bullets) | — | ✅ all | Eligibility check, context FK, attachment mimes/size, required report reason, required restriction reason |
| §17.42 "Controlled Student-Instructor Message" workflow | — | ✅ | `openOrFindConversation` → `send` → in-app notification |
| §17.42 "Message Report" workflow (incl. "Admin is notified") | — | ✅ | `message_reported` mapped in `NotificationMapper` |
| §17.43 exception handling (blocked send, flag + optional manual restrict) | — | ✅ | `MessagingException`, no automatic restriction |
| §17.44/§19.35 messaging reports | Medium | ✅ | See §1 correction above |
| §17.45 admin-configurable eligibility/attachment/report-reason rules | — | **Partially deferred** (only `post_lesson_window_days` and `attachments_enabled` are configurable; none of §17.45's bullets carry individual FR priority markers — same level of configurability Phase 31's Support Cases shipped with) | `MessagingSettings` |
| §17.46 acceptance criteria (messaging bullets) | — | ✅ all | — |

---

## 4. Confirmations

- One functional gap found (§17.28 demo-messaging exclusion) — fixed, tested, verified against the full existing suite.
- One documentation correction made (§19.35 citation was accurate, not a typo as the Phase 32 report speculated).
- One minor admin-capability gap closed opportunistically (flagged-message filter, §17.36).
- Export-conversation-for-investigation and per-view audit logging are documented deferrals, not silently dropped — neither is an FR-level requirement and neither has any precedent elsewhere in this codebase.
- No business logic outside `app/Messaging/*` and the one Filament relation manager was touched during this audit.
- **Tests run** (named, not swept): `composer test -- --filter="Messaging"` → **66/66 passing, 325 assertions** (2 new demo-eligibility tests added). Regression: `SupportCase|AccountMenuServiceTest|AccountLayoutTest|AdminNotificationTest|ReferFriendPageTest|InvoiceGenerationTest|InvoiceNumberSequenceTest|SuspiciousActivity|ComplianceMonitoring` → **152/152 passing, 419 assertions**.
- Pint clean on all changed files.
- No migration was needed for this audit's fix (query-level change only, no schema change).
- No full suite, no directory sweep, no `migrate:fresh`, no external provider contacted.

Stopping here per instructions.
