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
- [ ] Add only the granular scopes for the endpoints SIRI actually calls
      (`app/Booking/Gateways/ZoomApiClient.php` is the only class that
      talks to Zoom). Server-to-Server apps use the `:admin` variants:

      | Endpoint SIRI calls | Why | Granular scope |
      |---|---|---|
      | `POST /users/{hostUserId}/meetings` | create the lesson meeting | `meeting:write:meeting:admin` |
      | `PATCH /meetings/{meetingId}` | reschedule / align on adoption | `meeting:update:meeting:admin` |
      | `GET /meetings/{meetingId}` | read back after update; inspect before adoption | `meeting:read:meeting:admin` |
      | `GET /users/{hostUserId}/meetings?type=upcoming` | reconcile an ambiguous create | `meeting:read:list_meetings:admin` |
      | `DELETE /meetings/{meetingId}` | cancel; delete the validation probe meeting | `meeting:delete:meeting:admin` |
      | `GET /meetings/{meetingId}/recordings` + the file `download_url` | find and fetch the class recording | `cloud_recording:read:list_recording_files:admin` |
      | `DELETE /meetings/{meetingId}/recordings?action=trash` | move Zoom's copy to the recoverable trash after SIRI's copy is verified — only when "Trash Zoom Copy After SIRI Has Verified Its Own" is on | `cloud_recording:delete:meeting_recording:admin` |
      | `POST https://zoom.us/oauth/token` | mint the account token | no scope (account credentials grant) |

      No `user:*` scope is needed — SIRI never reads the host user; the
      host id/email is configuration. The names above are on Zoom's
      official granular scope list; confirm each against the endpoint's
      own page and the app's Scopes screen when you build the app, and
      grant nothing else.
- [ ] Event subscription (webhooks): `recording.completed` only (§3).
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

## 5a. Zoom portal settings required for HOSTLESS lessons — REQUIRED

SIRI sends the hostless meeting settings on every create and update
(`docs/meetings.md` §4). Zoom applies **account and user settings on
top of the API request**, and a setting that is locked at account
level silently wins. The portal must therefore allow what SIRI asks
for. Zoom web portal → **Admin → Account Management → Account
Settings**, then also check the room01 **user's** own settings
(Admin → User Management → Users → room01 → Settings) inherit them:

**Meeting tab → Schedule Meeting**

- [ ] **Join before host: ON**, with "Participants can join … before
      start time" allowing at least **5 minutes** (or "anytime"). Not
      locked OFF.
- [ ] **Waiting Room: OFF** and **not locked ON**. If your organisation
      needs the waiting room elsewhere, do not lock it; SIRI disables it
      per meeting.
- [ ] **Require a passcode when scheduling new meetings: ON** (SIRI
      sets its own passcode; this only ensures the account cannot strip
      it).
- [ ] **Embed passcode in invite link for one-click join: ON** — the
      `join_url` SIRI hands out must carry the passcode, or participants
      are prompted for one they never received.
- [ ] **Only authenticated users can join meetings: OFF** and not
      locked. Instructors and students are guests to Zoom.
- [ ] **Personal Meeting ID (PMI)**: leave as is; SIRI never uses it
      (`use_pmi: false`).
- [ ] **Allow participants to join before host** must not be overridden
      by a **Meeting → In Meeting (Advanced) → "Allow users to select
      … waiting room"** lock.

**Recording tab**

- [ ] **Cloud recording: ON**; **Automatic recording: ON, "Record in
      the cloud"**, and allow hosts to enable it per meeting (SIRI sets
      `auto_recording: cloud` per lesson).
- [ ] **Record active speaker with shared screen / gallery view**
      enabled as needed (`config('recordings.zoom.preferred_layouts')`
      chooses which file SIRI ingests).
- [ ] The §5 privacy controls above stay in force.

**Licence**

- [ ] room01 is a **Licensed (Pro)** user. Basic users cannot record to
      the cloud and hostless meetings on Basic are time-limited.

If any of these is locked the other way, the created meeting will show
it (§7a step 3): SIRI cannot override a locked account setting, and the
lesson would need the host to join — exactly what this phase removes.

---

## 6. Enable Zoom in SIRI

Enable in this order, verifying between each:

- [ ] `zoom_enabled` = true — Zoom may now create meetings
- [ ] Leave `zoom_recording_enabled` OFF for now
- [ ] Leave `default_provider` on Google Meet for now
- [ ] Complete §6a (host registration, preflight, capacity reservation)
      before any Zoom lesson is sold

---

## 6a. Host capacity reservation (one Zoom Pro user = one meeting at a time)

Do this **before** `default_provider` is ever set to Zoom anywhere
students can book. Architecture: `docs/meetings.md` §4a. Zoom is
capacity-governed whether or not the switch is on; the switch decides
only whether NEW reservations may be granted, and the settings page
refuses Zoom as default while it is off.

Enable, in this order:

- [ ] `php artisan meetings:zoom-hosts:register` — registers the
      configured `zoom_host_user_id` / `zoom_host_email` as a platform
      host with capacity 1. Identity only; no credential is copied.
- [ ] `php artisan meetings:zoom-hosts:preflight` — read-only. Lists
      every upcoming Zoom-bound booking without a reservation and every
      planned overlap.
- [ ] `php artisan meetings:zoom-hosts:backfill` (dry run), then
      `--apply` — reserves capacity for the bookings that fit, earliest
      first, and pins legacy Zoom meetings to their host. It reports
      what does **not** fit and leaves it untouched.
- [ ] Resolve what did not fit by hand: reschedule to a free hour, or
      cancel. Do not "switch" a booking whose Zoom meeting already
      exists to another provider — that is not supported.
- [ ] Re-run `meetings:zoom-hosts:preflight` until it reports
      **No findings**. The settings page enforces the same check and
      will refuse the next step otherwise.
- [ ] Settings → Meetings → Zoom → **Reserve Zoom Host Capacity** = on.
- [ ] Only now may `default_provider` be set to Zoom (staging first,
      §7). Leave **Host Turnaround Buffer** at 5 minutes unless staging
      shows the host needs longer between classes.
- [ ] Confirm on staging: two test instructors booking the same hour →
      the second is refused with "This time is fully booked on our video
      platform …" (or, with **When no Zoom host is free** set to Google
      Meet, accepted on Google Meet with the admin notification "Lesson
      Moved to Google Meet — Host Must Join"); a reschedule onto a taken
      hour is refused and the booking keeps its original time (or moves
      to Google Meet when the fallback is on and no Zoom meeting exists
      yet); cancelling frees the hour.

**Rollback — in this order:**

1. Set `default_provider` back to **Google Meet**. New bookings are
   accepted for Meet and pinned to it. Existing Zoom bookings keep their
   pin, their reservations and their meetings.
2. Only then switch **Reserve Zoom Host Capacity** off (the page refuses
   the reverse order). From here: any new Zoom-bound acceptance is
   refused, never accepted unreserved; pending Zoom holds keep their
   reservations and are released by the normal expiry/cancel path;
   reserved bookings still get their Zoom meeting on their host;
   reschedules of reserved bookings still check capacity; a Zoom-bound
   booking with no reservation gets no meeting and cannot be
   rescheduled until backfilled. Nothing is deleted; no migration is
   undone.
3. If a pending Zoom-bound hold must still be honoured after rollback,
   let it settle (its reservation exists) or run
   `meetings:zoom-hosts:backfill --apply` for the ones that have none.

---

## 6b. Staging readiness — settings, variables and the auth-only check

Everything Zoom reads lives in **Meeting Settings** (database, group
`meeting`, encrypted where marked). There are no separate Zoom `.env`
variables for credentials; do not add any.

| Setting (`meeting.*`) | Staging value for room01 |
|---|---|
| `meetings_enabled` | true |
| `default_provider` | **google_meet** — leave it; the test lesson is created for Zoom by the admin action (§7a step 2) |
| `zoom_enabled` | true |
| `zoom_account_id`, `zoom_client_id`, `zoom_client_secret` (encrypted) | the staging Server-to-Server app |
| `zoom_host_user_id` **or** `zoom_host_email` | the ONE licensed virtual host (room01) |
| `zoom_default_timezone` | e.g. `Asia/Kolkata` |
| `zoom_webhook_secret` (encrypted) | the app's Secret Token |
| `zoom_recording_enabled`, `zoom_recording_webhooks_enabled` | true (after §5 privacy settings) |
| `zoom_host_capacity_enabled` | true — after `meetings:zoom-hosts:register` and a clean preflight (§6a) |
| `zoom_host_capacity_buffer_minutes` | 5 |
| `zoom_capacity_fallback_provider` | `google_meet` once Google Meet is configured and a platform-host rota exists; null (refuse) otherwise |
| `recording_enabled`, `recording_retention_days` (30), `recording_student_playback_enabled` (true on staging only) | recording policy — `docs/recordings.md` |
| `recording_drive_root_folder_id`, `recording_drive_shared_drive_id`, `platform_meeting_account`, `google_credentials_json` | the staging Google Workspace user's Drive — `docs/deployment/recording-cutover.md` |

Also `FeatureSettings::recording_enabled` = true and the test student's
country must allow the `recording_availability` country feature.

Deployment variables already used by this repository (`.env`):

| Variable | Staging value |
|---|---|
| `RECORDING_STORAGE_DRIVER` | `google_drive` |
| `RECORDING_QUEUE_DRIVER` / `RECORDING_QUEUE_RETRY_AFTER` | `database` / `3900` — with the `recordings` worker running (cutover runbook §7) |
| `RECORDING_ZOOM_DOWNLOAD_TIMEOUT`, `RECORDING_ZOOM_CONNECT_TIMEOUT`, `RECORDING_ZOOM_MAX_REDIRECTS` | defaults (900 / 15 / 5) |
| `RECORDING_MAX_SOURCE_BYTES`, `RECORDING_STAGING_STALE_HOURS` | defaults |
| the `notifications` queue worker | running (booking notifications) |

**Auth-only check — creates nothing at Zoom, prints no secret:**

```bash
php artisan meetings:zoom:check-auth
```

It reports each setting as configured/not configured, whether the
platform host is registered, and mints one OAuth token
(`POST /oauth/token`). Token minting proves the credentials, not the
scopes — scopes are exercised only by the first real call. The admin
panel's **Validate Zoom Configuration** action goes further and creates
then deletes a temporary meeting; run it only once a probe meeting on
the staging account is acceptable.

---

## 7. Staging validation

### Meeting lifecycle (recording still off)

- [ ] Set `default_provider` = Zoom
- [ ] Create a booking → a Zoom meeting is created under the platform
      host; `provider_meeting_id` and `join_url` are persisted
- [ ] Join as a test student — the join URL works with no waiting room;
      before five minutes to the start Zoom shows "waiting for the
      scheduled time", not "waiting for the host"
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

## 6c. Creating the first Zoom canary booking (Google Meet stays the default)

**A booking whose meeting already exists is never switched.** Choosing
Zoom in Admin → Bookings → *Create/Update Meeting* for a booking that
already has a created Google Meet meeting is refused with "Switching it
to zoom is not supported" (it used to report "Meeting created" while
changing nothing — fixed). The existing meeting and the participants'
link stay exactly as they are; there is no migration workflow for a live
meeting, by design.

The supported route is to decide the provider **before the meeting is
created**, for one booking, without touching `default_provider`:

1. Preconditions: `meetings:zoom:check-auth` exit 0; host registered;
   preflight clean; **Reserve Zoom Host Capacity** on (a pin reserves the
   host immediately and is refused while the switch is off).
2. Create a **paid** lesson for the test student with the test
   instructor. Do not pay yet. A paid booking sits in `pending` with a
   payment hold and **no meeting row** — that is the window. (A free
   demo auto-confirms and gets its meeting at once, so it cannot be used
   as the canary unless automatic demo meeting creation is off.)
3. Pin it, either from the admin table — Admin → Bookings → row →
   **Pin Meeting Provider** → Zoom (the action is shown only while the
   booking has no created meeting; the *Pinned Provider* column, hidden
   by default, shows the result) — or from the shell:

   ```bash
   php artisan meetings:pin-provider BK-XXXXXXXXXX --provider=zoom --admin=<admin id or email>
   ```

   `--admin` is a SIRI administrator (super_admin, or a user holding
   `Manage:BookingMeeting`), not the Zoom host account.

   Verify: `bookings.meeting_provider_intent = zoom`;
   `meeting_host_reservations` one `active` row for the booking with
   `expires_at` = the payment hold; `activity_log`:
   `meeting_provider_pinned`. Refused (nothing changes) if a meeting
   already exists, if the host has no room at that hour, if the
   reservation switch is off, or if Zoom is not configured.
4. Pay for the lesson. Payment settles → the booking confirms → the
   normal listener creates the meeting **on Zoom**, hostless, on the
   reserved host; the reservation's expiry is cleared.
5. Continue with §7a from step 3 (inspect the created meeting).

To take a pinned-but-unpaid booking back to the default, run the same
command with `--provider=google_meet`; the Zoom reservation is released.
Once a meeting exists, the only ways out are cancelling the booking
(which deletes the meeting at its provider) or booking again.

---

## 7a. Real end-to-end staging test — one virtual host (room01), one lesson

Hostless provisioning shipped in Phase 4 (`docs/meetings.md` §4). The
virtual host **must not join** at any point of this test; if the
meeting cannot be entered without it, a Zoom portal setting from §5a
is locked and must be fixed before continuing. Do not hand-edit the
meeting in the Zoom portal — that would validate settings SIRI does not
produce.

Preconditions: §1–§6b complete; the `recordings` and `notifications`
queue workers running; one test instructor and one test student
(staging accounts); `default_provider` still Google Meet.

**Manual validation (exact order):**

| # | Step | Check in the application / database / Zoom |
|---|---|---|
| 1 | `php artisan meetings:zoom:check-auth` | exit 0; every Zoom setting "configured"; host room01 registered; "OAuth token acquired: yes"; no secret printed. |
| 2 | Create one staging lesson for room01: book a demo/paid lesson (instructor ↔ student) ≥ 30 min ahead, then Admin → Bookings → **Create/Update Meeting** → **Zoom** | `bookings`: `status=confirmed`. `meeting_host_reservations`: one `active` row on room01's host, `occupies_from/until` = lesson ± 15 min ± 5 min UTC. `booking_meetings`: `provider=zoom`, `status=created`, `provider_meeting_id`, `platform_meeting_host_id`, `join_url` set, `host_url`/`password` hidden. `activity_log`: `meeting_created`. `recordings`: one `pending` row. |
| 3 | Inspect the created meeting in the Zoom portal (room01 → Meetings → the lesson) | Meeting ID is generated (not room01's PMI); **Join before host: on, 5 minutes**; **Waiting room: off**; **Passcode: set** (10 characters) and embedded in the invite link; **Alternative hosts: none**; **Automatic recording: cloud** (only if the lesson is recording-eligible); agenda `Booking reference: BK-…` with no participant names; topic `Lesson: <subject>`. Any deviation = a locked portal setting (§5a). |
| 4 | Do **NOT** join as room01 | Nobody opens `start_url`; the host stays absent for the whole test. |
| 5 | Instructor joins via the SIRI join link inside the window | Before T−5 min Zoom shows "waiting for the scheduled time" (not "waiting for host"); from T−5 the instructor is in the meeting as a **participant** — no host controls, no co-host badge. SIRI: the link was shown only inside `meeting_link_visible_before_minutes`; no `start_url` in participant output. |
| 6 | Student joins via the SIRI join link | Student enters directly (no waiting room, no passcode prompt because it is embedded); both listed as participants; no host in the participant list. |
| 7 | Confirm audio, video and screen sharing work for both | Both can unmute, start video and share the screen without a host present. |
| 8 | Confirm cloud recording began automatically | Zoom's recording indicator visible to both from the first join; no one pressed Record. |
| 9 | Both participants leave | Meeting ends on its own when the last participant leaves; SIRI state unchanged (`bookings.status` moves only through lesson completion). |
| 10 | Verify the recording appears in Zoom | room01 → Recordings: one cloud recording for the meeting id, processing then available (minutes). |

**Then let the pipeline finish (unchanged from Phase 2/3):**

| # | Step | Check |
|---|---|---|
| 11 | Webhook `recording.completed` arrives | **200** `{"status":"accepted"}`; `recording_provider_events` one row `queued`; redelivery → `duplicate`. |
| 12 | Capture runs on the `recordings` worker | `recordings`: `pending → transferring → stored → available`; `storage_driver=google_drive`; `storage_path` = SIRI's Drive file id; `expires_at = recorded_at + 30 days`; staging dir empty; only `zoom.us`/`*.zoom.us`/`*.zoom.com` hosts contacted. |
| 13 | Student → Watch | 200 page; `206` ranged stream; `activity_log`: `recording_playback_opened` (needs `recording_student_playback_enabled` on staging and the lesson outcome finalised). |
| 14 | Instructor tries watch and download URLs | **403** both; `activity_log`: `recording_access_denied`. |
| 15 | `php artisan meetings:zoom-hosts:preflight` | exit 0. |

Zoom-side original: still in room01's cloud recordings — SIRI does not
delete it ("Remaining product decisions").

---

## 7b. Real recording pipeline validation — one lesson, end to end

Preconditions: §7a passed (hostless meeting ran and Zoom produced a
recording); `zoom_recording_enabled`, `zoom_recording_webhooks_enabled`,
`recording_enabled` and `FeatureSettings::recording_enabled` on;
**"Trash Zoom Copy After SIRI Has Verified Its Own" OFF** for this
first run; the `recordings` worker running; `recording_student_playback_enabled`
on **staging only**; `recordings:capture` scheduled (*/15).

Zoom app → Feature → Event Subscriptions: endpoint
`https://<staging-host>/api/webhooks/meetings/recordings/zoom`, event
**Recording → "All Recordings have completed"** (`recording.completed`)
only; Secret Token copied into `zoom_webhook_secret`; **Validate**
succeeds (the endpoint answers the challenge with the seconds-based
signature check, commit ≥ e0721a76).

| # | Step | Verify |
|---|---|---|
| 1 | Create a SIRI staging Zoom lesson (§7a steps 1–3) | `booking_meetings.provider=zoom`, `status=created`; `recordings`: one `pending` row, `provider=zoom`, `idempotency_key=recording:<meeting id>`; `meeting_host_reservations`: one active row. |
| 2 | Host does not join | Nobody opens `start_url`. |
| 3 | Instructor and student join via SIRI links | Both present as participants; no host. |
| 4 | Automatic recording starts | Recording indicator on from the first join. |
| 5 | Participants leave | Meeting ends by itself. |
| 6 | Zoom produces `recording.completed` | Zoom portal → Recordings: files present (shared screen with speaker view MP4, audio-only M4A, transcript). Zoom app → Event subscriptions log: one delivery, HTTP **200**. |
| 7 | Verify the webhook was received | `recording_provider_events`: one row `event_type=recording.completed`, `processing_status=queued`, `meeting_reference=<meeting id>`, `booking_meeting_id` set; raw payload, `download_token` and `download_url` **absent** from the row. Re-send from the Zoom log → `{"status":"duplicate"}`, still one row. An unsigned POST → 401; a POST naming another `account_id` → 422. |
| 8 | Verify the capture job was queued | `jobs` (connection `recordings`) shows one `CaptureLessonRecordingJob` for the recording id, or the worker log shows it consumed. A second delivery queues nothing (unique per recording + `duplicate`). |
| 9 | Verify the canonical MP4 was downloaded | Worker log: `listMeetingRecordings` then one download; the selected file is the **MP4 `shared_screen_with_speaker_view`** — never the M4A audio-only or the transcript (`config('recordings.zoom.preferred_layouts')`). Hosts contacted: only `zoom.us` / `*.zoom.us` / `*.zoom.com`; bearer header, never a token in a URL. `storage/app/private/recording-ingestion/` empty afterwards. |
| 10 | Verify the private storage object exists | Drive: `<root>/YYYY/MM/lesson-BK-….mp4`, no sharing link, owned by the platform account. `recordings.storage_driver=google_drive`, `storage_path` = that file id (never a Zoom URL). |
| 11 | Verify the row became AVAILABLE | `recordings.status=available`; `size_bytes`, `storage_checksum`, `mime_type=video/mp4`, `duration_seconds`, `recorded_at`, `available_at`, `expires_at = recorded_at + 30 days`; `provider_reference` = Zoom's file id. `activity_log`: `recording_available`; **no** `recording_source_disposed` (switch off) and **no** participant notification. Zoom still holds the original. |
| 12 | Log in as the student and play it | My Bookings → the lesson → **Watch**: 200 (the lesson outcome must be finalised completed first). `activity_log`: `recording_playback_opened`. |
| 13 | Seek through the recording | Devtools: first request `Range: bytes=0-` → `206` with `Content-Range: bytes 0-N/total`; seeking forward and back → further `206`s, no reload, no error. |
| 14 | Log in as the instructor and verify denial | Watch URL → 403; admin download URL → 403; no recording on the instructor's lesson; `activity_log`: `recording_access_denied`. Repeat with an unrelated student (403) and signed out (redirect to login). An admin with `View:Recording` downloads successfully. |
| 15 | Verify no Zoom share URL appears anywhere | Search the student and instructor pages, the API resources and the notification inbox for `zoom.us/rec`, `download_url`, `share_url`, `play_url`, `passcode`: none. `recordings`, `recording_provider_events` and `booking_meetings.metadata` contain no Zoom URL or token. |

Only after 11–15 pass, and only if the retention decision has been
taken: switch **Trash Zoom Copy After SIRI Has Verified Its Own** on,
run one more lesson, and confirm `recording_source_disposed` in the
audit log and the file in Zoom's trash (recoverable), not deleted.

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

1. **Zoom-side source disposal (implemented Phase 5, ships OFF).**
   SIRI copies each recording into its own storage and applies its own
   retention (30 days from the recording time, `docs/recordings.md`
   §11a) to that copy. With Meeting Settings → Zoom → **Trash Zoom
   Copy After SIRI Has Verified Its Own** switched on, the Zoom cloud
   copy is moved to the account's **recoverable trash** — never
   permanently deleted — strictly after the SIRI copy has been stored,
   read back and matched and the row is `available`. A Stored-but-
   unverified copy, a verification mismatch or any failure leaves the
   source in place, audited. Leave it OFF for the first real staging
   recording so the original stays available for comparison; decide
   deliberately afterwards. Until then Zoom's own auto-delete (Account
   Settings → Recording) governs the original.
2. **Multi-segment lessons.** A lesson recorded in several
   start/stop sessions produces several artifacts; SIRI stores the
   preferred one and raises a `recording_multiple_artifacts` alert. If
   multi-part lessons become common, decide whether to store all
   segments.
3. **Concurrent host capacity.** See §0 — depends on the plan purchased.
