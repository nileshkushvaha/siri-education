# Notifications

## Three notification systems

**1. Local flash toasts** — `Filament\Notifications\Notification::make()->send()` — ephemeral in-page toasts. Used in Filament pages after saves, actions, etc. Keep these as-is.

**2. Database bell notifications** — `->sendToDatabase($recipients)` — persisted to the `notifications` table, visible via Filament's bell icon. These come exclusively from the Activity Log pipeline (below).

**3. Transactional email notifications** — queued Laravel Notifications sent through the configured mailer. Production uses Resend; local development may use `log`; tests may use `array`. Delivery status is logged in `email_logs` and reviewed from Filament's **Email Logs** resource. See `resend.md`.

Never send transactional email directly from controllers, Livewire components, or Filament resources. Use domain events plus queued listeners where possible, or `TransactionalNotificationService` for on-demand mail routes.

## Admin database-bell pipeline (Activity Log)

```
Business Action
    → AuditTrailService::log*()
    → activity_log table saved
    → ActivityObserver::created()
    → ActivityCreated event dispatched
    → NotifyAdminsOnActivity listener (queued, queue: 'notifications')
    → NotificationMapper::map($activity) — returns NotificationPayload or null
    → AdminNotificationService::notify($payload, $actor)
    → sendToDatabase(all active super_admins, excluding actor if super_admin)
```

No admin notification is ever sent from a service directly — it always goes through this pipeline (`docs/decisions.md`).

### Key files

| File | Purpose |
|---|---|
| `app/Listeners/NotifyAdminsOnActivity.php` | Bridges ActivityCreated to notification delivery |
| `app/Services/Admin/NotificationMapper.php` | Maps activity `log_name.event` → `NotificationPayload`, or `null` to silence it |
| `app/Services/Admin/AdminNotificationService.php` | Resolves recipients, calls `sendToDatabase` |
| `app/Services/Admin/ActivityUrlResolver.php` | Builds deep-link URL for the notification's action button |
| `app/DTOs/NotificationPayload.php` | Immutable value object: title, body, icon, color, severity, category, priority, url |

### Recipients

All active `super_admin` users, excluding the actor when they are a `super_admin` themselves. `RoleDoesNotExist` is caught gracefully — returns an empty collection when the role hasn't been seeded.

### NotificationMapper — current notifiable events

This table is generated from `NotificationMapper::map()`'s match arms — if you add or remove a mapped event, update this table (or just read the source directly; it's the single place the notification policy lives, one match arm per event):

| Domain | Event key (`log_name.event`) | Title | Severity |
|---|---|---|---|
| Users | `users.created` | New User Created | success |
| Users | `users.roles_updated` | User Roles Changed | warning |
| Users | `users.account_approved` | User Account Approved | success |
| Users | `users.password_change_required` | Password Change Required | info |
| Roles | `roles.created` | Role Created | success |
| Roles | `roles.updated` | Role Updated | warning |
| Roles | `roles.deleted` | Role Deleted | danger |
| Security | `security.settings_updated` | Security Settings Changed | warning |
| Auth | `auth.account_locked` | Account Locked | warning |
| Auth | `auth.manual_lock` | Account Manually Locked | danger |
| Auth | `auth.manual_unlock` | Account Unlocked | info |
| Auth | `auth.self_service_unlock` | Account Self-Service Unlocked | info |
| Auth | `auth.registration_pending_approval` | New Registration Awaiting Approval | info |
| CMS | `cms.auto_published` | Content Auto-Published | success |
| Contact | `contact.contact_form_submitted` | New Contact Form Submission | info |
| Referral rewards | `referral_rewards.reward_credit_failed` | Referral Reward Credit Failed | danger |
| Referral rewards | `referral_rewards.reward_held` | Referral Reward Held for Review | warning |
| Referral rewards | `referral_rewards.reward_reversal_required` | Referral Reward Needs Manual Reversal | danger |
| Public forms | `forms.callback_requested` | New Callback Request | info |
| Public forms | `forms.feedback_submitted` | New Feedback Submitted | info |
| Public forms | `forms.support_requested` | New Support Request | warning |
| Public forms | `forms.inquiry_submitted` | New General Inquiry | info |
| Newsletter | `newsletter.newsletter_subscribed` | New Newsletter Subscriber | success |
| FAQs | `faqs.published` | FAQ Published | success |
| FAQs | `faqs.deleted` | FAQ Deleted | danger |
| Instructor profile | `instructor.profile_approved` | Instructor Profile Approved | success |
| Instructor profile | `instructor.profile_rejected` | Instructor Profile Rejected | danger |
| Instructor profile | `instructor.profile_published` | Instructor Profile Published | success |
| Booking lifecycle | `bookings.booking_requested` | New Booking | success |
| Booking lifecycle | `bookings.booking_confirmed` | Booking Confirmed | info |
| Booking lifecycle | `bookings.booking_cancelled` | Booking Cancelled | danger |
| Booking lifecycle | `bookings.booking_rescheduled` | Booking Rescheduled | warning |
| Booking lifecycle | `bookings.booking_completed` | Booking Completed | success |
| Meetings | `bookings.meeting_creation_failed` | Meeting Creation Failed | danger |
| Meetings | `bookings.meeting_cancellation_failed` | Meeting Cancellation Failed | danger |
| Lessons | `lessons.lesson_no_show` | Lesson No-Show | warning |
| Lessons | `lessons.lesson_disputed` | Lesson Disputed | danger |
| Payments | `payments.payment_late_terminal_manual_resolution` | Payment Needs Manual Resolution | danger |
| Instructor payouts | `instructor_payouts.payout_method_submitted` | Payout Method Awaiting Verification | warning |
| Instructor payouts | `instructor_payouts.withdrawal_requested` | New Withdrawal Request | warning |
| Instructor payouts | `instructor_payouts.withdrawal_failed` | Withdrawal Payout Failed | danger |
| Instructor payouts | `instructor_payouts.withdrawal_reversed` | Withdrawal Payout Reversed | danger |
| Instructor compensation | `instructor_compensation.earning_blocked_no_agreement` | Lesson Earning Blocked — No Compensation Agreement | danger |
| Payout execution | `instructor_payout_execution.payout_attempt_queued` | Payout Queued for Execution | info |
| Payout execution | `instructor_payout_execution.payout_reconciliation_issue_detected` | Payout Reconciliation Issue | danger |
| Payout execution | `instructor_payout_execution.payout_retry_exhausted` | Payout Retry Budget Exhausted | danger |
| Support cases | `support_cases.case_created` | New Support Case | info |
| Support cases | `support_cases.case_escalated` | Support Case Escalated | danger |
| Support cases | `support_cases.case_reopened` | Support Case Reopened | warning |
| Messaging | `messaging.message_reported` | Message Reported | warning |

All other events (login, logout, profile changes, cache operations, routine payout processing, etc.) return `null` from the mapper — no admin notification. Some domains (support cases, messaging) intentionally map only a subset of their events here — routine participant-facing events for those domains are handled by their own dedicated listener (`SendSupportCaseNotifications`, `SendMessagingNotifications`) rather than the admin pipeline, so as not to double-notify.

### Adding a new notifiable admin event

1. Log the activity via `AuditTrailService` with the correct `log_name` and `event`.
2. Add the `$log === '...' && $event === '...'` case to `NotificationMapper::map()`.
3. `AdminNotificationService` and the rest of the pipeline require no changes.

## Participant (student/instructor) notifications

Domain events → queued listeners (`notifications` queue) → Laravel Notifications extending the relevant domain's notification base class (e.g. `App\Notifications\Booking\BookingNotification`, which wires in `ShouldQueue`, `TransactionalEmail`, and `ConfiguresTransactionalEmail` for category-based sender routing). Channel routing (email now; WhatsApp/SMS stubbed) is driven by the relevant settings class's toggles — see `docs/booking.md`'s Notifications section for the booking domain's specific event → listener → notification map, and the `RoutesBookingChannels` trait pattern it uses.

### Content safety rules (apply across every domain, not just booking)

- Instructor-facing copies never contain: the student's paid amount, pricing-rule ids, payment-provider ids, wallet data, platform margin, gateway metadata, or raw provider responses.
- Student-facing copies may contain amount/currency only where the student already sees them (their own record's price snapshot).
- Admin alerts (the database-bell pipeline above) contain only sanitized, pre-redacted descriptions — provider name plus a masked/sanitized failure reason, never secrets, OAuth tokens, raw webhook payloads, or raw third-party API responses. Sanitization happens before the activity log entry is ever written (e.g. `SanitizesProviderMessages` for meeting-provider failures), not at notification time.

### Duplicate / idempotency strategy

There is no notification-log/dedupe table — duplicate-suppression relies on the same domain guarantees that prevent the underlying event from firing twice: a state-machine `canTransitionTo()` guard rejects a duplicate transition, a payment webhook replay is answered `ignored` before its success event can fire a second time, and an idempotent "create if not exists" action (e.g. meeting creation) short-circuits before dispatching an event at all. When adding a new domain's notifications, rely on the same pattern — guard the event dispatch at the domain-transition level, not with a separate notification dedupe mechanism.

### Queue

Notifications and their listeners run on the `notifications` queue (database driver): `php artisan queue:work --queue=notifications --tries=3` (supervised in production — nothing is delivered without a running worker). Mail uses the existing transactional-email plumbing (`ConfiguresTransactionalEmail`, Resend) and stock `MailMessage` content — no separate template system per notification. Tests use `Notification::fake()`.

## Adding a transactional email

1. Dispatch a domain event from the business service.
2. Handle it in a queued listener on the `notifications` queue.
3. Send a queued Notification/Mailable only.
4. Extend the matching base notification class for the domain (Auth, Booking, Payment, Instructor, Wallet, Support, or Admin).
5. Configure the category sender in Mail Settings when a separate sender address is required.
6. Confirm the message appears in `email_logs`.

## Troubleshooting

- **Nothing arrives**: check the `notifications` queue worker is actually running (`php artisan queue:work --queue=notifications`) — queued notifications silently pile up in the `jobs` table otherwise.
- **Admin bell notification missing for an event you expect**: check `NotificationMapper::map()` first — most events are intentionally silenced (`default => null`); only the events in the table above produce a bell notification.
- **Email not delivered but no error**: check `email_logs` (Filament's Email Logs resource) for the delivery attempt and its provider response; see `resend.md` for provider-level DNS/webhook diagnostics.
- **Duplicate notification for the same event**: check whether the domain event itself fired twice (a missing idempotency guard on the write path) before assuming the notification layer is at fault — see "Duplicate / idempotency strategy" above.
