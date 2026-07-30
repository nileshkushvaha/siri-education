# Controlled Student–Instructor Messaging

SRS §17.28-§17.37. Secure in-platform messaging between a student and instructor, enabled only when a valid booking or learning relationship exists, with leakage-prevention flagging, reporting, and admin oversight.

## Scope

Implements SRS §17.28-§17.37 for a single 1:1 conversation model. Explicitly out of scope: general/social chat, group conversations, live typing/presence/WebSockets, message editing or hard deletion, voice/video calls, external messaging providers, AI moderation, automatic account suspension. Support-case communication stays entirely separate — this module only adds an optional `Message` link *type* to `SupportCaseCategory`/`LinkedRecordAuthorizer` so a staff-created case can reference a reported message, never the reverse.

## Eligibility (SRS §17.29)

`App\Messaging\Services\MessagingEligibilityService::isEligible()` is the single source of truth, recomputed **fresh on every send** — never trusted from conversation-open time (requirement #4):

1. Lifecycle check: neither the student (`StudentStatus::blocksAccess()`) nor the instructor (`InstructorStatus::Suspended`/`Archived`) may be blocked.
2. Relationship check — the first of, in priority order:
   - a confirmed (`BookingStatus::Confirmed`) and paid (`BookingPaymentStatus::Paid`) booking;
   - an active `StudentLearningPlan`;
   - an upcoming lesson whose booking type is paid (resolves to its owning booking);
   - a lesson completed within the configurable `MessagingSettings::post_lesson_window_days` (default 7) window, whose booking type is paid (resolves to its owning booking).

§17.28 "Demo-only messaging may be restricted or limited": the lesson-derived checks explicitly require `booking.type.is_paid = true` (the same field `FreeDemoType`/`PaidOneToOneType` already use elsewhere) — a free demo lesson alone, with no other paid booking or active plan, never grants eligibility. The original implementation only excluded demo bookings from the direct "confirmed paid booking" check, not from the lesson-derived checks — since fixed.

Conversation context is deliberately narrowed to `Booking` or `StudentLearningPlan` only (§17.30's broader context list — Homework/Lesson/Support-case — is not implemented as a separate anchor this phase); a qualifying lesson resolves to its booking rather than becoming its own context.

## Schema and authoritative service

```
conversations (student_id, instructor_id, context_type, context_id, status, opened_by, last_message_at, closed_at, closed_by)
  unique(student_id, instructor_id, context_type, context_id)
messages (conversation_id, sender_id, body, sent_at, read_at, flagged_leakage, flagged_leakage_reasons)
message_reports (message_id, reporter_id, reason, details, status, reviewed_at, reviewed_by, review_notes)
messaging_restrictions (user_id, applied_by, reason, applied_at, lifted_at, lifted_by, lifted_reason)
```

`App\Messaging\Services\MessagingService` is the **only** writer of all four tables — controllers, Livewire, and Filament actions never write directly. `Conversation`/`Message`/`MessageReport`/`MessagingRestriction` all use `PreventsHardDeletion` (never physically deleted); `Message` deliberately has **no** `PreventsUpdates` guard (unlike `SupportCaseReply`) because `markRead()` legitimately mutates `read_at` — `body` immutability is a service-boundary discipline (no route/action ever writes it after creation), not a blanket model guard.

`ConversationStatus` is `Active` / `Closed` / `Restricted`. `Restricted` is never set directly — it is a system-derived reflection of an active `MessagingRestriction` on either participant, applied/lifted in bulk by `MessagingService::applyRestriction()`/`removeRestriction()` (removal only reactivates a conversation if the *other* participant isn't independently still restricted).

## Privacy / leakage controls (SRS §17.32-§17.33)

`App\Messaging\Support\LeakageDetector` is a pure, deterministic function — regex/keyword matching for email addresses, phone numbers, external links (any host other than `config('app.url')`), and off-platform-service keywords (WhatsApp, Telegram, payment apps, etc.). It **never blocks a send and never mutates `body`** (requirement #6) — a flagged message is stored with `flagged_leakage`/`flagged_leakage_reasons` set, visible to admins via the Conversations resource's message-history relation manager. No AI, nothing automated beyond this deterministic flag.

## Reporting and restriction workflow (SRS §17.35-§17.36)

- Any participant may report a message with a required reason (`MessageReportReason`); duplicate reports from the same reporter on the same message are idempotent (unique `(message_id, reporter_id)`).
- Authorized staff (`ReviewReport:Messaging` permission) review reports via `MessagingService::reviewReport()` from the `MessageReportsRelationManager` on the admin Conversations resource.
- Authorized staff (`Restrict:Messaging` permission) apply/remove a **user-level** restriction with a mandatory reason — both enforced inside the service itself (defense in depth beyond the Policy), not just at the UI layer.
- **Compliance integration** (requirement #7): `App\Compliance\Rules\RepeatedMessageReportsRule` (new `SuspiciousActivityRuleCode::RepeatedMessageReports` case) reuses the existing rule-based compliance engine — a threshold of reports against the same sender within a rolling window raises a `SuspiciousActivityFlag` for human review. This is evidence only; it never restricts messaging automatically (verified by test).
- Optional cross-reference into Support Cases: `SupportCaseCategory::Messaging` and `LinkedRecordAuthorizer`'s allow-list now include `Message` (ownership check = conversation participant), so staff can open a formal support case that links to a reported message — support-case messaging itself remains untouched.

## Notifications (SRS §17.42, requirement #8)

`App\Listeners\Messaging\SendMessagingNotifications::handleMessageSent()` — queued, `ShouldDispatchAfterCommit`, claimed through the existing `NotificationIdempotencyGuard`. Re-checks `read_at` at dispatch time and skips silently if the recipient already read the message before the job ran ("sent only for new unread messages"). The email/database notification never includes the message body — a generic "new message from X" with a link back into the platform. Admin awareness of a reported message flows separately through the existing Activity Log pipeline (`NotificationMapper` extended for `log_name = 'messaging'`, `event = 'message_reported'`), matching every other domain in this codebase — never a duplicate participant+admin notification for the same event.

## Reporting (SRS §17.44, requirement #10)

`App\Messaging\Services\MessagingReportingService` — bounded `count()` aggregates only (total conversations, total messages, flagged-message count, total/pending report counts, active restriction count), surfaced via a permission-controlled `MessagingStatsWidget` on the admin Conversations list page. This is SRS §19.35's literal `### Messaging Report` FR bullet ("The system shall report controlled messaging usage, reported messages, and messaging restrictions") — confirmed correct against the SRS text — not a typo.

## Frontend

Shared by both audiences under `/dashboard/messages` (no separate instructor-only route group) — `App\Http\Controllers\Dashboard\MessagingController` mirrors the Support Case controller's shape. List is Livewire-backed (`ConversationList`, showing unread counts); show/reply/report are plain Blade forms re-checking `ConversationPolicy` on every request. `GET /dashboard/messages/start/{target}` is the SRS §17.42 entry point ("Student opens lesson or learning plan context") — resolving student/instructor roles and opening or finding the eligible conversation; wiring an explicit "Message" button onto existing booking/lesson detail pages was left out to keep this phase's change minimal (noted as a follow-up, not a gap in enforcement).
