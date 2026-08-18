# Zoom Activation & Staging Validation Runbook

Everything needed to turn Zoom on, in order, with nothing left to build.

> **Acceptance criterion for this runbook:** no step below requires
> application development. No Composer package, no migration, no
> controller, no webhook listener, no recording code. If a step here
> ever needs code, the implementation was incomplete.

Architecture and rationale live in `docs/meetings.md`. This file is the
procedure.

---

## 0. Prerequisites you must buy or provision

These are the real blockers — everything else is configuration.

- [ ] A **Zoom account with cloud recording**. Cloud recording requires
      a paid plan; the free tier has none, and without it there is
      nothing for SIRI to ingest.
- [ ] A **licensed host user** on that account. Meetings are created
      under one platform host (`zoom_host_user_id`), not under
      instructors' personal accounts.
- [ ] Admin access to the Zoom account settings and the Zoom App
      Marketplace (to create the server-side app).

### Concurrency limit — read this before launch

A single Zoom licensed user **cannot host multiple meetings at the same
time** beyond what the plan permits. SIRI runs one-to-one lessons that
routinely overlap.

**This is an account limitation, not an application one.** SIRI creates
every meeting under the one configured host, so if two lessons overlap
and the plan does not allow concurrent meetings for that user, the
second creation fails and surfaces as a meeting-creation error.

Before enabling Zoom as the default provider, confirm with Zoom what
your plan allows for concurrent meetings per licensed user, and compare
it against your peak overlapping-lesson count.

The scaling seam is already in place: host selection is a single method
(`ZoomMeetingProvider::hostUser()`) reading configuration. Moving to a
pool of licensed hosts later changes that method and its settings —
nothing in `Recording`, `Booking` or the ingestion pipeline. A host-pool
scheduler was deliberately **not** built now, because no current
requirement defines the pool size or allocation policy.

---

## 1. Snapshot current provider settings (READ ONLY)

Before changing anything, record:

- [ ] `default_provider`
- [ ] `google_meet_enabled`, `google_meet_recording_enabled`
- [ ] `zoom_enabled`, `zoom_recording_enabled`
- [ ] `recording_enabled`, `recording_retention_days`
- [ ] `RECORDING_STORAGE_DRIVER`

Rollback is "restore these values" — see the end of this file.

---

## 2. Create the Zoom server-side app

Zoom App Marketplace → Develop → Build App → **Server-to-Server OAuth**.

- [ ] Note the **Account ID**, **Client ID**, **Client Secret**
- [ ] Add only the scopes SIRI actually uses (least privilege):
      - meeting read + write — create, update and delete scheduled
        meetings under the platform host
      - cloud recording read — list and download recordings
      - user read — resolve the host user
- [ ] Do **not** grant account-wide administrative scopes beyond these.

> Zoom's scope names change between app types and revisions. Take the
> exact identifiers from the app's own Scopes screen at the time you
> build it, and grant the narrowest that cover the four capabilities
> above. If a call later fails on a missing scope, add that one scope —
> never a broad `admin:*` substitute.

---

## 3. Configure the webhook

In the same app → **Event Subscriptions** → add a subscription.

- [ ] Event notification endpoint URL:

      ```text
      https://<your-domain>/api/webhooks/meetings/recordings/zoom
      ```

- [ ] Subscribe to the **recording completed** event
      (`recording.completed`). Nothing else is required — SIRI
      acknowledges and ignores other recording events.
- [ ] Copy the **Secret Token** into SIRI (step 4) *before* clicking
      Validate — the endpoint answers Zoom's URL-validation challenge
      using that secret, and cannot validate without it.
- [ ] Click **Validate**. It must succeed before Zoom will deliver
      events.

The endpoint is CSRF-exempt (it lives in `routes/api.php`), rate
limited, verifies the signature before parsing, and answers the
validation challenge automatically. No code is needed for any of that.

---

## 4. Enter credentials in SIRI

Admin → Settings → Meetings → Zoom:

- [ ] Account ID, Client ID, Client Secret
- [ ] Host User ID (preferred) or Host Email
- [ ] Webhook Secret Token
- [ ] Default timezone (optional)

Secrets are encrypted at rest and never re-displayed. Leaving a secret
field blank keeps the stored value.

- [ ] Click **Validate Zoom Configuration** — it mints a token and
      discards it. It must report ready before continuing.

---

## 5. Configure Zoom account privacy — REQUIRED

SIRI's `RecordingPolicy` denies students and instructors. That governs
SIRI. **It does not govern Zoom**, which will happily email a recording
link to the meeting host unless the account is configured otherwise.

Without this section, an instructor may receive recording access from
Zoom directly, defeating the admin-only rule.

In Zoom account settings → Recording:

- [ ] **Cloud recording: ON** (this is SIRI's canonical source)
- [ ] **Local recording: OFF** — a local recording lands on the host's
      own computer, entirely outside SIRI's storage, retention and
      access control
- [ ] **Hosts can access their cloud recordings: restrict** — use the
      account's recording-management / admin-only controls so ordinary
      host users cannot view, download or share cloud recordings
- [ ] **Recording notifications to hosts: off** where the account
      permits, so no recording link is emailed to instructors
- [ ] **Auto-delete / recording sharing: sharing OFF**, no public links,
      no "anyone with the link"
- [ ] **Require passcode / restrict viewers** on any residual sharing
      surface
- [ ] Lock these settings at account level so an individual user cannot
      re-enable them

> Some of these controls require a business/enterprise-tier Zoom plan.
> If your plan cannot restrict host access to cloud recordings, then
> **the admin-only guarantee cannot be met end-to-end**, and Zoom
> recording should stay disabled until it can. The application will not
> be weakened to accommodate that; the requirement is the requirement.

**Honest boundary:** none of this prevents someone running third-party
screen-capture software on their own machine. That is a terms-of-service
matter, not something any application can technically prevent. Do not
claim otherwise to clients.

Also confirm:

- [ ] Cloud storage quota is sufficient for expected volume until SIRI
      ingests each recording. SIRI copies recordings into its own
      storage and applies its own retention; it does **not** currently
      delete the Zoom-side copy (see "Remaining decisions" below).

---

## 6. Enable Zoom in SIRI

Enable in this order, verifying between each:

- [ ] `zoom_enabled` = true — Zoom may now create meetings
- [ ] Leave `zoom_recording_enabled` OFF for now
- [ ] Leave `default_provider` on Google Meet for now

---

## 7. Staging validation

### Meeting lifecycle (recording still off)

- [ ] Set `default_provider` = Zoom
- [ ] Create a booking → a Zoom meeting is created under the platform
      host; `provider_meeting_id` and `join_url` are persisted
- [ ] Join as a test student — the join URL works, waiting room applies
- [ ] Confirm the meeting topic contains the booking reference and **no**
      student name, email or phone
- [ ] Confirm `start_url` is not visible anywhere in student-facing
      output
- [ ] **Reschedule** the booking → the same Zoom meeting is updated, not
      duplicated
- [ ] **Cancel** the booking → the Zoom meeting is deleted
- [ ] Confirm a booking created earlier under Google Meet is still
      Google Meet and still works

### Consent gate (before enabling recording)

- [ ] A lesson where either participant has not consented creates **no**
      recording row, and the Zoom meeting is created with
      `auto_recording` = none

### Recording

- [ ] Set `zoom_recording_enabled` = true
- [ ] Set `zoom_recording_webhooks_enabled` = true
- [ ] Create an eligible, consented lesson
- [ ] Confirm the Zoom meeting was created with cloud auto-recording on
- [ ] Join and confirm participants **see Zoom's recording indicator**
      (the notice must not be suppressed)
- [ ] Record a short session; end the meeting

Then observe, without forcing anything:

- [ ] `recording.completed` webhook arrives → 200, one
      `recording_provider_events` row, one queued job
- [ ] Recording progresses `pending → transferring → stored → available`
- [ ] The stored file is the **class video**, not the audio-only track
      or the chat log
- [ ] Storage verification passed before it became available
- [ ] Staged temp file was deleted; disk returned to baseline

### Reconciliation fallback

- [ ] Temporarily disable `zoom_recording_webhooks_enabled`, run a
      second recorded lesson, and confirm `recordings:capture` discovers
      and ingests it anyway
- [ ] Re-enable webhooks

### Privacy

- [ ] Admin with `View:Recording` can download via SIRI
- [ ] Student is **denied** (403)
- [ ] Instructor is **denied** (403)
- [ ] Admin without the permission is **denied**
- [ ] Neither participant received a "recording available" message
- [ ] Check the instructor's Zoom account: they must not be able to
      reach the cloud recording (this validates §5, not the application)

### Replay and duplicates

- [ ] Re-deliver the same webhook from Zoom's dashboard → 200
      `duplicate`, no second job
- [ ] Run `recordings:capture` twice more
- [ ] Assert: 1 recording row, 1 stored object, 1 downloaded copy

### Controlled failure

- [ ] Temporarily blank the Zoom client secret → capture fails with an
      auth classification, an operational alert is raised, and lesson,
      booking and payment state are untouched
- [ ] Restore the secret; use the admin **Retry ingestion** action and
      confirm recovery

---

## 8. Go live

- [ ] Decide `default_provider` (Google Meet or Zoom) deliberately
- [ ] Confirm the `siri-recordings` queue worker is running
      (`docs/recordings.md` §13)
- [ ] Monitor for the first several recordings: queue depth, job
      duration, staging disk, `recording_capture_failed` alerts, and
      Zoom cloud storage consumption

---

## Rollback

Least to most disruptive; the first two touch Zoom not at all.

1. **Stop recording ingestion:** `zoom_recording_enabled` = false.
   Zoom meetings keep working; no new recordings are registered.
   Existing recordings stay available to admins.
2. **Stop webhooks:** `zoom_recording_webhooks_enabled` = false. The
   endpoint returns 404; reconciliation still runs if recording is on.
3. **Stop using Zoom for new meetings:** set `default_provider` back to
   Google Meet. **Existing Zoom meetings keep working** — each meeting
   is operated through the provider on its own row.
4. **Disable Zoom entirely:** `zoom_enabled` = false. Zoom reports "not
   configured"; nothing else in the application is affected.
5. **Revoke credentials:** clear the Zoom secrets in settings and
   deactivate the app in the Zoom Marketplace.

Nothing in this runbook deletes a recording. Retention deletion happens
only via `recordings:expire`.

---

## Remaining product decisions

These are business decisions, not implementation gaps:

1. **Zoom-side source deletion.** SIRI copies each recording into its
   own storage and applies its own retention, but does not delete the
   Zoom cloud copy. Left alone, Zoom storage accumulates. Deleting the
   source after SIRI verification is technically straightforward but was
   not implemented, because it destroys the only other copy of a class
   recording and no current policy authorises that. Decide explicitly,
   then implement deliberately.
2. **Multi-segment lessons.** A lesson recorded in several
   start/stop sessions produces several artifacts; SIRI stores the
   preferred one and raises a `recording_multiple_artifacts` alert. If
   multi-part lessons become common, decide whether to store all
   segments.
3. **Concurrent host capacity.** See §0 — depends on the plan purchased.
