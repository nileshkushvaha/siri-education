# Notifications

## Two notification systems

**1. Local flash toasts** — `Filament\Notifications\Notification::make()->send()` — ephemeral in-page toasts. Used in Filament pages after saves, actions, etc. Keep these as-is.

**2. Database bell notifications** — `->sendToDatabase($recipients)` — persisted to the `notifications` table, visible via Filament's bell icon. These come exclusively from the Activity Log pipeline.

**3. Transactional email notifications** — queued Laravel Notifications sent through the configured mailer. Production uses Resend; local development may use `log`; tests may use `array`. Delivery status is logged in `email_logs` and reviewed from Filament's **Email Logs** resource. See `resend.md`.

Never send transactional email directly from controllers, Livewire components, or Filament resources. Use domain events plus queued listeners where possible, or `TransactionalNotificationService` for on-demand mail routes.

## Activity Log pipeline

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

## Key files

| File | Purpose |
|---|---|
| `app/Listeners/NotifyAdminsOnActivity.php` | Bridges ActivityCreated to notification delivery |
| `app/Services/Admin/NotificationMapper.php` | Maps activity log_name.event → NotificationPayload |
| `app/Services/Admin/AdminNotificationService.php` | Resolves recipients, calls sendToDatabase |
| `app/Services/Admin/ActivityUrlResolver.php` | Builds deep-link URL for notification action button |
| `app/DTOs/NotificationPayload.php` | Immutable value object: title, body, icon, color, severity, category, priority, url |

## NotificationMapper — notifiable events

| Event key | Title | Severity |
|---|---|---|
| `users.created` | New User Created | success |
| `users.roles_updated` | User Roles Changed | warning |
| `users.account_approved` | User Account Approved | success |
| `roles.created` | Role Created | success |
| `roles.updated` | Role Updated | warning |
| `roles.deleted` | Role Deleted | danger |
| `security.settings_updated` | Security Settings Changed | warning |
| `auth.account_locked` | Account Locked | warning |
| `auth.manual_lock` | Account Manually Locked | danger |
| `auth.manual_unlock` | Account Unlocked | info |
| `auth.registration_pending_approval` | New Registration Awaiting Approval | info |
| `cms.auto_published` | Content Auto-Published | success |
| `contact.contact_form_submitted` | New Contact Form Submission | info |

All other events (login, logout, profile changes, cache operations, etc.) return `null` — no notification.

## Recipients

All active `super_admin` users, excluding the actor when they are a `super_admin` themselves.

`RoleDoesNotExist` is caught gracefully — returns empty collection when role has not been seeded.

## Adding a new notifiable event

1. Log the activity via `AuditTrailService` with the correct `log_name` and `event`
2. Add the `'{log_name}.{event}'` case to `NotificationMapper::map()`
3. `AdminNotificationService` and the pipeline require no changes

## Adding a transactional email

1. Dispatch a domain event from the business service.
2. Handle it in a queued listener on the `notifications` queue.
3. Send a queued Notification/Mailable only.
4. Extend the matching base notification structure: Auth, Booking, Payment, Tutor, Wallet, Support, or Admin.
5. Configure the category sender in Mail Settings when a separate sender address is required.
6. Confirm the message appears in `email_logs`.
