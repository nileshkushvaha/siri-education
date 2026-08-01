# Activity Log / Audit Trail

## Architecture rule

Business services never call `activity()` directly. All audit trail writes go through `AuditTrailService`. This keeps actor-type logic in one place and makes the pipeline future-proof.

## Three actor types

`App\Enums\ActivityActorType` — backed enum with values `user`, `guest`, `system`.

| Actor    | When                                                  | causer_id |
| -------- | ----------------------------------------------------- | --------- |
| `User`   | Authenticated user action                             | set       |
| `Guest`  | Anonymous visitor (contact form, public registration) | null      |
| `System` | Queue job, scheduler, CLI, automated process          | null      |

## AuditTrailService

`app/Services/AuditTrailService.php`

```php
// Authenticated user action
$audit->logUser($user, 'users', 'created', 'User created', subject: $user, properties: []);

// Anonymous visitor action
$audit->logGuest('contact', 'contact_form_submitted', 'Contact form submitted',
    subject: $block, guestName: 'John', guestEmail: 'john@sirieducation.com', guestPhone: '+1...');

// Automated / background action
$audit->logSystem('scheduler_monitor', 'manually_ran', 'Task executed', properties: []);
```

All three methods capture request context automatically (ip_address, user_agent, route, method, session_id) where available.

## Custom Activity model

`App\Models\Activity` extends `Spatie\Activitylog\Models\Activity`.

Helper methods (never check `$activity->causer` directly — use these):

| Method               | Returns                                               |
| -------------------- | ----------------------------------------------------- |
| `isUser()`           | bool                                                  |
| `isGuest()`          | bool                                                  |
| `isSystem()`         | bool                                                  |
| `actorName()`        | string — user name / guest name / 'System'            |
| `actorEmail()`       | ?string                                               |
| `actorIdentifier()`  | string — best available identifier                    |
| `actorDescription()` | string — 'Alice Smith <alice@sirieducation.com>' etc. |

`booted()` hook auto-detects actor type for raw `activity()` helper calls (backward compat): sets `user` if `causer_id` is present, `system` otherwise.

## Database table

`activity_log` — single unified table.

Additional columns added to Spatie's base schema:

| Column        | Type         | Purpose                      |
| ------------- | ------------ | ---------------------------- |
| `actor_type`  | varchar(20)  | ActivityActorType enum value |
| `guest_name`  | varchar      | Guest display name           |
| `guest_email` | varchar      | Guest email                  |
| `guest_phone` | varchar(50)  | Guest phone                  |
| `ip_address`  | varchar(45)  | Request IP (IPv6-safe)       |
| `user_agent`  | text         | Browser/client UA string     |
| `route`       | varchar(500) | Request path                 |
| `method`      | varchar(10)  | HTTP method                  |
| `session_id`  | varchar(100) | Session ID                   |

## Pipeline

Every `activity_log` insert triggers `ActivityObserver::created()` → `ActivityCreated` event → `NotifyAdminsOnActivity` listener.

See `notifications.md` for the full notification pipeline.

## Filament admin UI

System → Activity Log — read-only, all actor types visible.

Table: actor type badge (User/Guest/System with icon and colour), actor name, actor email, log channel, event badge, description, when.

Filters: actor type, event, log channel, subject type, user, date range.

Search: includes description, log name, guest name, guest email, user name/email.

Detail view (infolist): Subject section, Performed By section (actor type badge + name + email + guest phone), Changes tabs (Before/After/Metadata with ip/method/route/session).

Dashboard: Recent Audit Trail widget — last 8 entries with actor badge.

## Log channels in use

`auth`, `users`, `roles`, `pages`, `posts`, `content_blocks`, `contact`, `cache_manager`, `security`, `scheduler_monitor`, `countries`, `user`, `default`
