# Phase 11 — Meeting Creation Foundation (Manual + Google Meet)

Provider-neutral meeting creation for confirmed bookings, backed by a
dedicated `booking_meetings` table. Two working providers this phase:
`ManualMeetingProvider` and `GoogleCalendarMeetProvider` (via the
official `google/apiclient` SDK). No fake provider — see below.

## Why no `FakeMeetingProvider`

Explicit business decision for this phase. Local/testing exercises the
real `ManualMeetingProvider` (no external call, no credentials) instead
of a dedicated fake — it already behaves like a safe no-op provider,
so a separate fake would only duplicate it. `GoogleCalendarMeetProvider`
is tested by binding a fake `GoogleCalendarClient` (the SDK-isolation
seam), never a fake meeting *provider*.

## Meeting creation rule

A meeting is created only for a booking that is already `Confirmed`,
never before:

- **Paid booking**: `payment_status = Paid` (i.e. only after
  `BookingPaymentService::markPaid()` → `BookingService::confirm()`
  has run — a provider-verified webhook, not a frontend-only success).
- **Demo/free booking**: `payment_status = NotRequired` and the
  booking auto-confirmed in `BookingService::request()`.
- Never for a guest booking (`attendee_id === null`) — guest
  booking/payment remains disabled; never for a terminal
  (cancelled/expired/completed/no-show) booking; never for Option B's
  late-terminal payment path (see below); never for an offline/phone
  booking (`location_type !== Online` — "booking type supports an
  online lesson").

Both cases are gated per-kind by
`MeetingSettings::create_after_paid_booking_confirmation` /
`create_after_demo_booking_confirmation`, all under the platform-wide
`meetings_enabled` kill switch.

## Trigger: one event, no new call sites in Livewire/controllers

`BookingConfirmed` already fires exactly once per booking — from
`BookingService::confirm()` (paid, after payment settles) and from
`BookingService::request()` (auto-confirmed demo/free). The existing
queued listener, `CreateMeetingOnBookingConfirmed` (unchanged from its
first draft), calls `BookingMeetingService::createMeeting()` on that
event. No Livewire component, controller, or webhook handler calls the
meeting service directly.

Because `BookingConfirmed` never fires from payment initiation,
checkout-opened, or Option B's `handleLateTerminalPayment()` (which
explicitly never confirms a booking), those paths cannot trigger
meeting creation by construction.

## Data model

Dedicated table, `booking_meetings` (unique `booking_id`, one meeting
per booking):

| Column | Purpose |
|---|---|
| `provider` | `manual` \| `google_meet` \| `zoom` — which `MeetingProviderInterface` created the row |
| `provider_meeting_id` / `provider_event_id` | Provider references (Google's conference id / calendar event id; Zoom's numeric meeting id lands in `provider_meeting_id`) |
| `join_url` | Safe to show to student/instructor once `status = created` |
| `host_url`, `password` | Hidden by default (`BookingMeeting::$hidden`) — never in the student resource |
| `status` | `pending` / `created` / `failed` / `cancelled` (`MeetingStatus` enum) |
| `failure_reason` | Sanitized (token/key-shaped substrings redacted before storage) |
| `metadata` | Sanitized provider metadata only — never a raw API payload |
| `created_by` / `updated_by` | Admin/system actor, for audit |

The booking's own `starts_at`/`ends_at`/`timezone` are the default
meeting window (overridable per-meeting via `MeetingCreationContext`/
`MeetingUpdateContext`).

**Compatibility**: the pre-existing `bookings.meeting_provider` /
`meeting_ref` / `meeting_url` columns (predating this phase, read by
`BookingConfirmedNotification`'s email CTA) are kept and mirrored by
`BookingMeetingService::syncLegacyBookingColumns()` after every
create/update — `booking_meetings` is the canonical store; those three
columns are a read-only mirror so that pre-existing reader is not
rewritten this phase.

## Provider abstraction

`MeetingProviderInterface` — `key()`, `createMeeting()`,
`updateMeeting()`, `cancelMeeting()`, `isConfigured()` — with three
DTOs: `MeetingCreationContext` (create input), `MeetingUpdateContext`
(update/retry input), `MeetingCreationResult` (provider output;
`joinUrl` is nullable — Google's conference creation is async, so
`Pending` with no link yet is a valid result), `MeetingCancellationResult`.

### ManualMeetingProvider (`manual`)

No external API, no credentials, always "configured". `createMeeting()`
without a join URL (the automatic-trigger path when
`default_provider = manual`) produces a `pending` placeholder row for
the admin to fill in; supplying a join URL (the admin's own action)
produces `created` immediately. Validates the URL format
(`filter_var(..., FILTER_VALIDATE_URL)`) and fails safely (not a crash)
on a malformed one. `providerLabel` (e.g. `zoom_manual`,
`google_meet_manual`, `other`) is stored in `metadata.manual_label` —
descriptive only, never the `provider` column value.

### GoogleCalendarMeetProvider (`google_meet`)

Creates a Google Calendar event with Meet conference data:
`conferenceData.createRequest` (unique `requestId` per call),
`conferenceDataVersion = 1`, via `events.insert`/`events.update`. The
SDK (`google/apiclient` + `google/apiclient-services`) is isolated
behind `GoogleCalendarClient` (`GoogleCalendarSdkClient` is the only
class that ever instantiates `\Google\Client`/`\Google\Service\Calendar`
— mirrors `RazorpayGatewayClient`/`StripeGatewayClient`'s isolation).
The client converts the SDK's `Event` object to a minimal plain array
(`id`, `hangoutLink`, `conferenceData`) immediately — no raw SDK object
or full API response ever propagates past that boundary.

- **Event content**: `summary` = `"Lesson: {subject}"`; `description`
  = booking reference + duration only. Never price, wallet, provider
  payment ids, or internal metadata.
- **Attendees**: none added (business decision) — the event is created
  on the platform calendar only; the app's own notification pipeline
  is the source of truth for telling students/instructors about the
  link, since a calendar-invite notification policy isn't built this
  phase.
- **Async conference creation**: a join URL is never assumed to exist
  just because `events.insert`/`events.update` succeeded.
  `resultFromEvent()` inspects the returned `conferenceData` and maps
  to `Created` (a `video` entry point URI is present and status is
  `success`), `Failed` (status `failure`), or `Pending` (anything
  else, including no `conferenceData` back yet) — safe to retry via
  the "Retry Google Meet" admin action, which calls `updateMeeting()`
  (an `events.update` against the existing `provider_event_id` if one
  exists, otherwise a fresh `events.insert`).
- **Credential handling**: `MeetingSettings::google_credentials_json`
  stores the encrypted service-account JSON (`Crypt::encryptString`,
  same convention as `PaymentGatewaySettings`'s gateway secrets) — the
  admin settings page never renders it back after save; a blank submit
  keeps the existing value. `google_auth_type = 'oauth_user'` is a
  reserved, unimplemented option (no connection screen built this
  phase) — `isConfigured()` only ever returns true for
  `service_account`. Exception messages are sanitized
  (token/key-shaped substrings redacted, length-capped) before being
  persisted as `failure_reason` or logged.
- **Config status**: `GoogleCalendarConfigurationService::check()`
  (mirrors `PaymentGatewayConfigurationService`'s pattern exactly —
  pure settings/format inspection, no network call) sets
  `google_config_status` (`not_configured`/`incomplete`/`invalid`/`ready`)
  via the admin "Test Google Configuration" action.

### ZoomMeetingProvider (`zoom`) — Phase 11B, real integration

Schedules a Zoom meeting (type 2 — scheduled, never instant) under the
platform's own Zoom user via **Server-to-Server OAuth** — an
account-credentials token minted with Basic auth
(`client_id:client_secret`) and cached until shortly before expiry.
No third-party Zoom SDK (none is approved): all HTTP lives in
`ZoomApiClient` (Laravel HTTP client) behind the `ZoomMeetingClient`
contract — the only class in the codebase that talks to Zoom, mirroring
`GoogleCalendarSdkClient`'s isolation. The client whitelists exactly
six fields out of every response (`id`, `join_url`, `start_url`,
`password`, `timezone`, `status`) — no raw Zoom payload ever crosses
that boundary, and tokens live only in the cache entry and the
Authorization header.

- **Meeting payload**: `topic` = `"Lesson: {subject}"`; `agenda` =
  booking reference + duration only (never price/wallet/payment ids);
  `start_time`/`duration` from the booking; timezone falls back
  booking → `zoom_default_timezone` → app timezone. Settings:
  `join_before_host: false`, `waiting_room: true`,
  `mute_upon_entry: true`, `auto_recording: 'none'`.
- **Host account**: `zoom_host_user_id` (or `zoom_host_email`) — the
  platform's Zoom user, not the instructor's; per-instructor Zoom
  accounts are out of scope.
- **Storage**: Zoom's meeting id → `provider_meeting_id`; `start_url`
  → `host_url` (hidden, never serialized to students); the join
  password rides the same hidden `password` column the other providers
  use; `metadata` holds only `zoom_status`.
- **Unlike Google**, Zoom returns `join_url` synchronously — a
  successful create/update without one is treated as a failure, never
  a `pending` row.
- **Credential handling**: `zoom_client_secret` is encrypted
  (`Crypt::encryptString`), never re-rendered after save, blank submit
  preserves it — same rules as the Google credential JSON. Exception
  messages are sanitized (token/key-shaped substrings redacted) before
  persisting as `failure_reason`.
- **Config status**: `ZoomConfigurationService::check()` mirrors the
  Google/payment pattern with one deliberate difference: after local
  field/format checks pass, `ready` additionally requires
  `ZoomMeetingClient::validateCredentials()` to actually mint a token
  (so plausible-but-wrong credentials read `invalid`, never `ready`).
  That network call happens only inside the explicit admin "Validate
  Zoom Configuration" action — never on page load, and tests bind a
  fake client.
- **Retry semantics**: same-provider retry PATCHes the existing Zoom
  meeting (`updateMeeting`); a **cross-provider** retry (e.g. a failed
  Google row retried as Zoom) creates fresh — the old row's provider
  ids mean nothing to the new provider, and `persistResult()`
  overwrites them.
- **No Zoom webhooks** this phase (`zoom_webhook_secret`/
  `zoom_webhooks_enabled` deliberately not added — no route exists,
  asserted by test).

## Meeting settings

`MeetingSettings` (existing class, extended):

| Field | Purpose |
|---|---|
| `meetings_enabled` | Platform-wide kill switch — off blocks every provider, including Manual |
| `default_provider` | `manual` \| `google_meet` \| `zoom` — used by the automatic (listener) trigger; an unconfigured selection fails safely (status `failed`), never a silent fallback |
| `manual_provider_enabled` | Manual provider's own on/off switch |
| `create_after_paid_booking_confirmation` / `create_after_demo_booking_confirmation` | Per-kind auto-create toggles |
| `google_meet_enabled`, `google_calendar_id`, `google_auth_type`, `google_credentials_json` (encrypted), `google_credentials_configured`, `google_config_status`, `google_last_checked_at` | Google Calendar + Meet configuration and readiness |
| `zoom_enabled`, `zoom_account_id`, `zoom_client_id`, `zoom_client_secret` (encrypted), `zoom_host_user_id`, `zoom_host_email`, `zoom_default_timezone`, `zoom_config_status`, `zoom_last_checked_at` | Zoom Server-to-Server OAuth configuration and readiness (Phase 11B) |
| `student_join_url_visible`, `instructor_join_url_visible` | Visibility switches consumed by `StudentBookingResource` (instructor surface pending — see gaps) |

`default_provider = 'manual'` is **not** an off switch (unlike this
phase's first draft) — it is a real, working provider. The platform
off switch is `meetings_enabled`.

All of these are administered on a **dedicated Meeting Settings page**
(`MeetingSettingsPage`, `/admin/settings/meetings`, Platform navigation
group) — extracted from `PlatformFoundationSettingsPage` once the
Google + Zoom credential sections outgrew a general foundation page.
The "Test Google Configuration" and "Validate Zoom Configuration"
actions live there too. Shared provider plumbing:
`MeetingSettings::decryptedGoogleCredentials()`/
`decryptedZoomClientSecret()` are the single fail-closed decrypt
points, and the `BuildsSafeMeetingContent` /
`SanitizesProviderMessages` concerns (`app/Booking/Meetings/Concerns`)
are the single sources for safe event titles/descriptions and redacted
failure messages across Google and Zoom.

## Idempotency

`BookingMeetingService::createMeeting()`/`saveManualMeeting()`:

1. If a `booking_meetings` row already exists with `status = created`,
   return it untouched.
2. Otherwise re-check eligibility, resolve the provider, and either
   `createMeeting()` (no existing row) or `updateMeeting()` (a
   `pending`/`failed` row exists — retry) inside `DB::transaction()`.
3. All writes go through a single `firstOrNew(['booking_id' => ...])`
   + `fill()` + `save()` upsert helper, so "creating" a meeting twice
   is a no-op/update, never a duplicate row (also enforced by the
   unique `booking_id` constraint).

A duplicate/replayed payment webhook cannot reach this twice in the
first place: `BookingPaymentService::markPaid()`'s `assertReference()`
requires `payment_status === Pending`; a second delivery finds it
already `Paid` and is answered `ignored`, never re-confirming the
booking or re-dispatching `BookingConfirmed`.

## Failure handling

Every genuine failure (misconfigured/unresolvable provider, or an
exception mid create/update) sets `status = failed` and a sanitized
`failure_reason`, logs via `AuditTrailService::logSystem()` (never
`activity()` directly), and returns normally.
`BookingMeetingService::createMeeting()`/`saveManualMeeting()`/
`cancelMeeting()` **never throw** — a queued listener failing loudly
would retry the whole event needlessly, and a provider outage must
never touch payment or booking state. `meetings_enabled = false` on
the automatic path is a **silent no-op**, not a failure (a deliberate
admin choice, not a malfunction). No exception detail is surfaced to
students/instructors. Admins can always fall back to
`saveManualMeeting()` regardless of why a Google or Zoom attempt failed.

## Admin manual fallback

The "Create/Update Meeting" admin action lets an admin pick Manual,
Google Meet, or Zoom per booking, independent of `default_provider`
(Zoom appears in the select only when `isConfigured()` passes — an
admin can't pick a provider guaranteed to fail). If Google or Zoom
fails (misconfigured, API error, still-pending conference), the admin
can immediately paste a manual link instead via the same action, which
routes to `saveManualMeeting()` and overwrites the failed row with a
`created` manual one. "Retry Google Meet" / "Retry Zoom Meeting" are
offered only while the row belongs to that provider and `status` is
`pending`/`failed`.

## Visibility

- **Student** (`StudentBookingResource`, `booking-history.blade.php`):
  `meeting_url`/`meeting_password` only when `status === Confirmed`,
  the meeting's own `status === Created`, and
  `MeetingSettings::student_join_url_visible` is on; a safe
  `"Meeting link is being prepared."` message otherwise. `host_url` and
  `metadata` are never serialized (hidden at the model level too).
- **Instructor**: no dedicated "my bookings" surface exists yet (audit
  finding, unchanged by this phase) — a **documented gap**.
  `instructor_join_url_visible` and `BookingPolicy::manageMeeting()`
  (host-or-permission) are ready for whenever that UI lands.
- **Admin** (`BookingForm`, `BookingsTable`): full read access to all
  meeting fields (Filament is already admin-only) via a read-only
  summary bound to the `booking_meetings` relationship (status,
  provider, masked/truncated reference, join URL, failure reason, last
  updated) — mutation only via the table actions, never inline form
  editing, so eligibility/idempotency always run through
  `BookingMeetingService`.

## Deferred / explicitly out of scope this phase

- Zoom webhooks, Zoom Meeting SDK embedding, per-instructor Zoom
  accounts, and recurring Zoom meetings (Phase 11B integrated
  Server-to-Server meeting create/update/delete only).
- Google OAuth-user connection screen (`google_auth_type = 'oauth_user'`
  is reserved but unimplemented).
- Meeting cancellation cascading from booking cancellation (admin must
  use "Mark Meeting Cancelled" explicitly).
- `meeting_link_visible_before/after_minutes` time-window gating on the
  student-facing join link (pre-existing settings, still not wired to
  a visibility check — same gap as before).
- Wallet debit, wallet recharge, instructor payout, recording storage,
  attendance tracking, class completion — unchanged, not built here.
