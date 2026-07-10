# Phase 12 — Notifications for Booking, Payment, and Meeting Lifecycle

Queued, event-driven participant notifications plus admin alerts for
the booking → payment → meeting lifecycle. No new infrastructure — the
phase extends the two notification pipelines that already existed:

1. **Participants (student/instructor)**: domain events → queued
   listeners (`notifications` queue, tries 3, backoff 30/60/120) →
   Laravel notifications extending the `BookingNotification` base
   (transactional-email config, `RoutesBookingChannels` channel
   routing from `BookingSettings` toggles — email on, WhatsApp/SMS
   stubs off, unchanged).
2. **Admins**: `AuditTrailService` activity → `ActivityCreated` →
   `NotifyAdminsOnActivity`, with the policy in one place —
   `NotificationMapper`. No admin notification is ever sent from a
   service directly (docs/decisions.md rule, preserved).

## Events

| Event | Dispatched from | Fires when |
|---|---|---|
| `BookingRequested` *(pre-existing)* | `BookingService::request()` | every booking creation |
| `BookingConfirmed` *(pre-existing)* | `BookingService::confirm()` / auto-confirm | exactly once per booking |
| `BookingCancelled` *(pre-existing)* | `BookingService::cancel()` | now carries `CancelBookingData::$expired` — true only from `booking:release-expired` |
| `BookingPaymentSucceeded` **(new)** | `BookingPaymentService::markPaid()`, after its transaction | a provider-verified payment settles the booking — never from initiation, frontend `verifyCheckout()`, or Option B's late-terminal path |
| `MeetingCreated` **(new)** | `BookingMeetingService::dispatchTransitionEvents()`, after the write transaction | `booking_meetings.status` genuinely transitions into `created` |
| `MeetingUpdated` **(new)** | same | an already-created meeting's join URL actually changed |

No `BookingExpired` event was added — in this domain expiry *is* a
cancellation (the state machine has no Expired status); the `expired`
flag on the existing event distinguishes the notification wording
without duplicating events. No `MeetingFailed` event either: the admin
alert rides the Activity Log pipeline (below), so an event with no
listener would be dead weight. No `PaymentFailed` notification this
phase (not in the required set).

## Listeners

- `SendBookingNotifications` *(extended)* — `handleRequested` now also
  sends the student a pending-payment notice when
  `payment_status = Pending`; new `handlePaymentSucceeded`;
  `handleCancelled` sends the student `BookingExpiredNotification`
  instead of the cancellation notice when `context->expired` (the host
  keeps the standard cancellation copy either way).
- `SendMeetingNotifications` **(new)** — `handleCreated` /
  `handleUpdated`, both notifying attendee + host.
- `NotificationMapper` *(extended)* — two admin entries:
  `bookings/meeting_creation_failed` ("Meeting Creation Failed",
  danger, priority 3) and
  `payments/payment_late_terminal_manual_resolution` ("Payment Needs
  Manual Resolution", danger, priority 3). Both audit entries already
  existed (Phases 10.2B/11); the mapper entries just stop silencing
  them.

## Notifications (all queued: `ShouldQueue`, `notifications` queue)

| Notification | Recipients | Content |
|---|---|---|
| `BookingPendingPaymentNotification` **(new)** | student only | reference, type, date/time/timezone, amount due (the student's own price snapshot), link to `dashboard.my-bookings` |
| `BookingPaymentSucceededNotification` **(new)** | student only | reference, amount/currency (already student-visible), never a provider/order/payment id |
| `BookingConfirmedNotification` *(pre-existing)* | student + instructor | reference, type, date/time/timezone — contains no price for anyone (verified by test) |
| `MeetingCreatedNotification` **(new)** | student + instructor | join URL + passcode + date/time/timezone; never host/start URL or provider metadata |
| `MeetingUpdatedNotification` **(new)** | student + instructor | replacement join link, same safety rules |
| `BookingCancelledNotification` *(pre-existing)* | student + instructor | reference, time, cancellation reason |
| `BookingExpiredNotification` **(new)** | student only | reference, expiry message, "book again" link |
| Meeting-failed admin alert | admins (via activity pipeline) | booking reference + provider + **sanitized** failure reason (token-shaped substrings already redacted by `SanitizesProviderMessages` before the audit entry is ever written) |

## Content safety rules

- Instructor copies never contain: student paid amount, pricing-rule
  ids, payment provider ids, wallet data, platform margin, gateway
  metadata, or raw provider responses. (The shared
  `BookingConfirmedNotification` simply contains no price at all.)
- Student copies may contain amount/currency only where the student
  already sees them (their own booking's price snapshot).
- Admin alerts contain provider name + masked/sanitized failure reason
  only — never secrets, OAuth tokens, raw webhook payloads, or raw
  Google/Zoom responses (the mapper reads the activity description,
  which is built from pre-sanitized strings).

## Duplicate / idempotency strategy

No notification log table — duplicate-suppression rides the domain
guarantees that already existed:

- **Duplicate payment webhook** → `markPaid()` requires
  `payment_status === Pending`; a replay is answered `ignored` before
  `BookingPaymentSucceeded` (or a re-confirm → meeting chain) can fire.
- **Duplicate confirm** → `canTransitionTo()` throws.
- **Idempotent meeting re-create** → short-circuits on an existing
  `created` row before any event dispatch.
- **Admin re-save without change** → `MeetingUpdated` only fires when
  the join URL actually differs.

## Queue / mail

Everything queues on the `notifications` queue (existing worker:
`php artisan queue:work --queue=notifications --tries=3`). Mail uses
the existing transactional-email plumbing (`ConfiguresTransactionalEmail`,
Resend) and stock `MailMessage` lines — no new Blade templates, no
asset changes. Tests use `Notification::fake()`; production delivery
depends on the existing mail settings being configured (see
docs/resend.md), unchanged by this phase.

## Explicitly not built

WhatsApp/SMS integrations (the existing safe stubs remain off),
notification preference center, marketing campaigns, chat, wallet
debit/recharge notifications, instructor payout notifications,
recording/attendance/homework/review notifications, and no new tables
of any kind.
