# Class Recording — Staging Validation & Production Cutover Runbook

Operator runbook for turning on Google Meet recording acquisition and
Drive storage. Architecture and troubleshooting live in
`docs/recordings.md`; this file is the *procedure*.

Every step is ordered so that the riskiest change — the domain-wide
delegation grant, which the **existing** Google Meet booking integration
also depends on — is snapshotted before it is touched and verified
immediately after.

---

## 0. The one hazard to understand first

The service account's domain-wide delegation grant is **all-or-nothing
per token request**. If a client requests a scope the grant does not
authorize, Google rejects the *entire* token request with
`unauthorized_client` — not just the extra scope.

This is not theoretical. A read-only probe against the live
`@sirieducation.com` service account on 2026-08-18 produced exactly
this:

```text
calendar              GRANTED
meetings.space.ro     FAIL  unauthorized_client
drive.meet.readonly   FAIL  unauthorized_client
drive.file            FAIL  unauthorized_client
ALL FOUR TOGETHER     FAIL  unauthorized_client
```

**Consequence:** the moment recording code requests its scopes without
the grant being updated, it fails. And a *badly* updated grant can break
meeting creation for every booking. Follow the order below.

---

## 1. Pre-flight snapshot (READ ONLY — do this first)

Record, before changing anything:

- [ ] Google Cloud **project id** holding the service account
- [ ] Service-account **client id** (the numeric OAuth client id used in
      the delegation grant) — not the key, not the private key
- [ ] **Exact current scope string** from the Workspace admin console
      delegation entry → paste into the rollback note below
- [ ] Impersonated account (`meeting.platform_meeting_account`)
- [ ] Current settings: `recording_enabled`,
      `google_meet_recording_enabled`, `google_config_status`
- [ ] `RECORDING_STORAGE_DRIVER` currently in effect

### Rollback note template — fill in BEFORE step 3

```text
DATE:
WORKSPACE ADMIN CONSOLE → Security → Access and data control
  → API controls → Domain-wide delegation
CLIENT ID:                <service account numeric client id>
SCOPES BEFORE CHANGE:     <paste the exact comma-separated list>
RESTORE BY:               replacing the scope list with the line above
```

> As of the last audit the grant authorized **only** the Calendar scope.
> Confirm this yourself rather than trusting that statement — the grant
> may have been edited since.

### Verify current state without touching Google

```bash
php artisan tinker --execute='$s=app(\App\Settings\MeetingSettings::class);
 echo $s->google_config_status.PHP_EOL;'
```

---

## 2. Enable the required APIs

In the Google Cloud project from step 1:

- [ ] Google Calendar API *(already enabled — Meet creation works)*
- [ ] **Google Meet REST API**
- [ ] **Google Drive API**

Do not enable anything else. Workspace Events and Pub/Sub are **not**
required for V1 — recording discovery uses bounded reconciliation.

---

## 3. Update the domain-wide delegation grant

Workspace admin console → Security → Access and data control → API
controls → Domain-wide delegation → edit the existing entry for the
service account's client id.

Set the scope list to **all four, as one edit**:

```text
https://www.googleapis.com/auth/calendar
https://www.googleapis.com/auth/meetings.space.readonly
https://www.googleapis.com/auth/meetings.space.created
https://www.googleapis.com/auth/meetings.space.settings
https://www.googleapis.com/auth/drive.meet.readonly
https://www.googleapis.com/auth/drive.file
```

- [ ] Rollback note from step 1 is filled in
- [ ] Calendar scope **retained** (removing it breaks all meeting creation)
- [ ] Saved as a single edit

**Do NOT widen to `drive` or `drive.readonly`** to resolve a permission
error. Either exposes the whole Workspace account's Drive. If one of the
four proves insufficient, stop, capture the exact failing API call and
its error, and report the narrowest scope actually required before
changing anything further.

Grants can take a few minutes to propagate.

---

## 4. Verify the grant — and that nothing broke

**4a. Scope probe (read-only, creates nothing):**

```bash
php artisan tinker
>>> app(\App\Booking\Gateways\GoogleCalendarSdkClient::class)
      ->verifyTokenAcquisition(app(\App\Settings\MeetingSettings::class)->decryptedGoogleCredentials(),
                               app(\App\Settings\MeetingSettings::class)->platform_meeting_account);
>>> app(\App\Booking\Gateways\GoogleMeetSdkClient::class)->verifyTokenAcquisition(/* same two args */);
>>> app(\App\Booking\Gateways\GoogleDriveSdkClient::class)->verifyTokenAcquisition(/* same two args */);
```

All three must return without throwing.

**4b. REGRESSION GATE — existing meeting creation must still work.**

- [ ] Create a normal booking that produces a Meet conference
- [ ] `booking_meetings.provider_meeting_id` populated (meeting code)
- [ ] Join URL resolves to a real Meet
- [ ] Calendar event exists, organized by the impersonated account

**If this fails: restore the prior scope list from the rollback note
immediately, then debug.** Do not proceed to recording testing with
meeting creation broken.

---

## 5. Shared Drive and root folder

- [ ] Create an organization-owned **Shared Drive** (e.g. `SIRI Education`)
- [ ] Create a `Recordings` folder inside it
- [ ] Grant the impersonated platform account **Content manager** on the
      Shared Drive — nothing wider
- [ ] **No** public, anyone-with-link, or domain-wide reader access
- [ ] Copy the folder id from its URL → `recording_drive_root_folder_id`
- [ ] Copy the Shared Drive id → `recording_drive_shared_drive_id`

Both are ids, not secrets, but there is no reason to circulate them.

---

## 6. Storage configuration

```env
RECORDING_STORAGE_DRIVER=google_drive
```

Leave `RECORDING_STORAGE_DISK` alone; it applies only to the filesystem
driver (and is the future S3 switch).

---

## 7. Recordings worker

Verified values from `config/queue.php` and the job class:

| Setting | Value |
|---|---|
| connection | `recordings` (database driver) |
| queue | `recordings` |
| `retry_after` | `3900` |
| job `timeout` | `3600` |
| job `tries` | `1` |
| retry_after > timeout | **yes** (required) |

Supervisor program:

```ini
[program:siri-recordings]
command=php /path/to/artisan queue:work recordings --queue=recordings --timeout=3600 --tries=1 --sleep=3
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3700
```

- [ ] `numprocs=1` for the first production recordings — do not raise
      concurrency until the flow is proven
- [ ] `stopwaitsecs` exceeds the job timeout so a deploy cannot kill a
      transfer mid-flight
- [ ] Worker starts, consumes a job, and Supervisor restarts it after a
      manual `kill`

Smoke test (safe — the job no-ops on an unknown id):

```bash
php artisan tinker --execute='\App\Booking\Jobs\CaptureLessonRecordingJob::dispatch("00000000-0000-0000-0000-0000000000ff");'
php artisan queue:work recordings --queue=recordings --once --stop-when-empty
```

- [ ] Scheduler shows `recordings:capture` (*/15) and `recordings:expire` (daily)

---

## 8. Disk headroom

Only matters if the streamed fallback runs (see §10). Record before the
first real recording:

- [ ] Free disk on the worker host
- [ ] `storage/app/private/recording-ingestion/` exists with `0700` perms
      (created on first use)
- [ ] Headroom ≥ the largest expected recording × concurrent transfers

`RECORDING_MAX_SOURCE_BYTES` (default 5 GB) is the hard ceiling per
recording.

---

## 9. Enable acquisition

- [ ] `meeting.recording_enabled` = true
- [ ] `meeting.google_meet_recording_enabled` = true

Both ship OFF so steps 2–3 cannot be skipped.

**Leave `meeting.recording_student_playback_enabled` OFF for now.** It
is the SRS §12.20 access-policy switch (students watch their own
recordings inside SIRI) and is independent of acquisition: recordings
are captured, stored and retained for administrators whether or not it
is on. Turn it on only after §10–§11 below have proven the pipeline
and after the student-facing check in §11a — it is a product
activation, not a deploy step.

**Eligibility check (negative test) — do this before the positive one:**

- [ ] With `meeting.google_meet_recording_enabled` still OFF, confirming a
      lesson creates **no** `recordings` row and the application log
      shows `Lesson recording not registered.` with reason
      `provider_capability_missing`. (Consent is platform-wide since
      2026-09-05 and no longer a per-profile gate.)

---

## 10. One controlled production lesson

- [ ] Instructor joins; recording starts **automatically** (the space was
  created through the Meet API with auto-recording ON). If the red
  Recording indicator does not appear, the meeting fell back to a
  Calendar-created conference — check the application log for the
  auto-record warning and press Record manually for this run.

- [ ] Record a short session, stop, end the conference
- [ ] Note: scheduled start, conference start, record start/stop, end

Then observe, without forcing anything:

- [ ] Recording row is `pending` and stays retryable while Google is
      still generating the file (state `STARTED`/`ENDED`) — this is
      expected, not a failure
- [ ] Within a sweep cycle or two, state becomes `FILE_GENERATED` and the
      row progresses `transferring → stored → available`

**Native Drive copy — the primary unverified assumption.** Watch the
worker log:

- If the row reaches `available` with no download, `files.copy` worked →
  record **Native Drive copy: SUPPORTED**
- If the log shows `Backend-side recording copy unavailable; falling back
  to streamed ingestion`, capture the HTTP status and Google error code
  (never tokens) → record **NOT SUPPORTED WITH CURRENT LEAST-PRIVILEGE
  SCOPES; fallback: PASS**

**Do not widen scopes to force copy support.** The streaming fallback is
an accepted production path.

Then verify:

- [ ] Original Meet recording still exists in Drive, permissions unchanged
- [ ] SIRI's copy is private, correct size and MIME type
- [ ] `recordings.storage_path` holds **SIRI's copy**, not Meet's original
- [ ] Authorized student can download via the SIRI route (never a Drive URL)
- [ ] Assigned instructor can download; unrelated student/instructor denied
- [ ] Admin with `View:Recording` can download
- [ ] Student and instructor each received exactly one "recording
      available" notification, containing no Drive URL or file id
- [ ] If fallback ran: staged temp file deleted, disk returned to baseline

---

## 11a. Student playback validation (real Google, controlled)

Only after §10 has produced one `available` recording, and only on a
staging environment or with a consenting internal test student. Global
flags stay as they are; this validates the CAPABILITY, it does not
activate the policy for real students.

Local fakes prove the pipeline's logic; **only this section proves a
real Drive ranged read.** Until it has been run, treat Drive playback as
unvalidated.

1. [ ] Create one lesson between an internal test student and an
       internal instructor.
2. [ ] Instructor presses Record in Meet; stop; end the conference.
3. [ ] Wait for Google to generate the file (minutes); watch the row go
       `pending → transferring → stored → available`.
4. [ ] Confirm discovery found the conference by meeting code + window
       (audit `recording_registered` → `recording_available`).
5. [ ] Confirm the SIRI copy exists under `<root>/YYYY/MM/` with the
       booking-reference filename and that the Meet original is untouched.
6. [ ] Admin → Recordings → the row shows `google_drive`, size,
       duration; **Download** returns the full file with
       `Content-Disposition: attachment`.
7. [ ] Enable `meeting.recording_student_playback_enabled` **on staging
       only**; log in as the test student; My Bookings → the booking →
       "Recording available" → Watch.
8. [ ] Initial stream: browser devtools shows the first request
       `Range: bytes=0-` answered `206` with `Content-Range: bytes 0-N/total`
       where N+1 = `RECORDING_PLAYBACK_MAX_RANGE_BYTES`; playback starts.
9. [ ] Seek forward past the first window: a new `206` with the
       expected offset; video continues without a reload.
10. [ ] Seek backward: another `206`; no error in the console.
11. [ ] `curl -I` the stream URL with the student's cookie: `200`,
        `Accept-Ranges: bytes`, `Content-Length`, no body. `curl -H
        "Range: bytes=999999999999-"`: `416` with `Content-Range: bytes */total`.
12. [ ] Withhold the recording from the admin screen with a reason:
        the student now sees "Recording unavailable", the stream URL
        returns `403`, one `recording_student_access_withheld` audit row
        with `override_reason`. Restore; playback works again.
13. [ ] Log in as a different student: the booking is not theirs, the
        watch and stream URLs return `403`, one
        `recording_access_denied` audit row (repeats within 15 minutes
        do not add rows).
14. [ ] Turn `meeting.recording_student_playback_enabled` off: the
        booking shows nothing about recordings; URLs return `403`.
        Turn `meeting.recording_enabled` off with playback on: playback
        still works (acquisition switches never hide existing recordings).
15. [ ] Move the SIRI copy to Drive trash: the stream URL returns `503`
        and the application log carries "could not be opened" with the
        recording id and a failure code; restore from trash; playback
        works again. (Retention expiry, by contrast, sets the row to
        `expired` and the student sees "no longer available".)
16. [ ] Turn the staging playback switch back off. Nothing in
        production has changed.

---

## 11. Replay / idempotency check

```bash
php artisan recordings:capture   # run twice
```

- [ ] Still exactly 1 `recordings` row
- [ ] Still exactly 1 object in the Drive destination folder
- [ ] No additional notifications

---

## 12. Failure rehearsal (safe)

Prefer the least invasive: temporarily blank
`recording_drive_root_folder_id`, trigger a capture, then restore it.

- [ ] Failure classified (`storage_not_configured`), not a raw exception
- [ ] Lesson, booking, payment, earnings state untouched
- [ ] Operational alert raised
- [ ] Admin "Retry ingestion" action available and works after the fix
- [ ] Configuration restored

**Do not** rehearse by breaking the delegation grant — that affects
meeting creation for every booking.

---

## 13. Monitoring for the first several recordings

- [ ] Rows stuck in `pending`/`transferring` beyond
      `recording_transfer_stale_minutes` (120)
- [ ] Operational alerts: `recording_capture_failed`,
      `recording_multiple_artifacts`
- [ ] `recordings` queue depth and job durations
- [ ] Disk under `storage/app/private/recording-ingestion/`
- [ ] Drive storage consumption against the Workspace quota

---

## Rollback procedure

Ordered least to most disruptive — the first step stops all recording
activity without touching Google at all.

1. **Stop acquisition (no Google change):**
   set `meeting.google_meet_recording_enabled` = false.
   The provider stops declaring recording support, eligibility declines,
   no new recording rows are created. Existing stored recordings remain
   downloadable.

2. **Stop the worker:** `supervisorctl stop siri-recordings`.
   In-flight rows stay `transferring` and are reclaimed to `pending` by
   the sweep after 120 minutes. Nothing is lost.

3. **Revert storage target:** set `RECORDING_STORAGE_DRIVER=filesystem`.
   New recordings go to the local disk. **Recordings already in Drive
   stay readable** — each row is read back through its own
   `storage_driver`, so no backfill or cutover window is needed.

4. **Restore the delegation grant** (only if step 4b regressed):
   paste the scope list from the step-1 rollback note back into the
   Workspace admin console entry. Then re-verify meeting creation.
   Recording will stop working; meetings will resume.

5. **Full stop:** set `meeting.recording_enabled` = false.

Nothing in this runbook deletes a recording. Retention deletion happens
only via `recordings:expire` on rows past `expires_at`.
