# Meeting Provider Production Checklist

Audit-readiness checklist for enabling real meeting providers
(Google Meet — Phase 11, Zoom — Phase 11B) in production. Complements
`docs/architecture/payment-gateway-production-checklist.md` (whose
"Meeting creation" section covers the payment↔meeting boundary rules)
and `docs/architecture/phase-11-meeting-creation-foundation.md` (the
design). Everything here is manual/operational — the automated
guarantees are already enforced by the focused test suites
(`BookingMeetingTest`, `ManualMeetingProviderTest`,
`GoogleCalendarMeetProviderTest`, `ZoomMeetingProviderTest`,
`ZoomApiClientTest`).

## Platform

- [ ] `MeetingSettings::meetings_enabled` deliberately on, and
      `default_provider` (`manual` / `google_meet` / `zoom`) is the
      intended one — an unconfigured default makes automatic creation
      fail safely (`status = failed`), it never silently falls back.
- [ ] `student_join_url_visible` / `instructor_join_url_visible`
      reviewed — these gate join-link exposure platform-wide.
- [ ] Manual provider fallback tested: with the real provider
      intentionally broken, the admin "Create/Update Meeting" action
      still saves a `manual` link for the same booking, updating (not
      duplicating) the failed row.

## Zoom (Server-to-Server OAuth)

- [ ] Zoom **Server-to-Server OAuth app** created on the platform's
      Zoom account (Marketplace → Develop → Server-to-Server OAuth) —
      not a general OAuth app, no user-consent screen exists in this
      codebase.
- [ ] Required meeting scopes granted to that app (at minimum
      `meeting:write:meeting:admin` / the account-level equivalents for
      create, update, delete).
- [ ] `zoom_account_id`, `zoom_client_id`, `zoom_client_secret` entered
      through the Meeting Settings page (`/admin/settings/meetings`)
      only — the secret is
      encrypted at rest, never re-displayed after save, and a blank
      re-submit preserves it. Never commit credentials or put them in
      code/config files.
- [ ] `zoom_host_user_id` (or `zoom_host_email`) set to the platform
      host user meetings are scheduled under — and that user's Zoom
      license allows scheduled meetings of the platform's lesson length.
- [ ] "Validate Zoom Configuration" clicked and showing **ready** —
      note this mints a real token; `ready` requires an actual
      successful token grant, not just well-shaped fields.
- [ ] A real test meeting created end-to-end from the Booking admin
      ("Create/Update Meeting" → Zoom) for a confirmed booking; join
      URL opens a real Zoom meeting with waiting room on,
      join-before-host off, auto-recording off.
- [ ] Student view checked for that booking: join link (and passcode)
      visible only after confirmation; **start_url is not present
      anywhere in the student-facing HTML/JSON**.
- [ ] Duplicate confirmation/webhook replay does not create a second
      Zoom meeting (one `booking_meetings` row per booking, unique
      constraint + idempotent service).
- [ ] Cancelled/expired bookings confirmed to never get a Zoom meeting.
- [ ] Application logs and the Activity Log inspected after the test
      run: no access token, client secret, or raw Zoom response
      anywhere (failure reasons are sanitized — token-shaped substrings
      are redacted before storage).
- [ ] No Zoom webhook endpoint exists (`php artisan route:list` shows
      no zoom route) — deliberate; meeting status is not synced from
      Zoom-side events this phase.

## Google Calendar + Meet

- [ ] Service-account JSON uploaded via the Meeting Settings page
      (`/admin/settings/meetings`) only;
      "Test Google Configuration" shows **ready** (format inspection —
      no network call), and a real sandbox event was created end-to-end
      on the configured `google_calendar_id` with a working Meet link.
- [ ] The service account has write access to that calendar (shared to
      the service account's `client_email`).
- [ ] Pending-conference handling observed: a `pending` row (no join
      URL yet) shows the student "Meeting link is being prepared." and
      "Retry Google Meet" resolves it.
- [ ] Logs/Activity Log inspected: no credential JSON or token material.

## Explicitly not built (do not enable ad hoc during rollout)

- Zoom webhooks / webhook secret settings.
- Zoom Meeting SDK embedding, per-instructor Zoom accounts, recurring
  meetings.
- Recording storage (the `recording_*` settings are stored defaults
  only), attendance tracking, wallet debit/recharge, instructor payout.
