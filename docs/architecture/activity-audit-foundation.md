# Activity Timeline and Audit Logging Foundation

## Executive Summary

Spatie Activitylog is the only audit logging system in this project — there is no reason to build a second one. The foundation: an extended `Activity` model with actor-type awareness (User/Guest/System), `AuditTrailService` as the single logging entry point, a permission-gated Filament Activity Log viewer, and a two-tier design (`booking_activities` as the booking business timeline, the unified Activity Log as the technical audit trail). The sections below record the patterns established for payment success/failure, student (non-instructor) profile edits, settings changes, and admin overrides, plus the "never call `activity()` directly" rule and the two places that used to violate it.

## Two Kinds of Timeline (Business Timeline vs. Technical Audit Log)

This project draws this distinction explicitly and consistently everywhere.

| | Business timeline | Technical audit log |
|---|---|---|
| Example | `booking_activities` (`BookingActivity`/`BookingActivityAction`) | `activity_log` (`Activity`, fed by `AuditTrailService`) |
| Scope | One entity's own step-by-step history | Cross-entity, searchable, filterable by actor/log/event/date |
| Audience | Whoever owns/views that one entity (a booking's participants) | Admins investigating "what happened across the system" |
| Granularity | Every micro-transition (`requested`, `guest_invited`, `payment_status_changed`, ...) | Meaningful, named business events only |
| Actor model | `BookingActor` (Attendee/Host/Admin/System) | `ActivityActorType` (User/Guest/System) |

They're deliberately not merged. A booking's own page needs its full blow-by-blow history; an admin auditing "show me every payment failure this week across all bookings" needs one global, indexed table — not to join across every domain's own timeline table. Where an event matters at both levels (payment success/failure), both get written; see below.

## What Already Existed (Confirmed Adequate, No Changes)

- **`AuditTrailService`** (`app/Services/AuditTrailService.php`) — the shared logging helper (`logUser`/`logGuest`/`logSystem`), capturing actor type, IP, user agent, route, method, session ID, plus `logOverride()` (see below).
- **`App\Models\Activity`** — extends Spatie's `Activity`, adds `actor_type` (User/Guest/System) plus `actorName()`/`actorEmail()`/`actorIdentifier()`/`actorDescription()` accessors so callers never null-check actor logic themselves.
- **Filament Activity Log viewer** (`app/Filament/Resources/ActivityLog/`) — list + view pages, read-only (no create/edit/delete), gated by `ActivityLogPolicy::viewAny()` (`ViewAny:Activity` permission or super admin). Satisfies "admin activity logs require permission" as-is.
- **13 models already use `LogsActivity`** (Spatie's model-diff trait): `User`, `UserProfile`, `Booking`, `BookingType`, `Country`, `Currency`, `Language`, `State`, `Tag`, `Page`, `Post`, `PostCategory`, `NavigationMenu`/`NavigationItem`, `ContentBlock`.
- **Booking lifecycle events already reach the unified log**: `RecordBookingLifecycleAudit` listens for `BookingRequested`/`Confirmed`/`Cancelled`/`Rescheduled`/`Completed` and writes `bookings` log entries via `AuditTrailService`, correctly choosing `logGuest` vs `logSystem` based on whether the booking is a guest booking.
- **Instructor approve/reject already logged**: `UserProfileObserver` + `NotifyInstructorOnProfileActivity` (see the User Lifecycle Foundation doc for the `InstructorStatus` rename this built on).
- **User registration already logged**: the `auth` log name covers registration, approval-required, email verification, etc. (see `RegistrationIntegrationTest`).

## Established Patterns

### 1. Payment success/failure reaches both the per-booking timeline and the unified log
`BookingPaymentService::logPayment()` wrote to `booking_activities` only. Financial state changes need to be centrally traceable (implementation rule: "financial changes must be traceable"), not just visible from inside one booking's own history. `logPayment()` now also calls `AuditTrailService` (log name `payments`, events `payment_paid`/`payment_failed`/`payment_refunded`) — both writes happen from the same method, so they can never drift apart.

### 2. Never call `activity()` directly
`AuditTrailService`'s docblock states business code must never call `activity()` directly — two places did anyway:
- **`PaymentWebhookProcessor`** — routed through `AuditTrailService::logSystem()` instead. Also stopped logging the **raw webhook payload** into the audit log (implementation rule: don't log sensitive content — gateway payloads can carry tokens/PII). Only `gateway`, `event` type, and a `reference` id are stored in the Activity Log now; the full payload still goes to the engineers-only debug log (`Log::channel`, gated by a separate `payment_logging` setting, never exposed via any admin UI).
- **`EditUser`** (Filament) — its `roles_updated`/`account_approved`/`password_change_required`/`password_change_cleared` events now go through `AuditTrailService::logUser()`, same log name/event/description as before (no behavior change, just routed through the standard entry point).

### 3. Student (non-instructor) profile updates are also logged
`UserProfileObserver` logs a generic `profile`/`profile_updated` event that fires for **any** user (student or instructor) when one of a curated set of content fields changes (`headline`, `bio`, `phone`, `address`, social links, etc.) — deliberately excluding system-recalculated columns like `profile_completion` so an unrelated save doesn't create noise. Instructor-only events are unchanged and still fire alongside this for instructor accounts.

### 4. Settings changes are audited via a shared trait
`LogsSettingsUpdates` (`app/Filament/Pages/Settings/LogsSettingsUpdates.php`), a trait mirroring the existing `HasSettingsAccess` pattern: `snapshotSettings()` captures state before mutation, `logSettingsUpdate()` diffs after `->save()` and logs only the changed fields (log name `settings`, event `settings_updated`) — an unchanged save logs nothing. Sensitive-looking keys (containing `password`/`secret`/`token`/`api_key`) are redacted in the diff rather than logged in plain text.

Wired into **`GeneralSettingsPage`** and **`PlatformFoundationSettingsPage`** (all 7 of its `save*()` methods) as the demonstrated pattern. When adding audit logging to another settings page, use the same two-line pattern: `$before = $this->snapshotSettings($settings);` before mutating, `$this->logSettingsUpdate('settings', $settings, $before);` after `->save()`. Not every settings page has adopted this yet — check whether a given page already logs before assuming it doesn't.

### 5. Admin override reason pattern
`AuditTrailService::logOverride(User $admin, string $logName, string $event, string $description, string $reason, ...)` — a thin wrapper over `logUser()` that makes `$reason` mandatory and always stamps `override_reason` and `is_override: true` into properties, so overrides are findable independently of whatever `log_name` the calling feature uses.

Example: the **"Force Approve"** action on the Filament User edit page's header, visible only when the record holds the `instructor` role and its `instructor_status` isn't already in `InstructorStatus::bookable()`. Requires a reason (Filament form validation), sets `instructor_status` to `Approved`, and logs via `logOverride()` under the `instructor` log name with event `admin_override`. Several other domains (wallet freeze/close/adjustment, referral reward decisions, support case escalation) follow the same pattern — see each domain's own doc.

## Naming Convention

The existing convention — confirmed, not changed — is: **snake_case, either the plural of the model/table it tracks (`bookings`, `countries`, `currencies`, `pages`, `posts`, `post_categories`, `booking_types`) or a domain name for cross-cutting business processes that don't map to one model (`auth`, `security`, `instructor`, `payments`, `settings`, `profile`)**. `User::useLogName('user')` is the one singular exception among model-tracking log names — kept as-is rather than renamed, since that would touch a large number of already-passing tests for a purely cosmetic gain.

## Admin Override Reason Pattern

Use `AuditTrailService::logOverride()` whenever an admin action bypasses a normal workflow/lifecycle rather than progressing it normally (e.g., force-approving before review finishes, manually correcting a value a workflow would normally compute). Don't use it for ordinary admin edits that a policy already permits — that's just `logUser()`. The distinguishing question: "if someone found this in the log with no other context, would they need to know *why* it was done?" If yes, it's an override.

## Auditable Entities — Final List

| Entity / Domain | Log name | Mechanism |
|---|---|---|
| User (identity) | `user` (model diff), `users` (roles/approval/password events) | `LogsActivity` + `AuditTrailService` (EditUser) |
| UserProfile (student + instructor) | `profile` | `LogsActivity` (model diff) + `UserProfileObserver` (`profile_updated`) |
| Instructor lifecycle | `instructor` | `UserProfileObserver` (status/visibility/featured changes, admin overrides) |
| Bookings | `bookings` | `LogsActivity` (model diff) + `RecordBookingLifecycleAudit` (lifecycle events) |
| Booking payments | `payments` | `BookingPaymentService::logPayment()` (paid/failed/refunded) |
| Generic payment gateway webhooks | `payments` | `PaymentWebhookProcessor` (`webhook_received`) |
| Settings (General, Platform Foundation) | `settings` | `LogsSettingsUpdates` trait |
| Auth (registration, verification, approval) | `auth` | `AuditTrailService` (RegistrationService, listeners) |
| CMS (Pages, Posts, Categories, Nav, Content Blocks) | `pages`, `posts`, `post_categories`, `navigation`, `navigation_item`, `content_blocks` | `LogsActivity` (model diff) |
| Countries / States / Currencies / Languages | `countries`, `states`, `currencies`, `languages` | `LogsActivity` (model diff) |
| Booking types | `booking_types` | `LogsActivity` (model diff) |
| FAQs / FAQ categories | `faqs`, `faq_categories` | `AuditTrailService` (observers) |
| Public forms (contact, newsletter, etc.) | `contact`, `forms`, `newsletter` | `AuditTrailService::logGuest()` |
| Security (login/lockout events) | `security` | `AuditTrailService` |
| Cache manager / scheduler (system tasks) | `cache_manager`, `scheduler_monitor` | `AuditTrailService::logSystem()` |
| Admin overrides (cross-cutting) | *(whatever log the feature uses)* | `AuditTrailService::logOverride()` |
| Wallet (credit/debit/freeze/close/adjustment/reversal) | `wallet` (model diff) + `AuditTrailService::logUser()`/`logOverride()` | see `docs/architecture/wallet.md` |
| Referral rewards, promotional credits, support cases, messaging, instructor payouts/earnings | domain-specific log names (`referral_rewards`, `support_cases`, `messaging`, `instructor_payouts`, `instructor_compensation`, …) | `AuditTrailService` — see `docs/notifications.md`'s NotificationMapper event table for the current full cross-domain list of what's logged and which events reach admins |

## Access Rules

- **Admin Activity Log viewer**: gated by `ActivityLogPolicy` (`ViewAny:Activity` permission or super admin) — already existed, unchanged.
- **Students/instructors and their own timeline**: no separate student/instructor-facing "my activity" view exists in this codebase (the student dashboard shows Notifications, not raw activity log entries), so "students/instructors should only see their own timeline where applicable" has no surface to violate today — the *only* place any Activity Log data is browsable is the admin-only Filament resource above. If a student/instructor-facing timeline is built later, it must filter by `causer_id = auth()->id()` and must not reuse the admin resource's unfiltered query.
- **Sensitive content**: no document contents (KYC uploads, media files) are ever logged — only field names and metadata. Payment webhook payloads are excluded from the Activity Log entirely (see "Never call activity() directly" above); the raw payload only ever reaches the engineers-only debug log channel.

## Tests

- `tests/Feature/AuditTrail/AuditTrailTest.php` — extended with `logOverride()` coverage (actor type, mandatory reason storage, `is_override` flag, property merging).
- `tests/Feature/Booking/PaymentWorkflowTest.php` — extended: successful/failed payments now also assert a `payments`-log entry in the unified Activity Log.
- `tests/Unit/Services/PaymentWebhookProcessorTest.php` — new: confirms routing through `AuditTrailService` (not raw `activity()`), confirms the raw payload/sensitive fields are never stored, confirms the audit-log toggle setting is respected.
- `tests/Feature/Profile/ProfileUpdateActivityLogTest.php` — new: generic `profile_updated` fires for students, doesn't fire for untracked fields, lists changed fields, and instructor accounts get both the generic and instructor-specific events.
- `tests/Feature/Settings/GeneralSettingsTest.php` — extended: settings save logs a diff; an unchanged save logs nothing.
- `tests/Feature/Instructor/InstructorForceApproveOverrideTest.php` — new: reason required, status forced to Approved, override logged with reason, action hidden once already bookable or for non-instructors.
