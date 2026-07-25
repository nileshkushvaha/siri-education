# Support & Dispute Case Management (Phase 31, GAP-016)

SRS Chapter 25. A centralized case system for student/instructor support requests and disputes, with staff assignment, status tracking, requester-visible replies, internal notes, escalation, and audit trail.

## Scope

Implements SRS §25.1-§25.48 for a single-case-record model. Explicitly out of scope for this phase (see the phase-31 report for the full exclusion list):

- General student–instructor messaging (GAP-017).
- Automatic refunds, wallet credits, payment reversals, booking changes — a case's resolution *links to* the module that performed the action, it never performs the action itself (SRS §25.41 "Financial resolutions must be executed through financial modules, not informal case notes").
- Case merging/duplicate-linking, `On Hold`/`Duplicate`/`Cancelled` statuses, attachments beyond the case-level `evidence` media collection, SLA automation, public case pages, email-to-ticket ingestion.

## Status lifecycle

`Open → InProgress → WaitingForUser ⇄ Escalated → Resolved → Closed`, plus `Resolved`/`Closed → Open` (reopen). This is a deliberately narrowed subset of the SRS §25.9 lifecycle (which also lists `Assigned`, `In Review`, `Reopened`, `Duplicate`, `Cancelled`, `On Hold`) — `Assigned`/`In Review` are folded into `InProgress`; `Escalated` is kept because §25.40 marks both "Case Status Lifecycle" and "Case Escalation" as explicit (Critical/High-priority) requirements. See `App\SupportCases\Enums\SupportCaseStatus` for the full transition table — `TransitionSupportCaseStatusAction` is the sole writer of the `status` column, and every transition is audit-logged.

Escalation requires a reason; resolution requires a resolution summary (SRS §25.42) — both enforced inside the transition action, not just at the UI layer.

## Architecture

```
Controller/FilamentAction → SupportCaseService → TransitionSupportCaseStatusAction → SupportCase model
```

- `App\SupportCases\Services\SupportCaseService` is the **only** writer of `support_cases`/`support_case_replies`. Controllers, Livewire components, and Filament actions never touch the models directly.
- `App\SupportCases\Support\LinkedRecordAuthorizer` enforces "a requester may link only records they are authorized to view" (§25.41) for the optional single polymorphic link (`linked_record_type`/`linked_record_id` — one of Booking, Lesson, BookingPayment, Invoice, WalletLedgerEntry, InstructorWithdrawalRequest, or a User representing an instructor). Admin-created cases skip the ownership check (staff may view any record).
- `App\SupportCases\Services\SupportCaseNumberAllocator` mirrors `InvoiceNumberAllocator` — an annually-scoped, row-locked sequence (`SUP-2026-000123`, configurable via `App\Settings\SupportCaseSettings`).
- Internal notes (`SupportCaseReplyVisibility::InternalNote`) are guarded in the service (`SupportCaseService::addReply()` throws unless the author has `AddInternalNote:SupportCase`) and filtered out of `SupportCase::requesterVisibleReplies()` — the only relation the frontend ever queries.
- `SupportCase`/`SupportCaseReply` are immutable history: `PreventsHardDeletion` (both), `PreventsUpdates` (replies only — a case's status/assignment legitimately mutates over its lifecycle).

## Notifications

Two independent pipelines, matching the existing booking/invoice convention:

- **Participant notifications** (`App\Listeners\SupportCases\SendSupportCaseNotifications`): case created (→ requester), assigned (→ assignee), reply added (→ whichever side didn't write it, requester-visible only), status changed to `WaitingForUser`/`Resolved`/`Closed`/reopened (→ requester or assignee). Every send is claimed through `NotificationIdempotencyGuard` before dispatch. All four `App\SupportCases\Events\*` events implement `ShouldDispatchAfterCommit`.
- **Admin notifications**: `AuditTrailService::logUser()` calls with `log_name = 'support_cases'` flow through the existing Activity Log pipeline (`ActivityCreated` → `NotifyAdminsOnActivity` → `NotificationMapper`). Only `case_created`, `case_escalated`, and `case_reopened` are mapped — everything else stays silent to avoid duplicate participant + admin notifications for the same event.

## Authorization

`App\Policies\SupportCasePolicy` — owner-or-permission, matching `InvoicePolicy`. "Owner" (requester) is `created_by`, or the subject `student_id`/`instructor_id` for an admin-created case. Permissions (Filament Shield naming, seeded by `SupportCasePermissionSeeder`, granted to `manager`): `ViewAny/View/Create/Assign/Reply/AddInternalNote/Escalate/Resolve/Close/Reopen:SupportCase`.

## Staff surface

`app/Filament/Resources/SupportCases/` — list/view/create (admin-operational cases only; student/instructor self-service creation is frontend-only), filters (type/category/priority/status/assignee/date), reference search, lifecycle row actions (assign, escalate, waiting-for-user, resolve, close, reopen), and a `RepliesRelationManager` for the reply/internal-note timeline. No edit page, no delete action of any kind.

## Frontend

Shared by both student and instructor audiences under `/dashboard/support-cases` (no separate instructor-only route group) — `App\Http\Controllers\Dashboard\SupportCaseController` derives `SupportCaseType` from the acting user's active workspace via the existing `FrontendPortalAudienceResolver` (the same dual-role resolution already used elsewhere in the portal). List is Livewire-backed (`App\Livewire\Frontend\SupportCases\SupportCaseList`); create/show/reply are plain Blade forms re-checking `SupportCasePolicy` on every request.
