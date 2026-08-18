# Class Recording Storage

How a completed lesson's recording gets from the meeting provider into
SIRI-owned storage, who may reach it afterwards, and what changes when
storage moves from Google Drive to Amazon S3.

SRS references: §12.18 (recording strategy), §12.19 (consent), §12.20
(access), §12.21 (retention), §12.31 (meeting recording), §12.35
(exception handling), §12.36 (notifications).

---

## 1. The one architectural rule

**Google Drive is an implementation of recording storage. It is not the
architecture.**

Everything above the storage boundary — `Lesson`, `Booking`,
`Recording`, policies, notifications, admin screens, the download route
— is written against `RecordingStorage` and knows nothing about Drive.
There is no `google_drive_file_id` column, no Drive URL in a Blade
template, and no Google SDK type anywhere in the domain. Two tests
enforce this mechanically
(`RecordingStoragePortabilityTest::test_no_google_specific_type_leaks_into_the_recording_domain`
and `…::test_only_the_drive_adapter_and_its_gateway_touch_the_google_drive_sdk`).

---

## 2. Lifecycle

```text
Booking confirmed
        ↓
BookingMeetingService::createMeeting()
        ↓
RecordingService::registerIfEligible()      ← consent + eligibility gates
        ↓
Recording row created                        status: pending
        ↓
CaptureLessonRecordingJob dispatched afterCommit, delayed
(and, independently, the recordings:capture sweep)
        ↓
RecordingIngestionService::ingest()
        ↓
  claim row  (atomic pending → transferring, row-locked)
        ↓                                    status: transferring
  provider->fetchRecording()  → staged to storage/app/private/recording-ingestion/
        ↓
  RecordingStorage::put()     → resumable chunked upload
        ↓                                    status: stored   ← locator persisted
  RecordingStorage::verify()  → metadata check at the backend
        ↓                                    status: available
  notify student + instructor (once each)
        ↓
  … retention window elapses …
        ↓
recordings:expire → RecordingStorage::delete()
        ↓                                    status: expired  ← metadata retained
```

Failure branches back to `pending` (transient, inside the retry window)
or to `failed` (permanent, or budget exhausted) with an operational
alert raised for administrators.

### Why `stored` and `available` are separate

A recording that Drive accepted is not yet a recording SIRI can serve.
`stored` means "the upload call returned success and we have persisted
the locator"; `available` means "we asked the backend what it holds and
it matches what we sent". Only `available` is downloadable.

The gap between them is also the crash-recovery seam: a worker killed
after the upload but before verification leaves the row `stored`, and
the retry resumes at verification instead of transferring the same
video a second time.

---

## 3. Acquisition — Google Meet

**Google Meet supplies real lesson recordings.** This corrects an
earlier claim in this document that no production provider could. It
could; the Meet REST API v2 exposes conference recording artifacts, and
that is what this integration uses.

### How a Meet recording actually comes into being

Meet writes a finished class recording **as an MP4 into the platform
Workspace account's Google Drive**, and publishes its Drive file id
through the Meet API. So acquisition is a metadata lookup, not a
download — and because SIRI's storage is currently that same Drive,
usually not even a transfer.

```text
Google Meet class (Calendar-created conference)
        ↓
conference ends  →  Google reconciles a conferenceRecord
        ↓            (asynchronous — minutes, not instant)
recording generated → state becomes FILE_GENERATED
        ↓
conferenceRecords.list(space.meeting_code = <code>
                       AND start_time within the lesson window)
        ↓
conferenceRecords.recordings.list(<conferenceRecord>)
        ↓
driveDestination.file  →  the Meet MP4's Drive file id
        ↓
       ┌──────────────────────────┴──────────────────────────┐
Drive is SIRI storage                        storage is S3/filesystem
       ↓                                              ↓
files.copy server-side                    stream download → stage → upload
(no bytes touch this host)                    (RecordingStagingArea)
       └──────────────────────────┬──────────────────────────┘
                                  ↓
                     verify at the backend  →  available
```

### Mapping a lesson to its conference

This is the part that must not be wrong: one student's class must never
be delivered to another. The mapping uses **only immutable provider
identifiers plus an explicit time window**, never a title, participant,
or formatted date:

| SIRI | Google |
|---|---|
| `booking_meetings.provider_meeting_id` (persisted at creation) | Meet **meeting code**, e.g. `abc-defg-hjk` |
| `booking_meetings.starts_at`/`ends_at` | `start_time` filter bound (−60 min / +240 min) |
| — | `conferenceRecords/{id}` |
| `recordings.provider_reference` | `conferenceRecords/{c}/recordings/{r}` |
| `recordings.storage_path` | the Drive id of **SIRI's own copy**, never Meet's original |

The time window is load-bearing: **a meeting space can host many
conferences over its lifetime**, so a recurring lesson reuses the same
code every week. `GoogleMeetRecordingLocator` is where this lives, and
`GoogleMeetRecordingAcquisitionTest` attacks it directly — a conference
a week earlier in the same space, and a conference in a different space,
must both be refused.

No new identifier column was needed: the meeting code was already
persisted. The join URL is a documented fallback.

### Discovery strategy: bounded reconciliation, not events

The Google Workspace Events API can push `recording generated` events,
and was evaluated. It is **not** used, for V1:

- It needs Google Cloud Pub/Sub — a subscription, a push endpoint, IAM,
  and subscription renewal. This application has no Pub/Sub
  infrastructure, and adding it would be the single largest piece of new
  operational surface in the feature.
- It would not remove the reconciliation sweep. A missed or expired
  subscription must never permanently lose a class recording, so bounded
  polling has to exist regardless — events would only reduce latency.
- Recording generation is already asynchronous by minutes, so the sweep's
  15-minute cadence is not the dominant delay.

So V1 uses the low-latency queued job plus the bounded sweep already
built, both of which now call the Meet API. If recording latency ever
becomes a product complaint, Workspace Events is the right next step and
slots in ahead of the same job with no downstream change.

Every Meet query is bounded: filtered by meeting code, constrained to the
lesson's own window, page-size capped, and hard-limited to 5 pages.

### Automatic recording is NOT enabled — and cannot be

Meet can auto-record a space via
`spaces.config.artifactConfig.recordingConfig.autoRecordingGeneration`.
SIRI does not set it, for a reason that is a genuine Google constraint
rather than a choice:

> Configuring a space requires the `meetings.space.settings` scope, and
> Meet only permits an app to configure **spaces it created itself
> through the Meet API**. SIRI's conferences are created by the
> **Calendar API** (`conferenceData.createRequest`), which makes their
> spaces Calendar-created. Google provides no scope that lets an app
> configure another app's space.

**Operational consequence: a human must press Record in the meeting for
a recording to exist at all.** Everything downstream is automatic; the
start is not. This must be part of instructor onboarding.

The remedy, if auto-recording becomes a requirement, is to create the
meeting space via the Meet API and attach it to the Calendar event
instead of letting Calendar create the conference. That is a change to
meeting *creation*, not to recording, and it would additionally allow
dropping to the narrower `meetings.space.created` scope. It was not done
now because it alters a working, tested booking flow for a benefit that
consent rules must gate anyway — see §14.

A test asserts the integration never reaches for auto-recording, so a
future change has to confront the consent question deliberately rather
than switching it on by accident.

---

## 4. Domain model

`recordings` — one canonical row per lesson recording.

| Column | Purpose |
|---|---|
| `booking_meeting_id`, `booking_id` | the lesson this recording belongs to |
| `student_id`, `teacher_id` | denormalized from the booking so authorization never joins |
| `provider`, `provider_reference` | which meeting provider supplied it, and its id there |
| **`storage_driver`** | which backend holds the bytes (`filesystem`, `google_drive`) |
| **`storage_path`** | that backend's opaque handle — a Drive file id, a disk path, an S3 key |
| **`storage_checksum`** | sha256 of the source bytes, computed while staged locally |
| `status` | see the lifecycle above |
| `idempotency_key` | `recording:<meeting id>`, uniquely indexed |
| `consent_snapshot` | both participants' consent at registration time (§12.19) |
| `duration_seconds`, `size_bytes`, `mime_type` | metadata that must outlive the file (§12.21) |
| `capture_attempts`, `failure_code` | bounded retry budget and a stable failure label |
| `recorded_at`, `transfer_started_at`, `stored_at`, `available_at`, `failed_at`, `expires_at` | lifecycle timestamps |

`storage_driver` + `storage_path` are the **provider-neutral locator**.
Nothing outside the owning adapter may parse either value. They are on
`$hidden`, so no API or Livewire payload ever carries them.

### What is deliberately NOT stored

Access tokens, refresh tokens, temporary provider download URLs, raw
webhook payloads, and credentials. None of these belong on a metadata
row. Google credentials live encrypted in `MeetingSettings`, exactly
where the Google Meet integration already keeps them.

---

## 5. Storage abstraction

```text
                    App\Booking\Contracts\RecordingStorage
                    key() isConfigured() put() verify() read() delete()
                                    │
              ┌─────────────────────┼─────────────────────┐
              │                     │                     │
   FilesystemRecordingStorage   GoogleDrive…Storage   InMemory… (tests)
        any Laravel disk          GoogleDriveClient
     local NOW · s3 LATER        └─ GoogleDriveSdkClient  ← only file
                                     (google/apiclient)      touching the SDK
```

Selection is by `RecordingStorageResolver`:

- `default()` — where **new** recordings are written, from
  `config('recordings.storage_driver')`.
- `forRecording()` — where an **existing** recording actually lives,
  from its own row.

That split is the migration guarantee. See §11.

### Why not a Flysystem Drive adapter?

`google/apiclient` is already a direct dependency (the Google Meet
integration uses it), and this workload needs four things a generic
Flysystem adapter does not give cleanly: resumable chunked uploads for
multi-gigabyte files, Shared Drive parameters (`supportsAllDrives`,
`driveId`), Drive-specific error classification (quota vs auth vs
transient), and metadata-only verification. No third-party package was
added. The filesystem driver still uses Laravel's own abstraction,
because for a plain disk it is entirely sufficient — and it is what
makes S3 free.

---

## 6. Google authentication and scopes

**Model: one service account with domain-wide delegation, impersonating
the platform Workspace account** — the same identity already proven by
the Google Meet/Calendar integration. One credential, one place to
rotate it, no refresh token to store, and no "Connect your Drive"
screen (which would be wrong: SRS §12.18 forbids instructor personal
drives).

### The four APIs and why each scope is needed

| API | Scope | Why |
|---|---|---|
| Calendar | `calendar` | Creates the Meet conference (pre-existing) |
| Meet REST v2 | `meetings.space.readonly` | Read `conferenceRecords` and `recordings` to locate the artifact |
| Drive | `drive.meet.readonly` | **Read the Meet-created MP4** |
| Drive | `drive.file` | Create/manage SIRI's own recording folders, copies and uploads |

Two of these deserve their reasoning stated, because both are places
where the obvious guess is wrong:

**Why `meetings.space.readonly` and not `meetings.space.created`.**
`meetings.space.created` is the narrower scope and would be preferable,
but it grants access only to spaces **the calling app created through
the Meet API**. SIRI's conferences are created by the Calendar API, so
they are Calendar-created and that scope reads none of them — every
call returns `PERMISSION_DENIED`. `meetings.space.readonly` is the
narrowest scope that works for this architecture. It is read-only.

**Why `drive.file` alone is not enough.** `drive.file` grants per-file
access to files **the app created or opened**. A Meet recording is
created by *Meet*, not by SIRI, so `drive.file` cannot see it at all.
Google added `drive.meet.readonly` in July 2024 for exactly this case:
read-only access confined to Drive files created or edited by Google
Meet. It is used instead of `drive.readonly` or `drive`, either of which
would expose the entire Workspace account's Drive.

An exact-match test asserts the Drive scope list, specifically to stop
someone widening it to `drive.readonly` to make a permission error go
away.

> **All scopes must appear in the same domain-wide delegation grant.** A
> scope a client requests but the grant omits fails token acquisition
> with `401 unauthorized_client` for **every** scope, not just the
> missing one — which would break Meet creation too, not only
> recordings.

**Shared Drive is recommended** for the recording root so ownership
belongs to the organization and survives any single account.

---

## 7. File organization

```text
<configured root folder>/
    2026/
        08/
            lesson-BK-7QF2M4XK1P-20260818-143000.mp4
```

- Partitioned by year and month so no single folder or prefix
  accumulates every recording the platform has ever made (Drive
  degrades badly past a few thousand children).
- Folder ids are resolved-or-created lazily and cached for a day.
  Concurrent creators are handled inside the client.
- The filename carries the **booking's public reference only** — the
  same reference already on invoices and meeting titles. No student or
  instructor name, email, phone, or free-text subject.
- **The folder tree is not a database.** Nothing is ever looked up by
  filename or path. Renaming every file in the bucket would break
  nothing; the row and its locator are the only identity.

The filesystem driver mirrors the same layout:
`recordings/2026/08/lesson-….mp4`.

---

## 8. Access control

SIRI is the authorization layer; the storage backend never is.

| Who | May access |
|---|---|
| Student | recordings of their own lessons |
| Instructor | recordings of lessons **they delivered** — no blanket instructor right exists, because the SRS grants none |
| Admin | with the explicit `View:Recording` permission (`ViewAny:Recording` for the list) |
| Super admin | via `Gate::before`, as everywhere |

Enforced by `RecordingPolicy`, re-checked **live on every single
request** by `RecordingDownloadController`. Notably:

- Drive files are created with default (private) permissions. This
  codebase never sets an "anyone with the link" permission, never
  requests a `webContentLink`, and never issues a shareable URL — a
  test asserts those API surfaces are absent from the adapter.
- The download URL keys on the **recording**, not on a storage id, so
  no backend identifier is ever exposed in a URL.
- `download` is stricter than `view`: it additionally requires the
  recording to be `available` and to still have a locator, so an
  expired or failed recording is never half-served.
- There is no signed or pre-generated URL anywhere, so there is nothing
  to leak or forward.

### Delivery: download, not playback

The current product requirement (§12.20) is permission-controlled
access to a stored recording, and no student-facing player exists.
Delivery is therefore an **authenticated, application-proxied
download**, streamed in 1 MB chunks so a recording is never held in PHP
memory on the way out either.

Range requests are explicitly **not advertised** (`Accept-Ranges:
none`) rather than half-implemented. If in-browser seeking becomes a
requirement, the honest implementation is a ranged read added to
`RecordingStorage` — not a faked range response, and certainly not a
public Drive file.

---

## 9. Idempotency and concurrency

However many times the same recording is observed, the result is one
canonical row, one stored object, and one notification per participant.

| Event | What prevents duplication |
|---|---|
| Duplicate provider event / webhook replay | unique `idempotency_key` (`recording:<meeting id>`) |
| Redelivered queue message | atomic `pending → transferring` claim under a row lock |
| Reconciliation sweep overlapping the job | same claim — the loser exits without fetching |
| Two workers at once | same claim, plus the unique `(storage_driver, storage_path)` index as a database-level backstop |
| Retry after a partial upload | row is `stored`, so the retry re-verifies rather than re-uploads |
| Admin manual retry | only a `failed` row transitions, under a row lock |

None of this relies on in-memory state or on queue-level uniqueness.
`RecordingIdempotencyTest` runs the full realistic sequence — dispatch,
replay, redelivery, two sweeps — and asserts one object.

---

## 10. Failure, retry, and recovery

Failures are classified into `RecordingFailureCode`, and the retry
decision comes from that enum plus a bounded budget — never from an
exception class or message.

| Code | Permanent? |
|---|---|
| `provider_capability_missing`, `source_expired`, `source_rejected`, `storage_not_configured`, `capture_retries_exhausted` | yes — stops immediately |
| `source_download_failed`, `storage_upload_failed`, `storage_auth_failed`, `storage_quota_exceeded`, `storage_verification_failed` | no — retried inside the window |

Auth and quota are deliberately transient: both are routinely fixed by
an operator (re-grant delegation, free space) while the window is still
open, and a permanently stalled recording is worse than a few wasted
attempts.

**Bounds** (admin-configurable, `meeting.*`):
`recording_capture_max_attempts`, `recording_capture_retry_minutes`,
`recording_capture_max_age_hours`, `recording_capture_batch_size`,
`recording_transfer_stale_minutes`. There is no unbounded retry loop
anywhere.

**Self-healing.** `recordings:capture` (every 15 minutes) does three
bounded things: reclaims rows abandoned mid-transfer by a crashed
worker, retries every `pending`/`stored` recording inside the age and
attempt window, and purges staged temp files a crashed run left behind.

**Manual recovery.** Admins holding `Retry:Recording` get a "Retry
ingestion" action on failed recordings. It is authorized, audited with
the acting admin and the previous failure code, idempotent (only
`failed` transitions, row-locked), non-destructive, and queued — never
inline.

**Recording failure never touches lesson completion, booking payment,
instructor earnings, or wallet settlement.** Recording persistence is
an independent post-lesson workflow and the SRS establishes no
dependency in either direction. A test asserts this.

**Temporary files.** Staged downloads live in
`storage/app/private/recording-ingestion/` (private disk, never
public), are deleted in a `finally` block on every path — success and
failure alike — and are backstopped by the sweep's stale purge.
Deleting on failure is safe because a retry always re-downloads from
the provider; staged bytes are never the only copy.

---

## 11. Migrating to Amazon S3

**What changes:**

```env
RECORDING_STORAGE_DRIVER=filesystem
RECORDING_STORAGE_DISK=s3
```

That is the whole switch for new recordings. S3 is a Laravel disk, so
`FilesystemRecordingStorage` already handles it — no new adapter, no
new dependency, no AWS package added speculatively.

**What does NOT change:** `Lesson`, `Booking`, `Recording`, student and
instructor access logic, `RecordingPolicy`, the recording lifecycle and
status machine, the download route and controller, notifications, the
admin resource, Meet acquisition, and any future provider webhook
handling. None of them reference a backend.

**The Drive-native copy does not become a dependency.** It is an
optional capability (`SupportsNativeIngestion`) that only the Drive
backend implements, and it applies only while source and destination
happen to be the same service. Point storage at S3 and a Meet recording
is simply streamed — discovered from the Meet API exactly as before,
downloaded once, uploaded once. Tests cover that path end to end today,
against a filesystem disk, so it is exercised rather than assumed.

**Existing Drive recordings keep working during and after the switch**,
with no backfill and no cutover window: reads and deletes resolve
through each row's own `storage_driver`, not through the configured
default. `RecordingStoragePortabilityTest` asserts exactly this.

**Moving old objects** (optional, later) would be a bounded job per
recording: read through the old driver → write through the new →
verify → update the row's locator in one transaction → delete the old
object. The domain would not need to know it happened.

---

## 12. Configuration

**Deployment configuration** — `config/recordings.php` / `.env`:

| Key | Meaning |
|---|---|
| `RECORDING_STORAGE_DRIVER` | `filesystem` or `google_drive` |
| `RECORDING_STORAGE_DISK` | disk for the filesystem driver — must be private, never `public` |
| `RECORDING_STAGING_STALE_HOURS` | how long an orphaned staged file survives before the sweep purges it |
| `RECORDING_MAX_SOURCE_BYTES` | hard ceiling on an accepted source (default 5 GB) |
| `RECORDING_DRIVE_CHUNK_BYTES`, `RECORDING_DRIVE_TIMEOUT` | resumable upload mechanics |
| `RECORDING_QUEUE_DRIVER`, `RECORDING_QUEUE_RETRY_AFTER` | the dedicated ingestion connection |

**Admin settings** (`meeting.*`, database, encrypted where sensitive):
`recording_enabled`, `recording_retention_days`,
`recording_drive_root_folder_id`, `recording_drive_shared_drive_id`,
`recording_transfer_stale_minutes`, and the capture window/attempt
knobs. Google credentials reuse the existing encrypted
`google_credentials_json` and `platform_meeting_account`.

**No credential value belongs in `.env.example`, git, the database's
recording columns, or logs.** Recording storage fails closed when
enabled but misconfigured — and its absence never prevents the
application from booting, because recording is optional.

---

## 13. Queue and Supervisor

Recording ingestion runs on its own connection **and** queue, both named
`recordings`.

Why its own **connection**: `retry_after` is a per-connection setting
and must exceed the job's timeout. `CaptureLessonRecordingJob` has
`timeout = 3600`, so the connection uses `retry_after = 3900`. Raising
`retry_after` on the shared `database` connection would delay recovery
for every other job in the system; leaving it at 90s would hand the
same recording to a second worker while the first is still uploading.

`tries = 1` on purpose: retry belongs to the domain (row state + the
bounded sweep), not to the queue. Queue-level retries would multiply
concurrent uploads of the same video and bypass the attempt budget.

**Supervisor — add one program:**

```ini
[program:siri-recordings]
command=php /path/to/artisan queue:work recordings --queue=recordings --timeout=3600 --tries=1 --sleep=3
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3700
```

`stopwaitsecs` must exceed the job timeout so a deploy does not kill a
transfer mid-flight. Existing workers are unchanged. One process is
enough — ingestion is bandwidth-bound, and the claim guarantees no
double work if you run more.

**Bandwidth note:** while Drive is both source and destination, Meet
recordings are copied server-side and consume essentially no worker
bandwidth or staging disk. Budget for the streaming path (full download
+ full upload per recording) only from the point storage moves to S3, or
if Drive starts refusing server-side copies.

**Also check on the recording worker host:** PHP `memory_limit` needs
only to cover the upload chunk size (8 MB default), not the recording;
and `storage/app/private/recording-ingestion/` needs free space for the
largest concurrent transfers.

**Scheduler** (already registered in `routes/console.php`):
`recordings:capture` every 15 minutes, `recordings:expire` daily, both
`withoutOverlapping()->onOneServer()`.

---

## 14. Google production setup

Before enabling recording acquisition and Drive storage:

1. In Google Cloud Console, on the project that already holds the
   Meet/Calendar service account, **enable the Google Drive API** and
   the **Google Meet API**.
2. In the **Workspace admin console → Security → Access and data
   control → API controls → Domain-wide delegation**, edit the existing
   entry for that service account's client ID and set the scope list to
   **all four**, keeping the existing Calendar scope:
   - `https://www.googleapis.com/auth/calendar`
   - `https://www.googleapis.com/auth/meetings.space.readonly`
   - `https://www.googleapis.com/auth/drive.meet.readonly`
   - `https://www.googleapis.com/auth/drive.file`

   All four in one grant. Omitting any one breaks **every** scope,
   including meeting creation.
3. Confirm the Workspace edition includes **Meet recording** (Business
   Standard and above, Enterprise, Education Plus, Teaching & Learning
   Upgrade). Without it no recording is ever produced and there is
   nothing for this pipeline to find.
4. Create the recordings root folder, ideally inside a **Shared Drive**
   owned by the organization (e.g. "SIRI Education Recordings").
5. Grant the impersonated platform account
   (`meeting.platform_meeting_account`) **Content manager** on that
   Shared Drive.
6. Copy the folder id from its URL into **Admin → Settings → Meetings →
   `recording_drive_root_folder_id`**; set
   `recording_drive_shared_drive_id` too if using a Shared Drive.
7. Set `RECORDING_STORAGE_DRIVER=google_drive`, deploy, and start the
   `siri-recordings` Supervisor program.
8. Turn on **`meeting.google_meet_recording_enabled`**. It ships OFF
   precisely so steps 1–2 cannot be skipped.
9. **Brief instructors that they must press Record** — see §3;
   auto-recording is not available to this integration.
10. Verify with one real lesson end to end before enabling broadly.

No credential value is ever entered into `.env` or committed.

---

## 15. Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| **No recording exists at all** | Nobody pressed Record. Auto-recording is not available to this integration (§3), and Meet produces no artifact without it. | Confirm with the instructor; check the Workspace edition includes Meet recording. |
| **`source_access_denied`** | The `meetings.space.readonly` or `drive.meet.readonly` scope is missing from the domain-wide delegation grant. | Complete step 2 of §14. Transient, so the sweep recovers automatically once fixed. |
| **`source_rate_limited`** | Meet API quota or rate limit. | Transient; the bounded sweep retries. Investigate only if persistent. |
| **`source_expired` on a Meet lesson** | Google's conference record no longer exists (artifacts expire). | Permanent — unrecoverable. |
| **Recording stays pending for a while after class** | Normal. Google reconciles the conference record and generates the MP4 asynchronously. | Wait for a sweep cycle or two before investigating. |
| **Multiple segments alert** | The class was recorded in several Record start/stop sessions. SIRI stores the earliest. | Product decision — see §15 of the report / `RecordingMultipleArtifacts` alerts. |
| **Recording never discovered** — no row exists | Eligibility gate. Recording disabled globally/per-country, booking not confirmed, a participant has not consented, or the provider declines recording. | Check `FeatureSettings::recording_enabled`, `MeetingSettings::recording_enabled`, both profiles' `consents_to_recording`, and whether the active provider implements `MeetingRecordingProviderInterface`. `RecordingEligibilityResolver` returns a specific reason code for each. |
| **Row stuck `pending`** | Provider not ready yet, or the queue worker is down. | Normal for a while after a lesson. If it persists, check the `recordings` worker and run `php artisan recordings:capture`. |
| **Row stuck `transferring`** | Worker crashed mid-upload. | The sweep reclaims it after `recording_transfer_stale_minutes` (default 120). Force with `recordings:capture`. |
| **Row stuck `stored`** | Verification is failing repeatedly. | The object exists but the backend reports a different size, or is trashed. Check the failure code and the Drive file; a retry re-verifies without re-uploading. |
| **`source_expired`** | The provider's asset is gone. | Permanent. The recording is unrecoverable; no retry will help. |
| **`storage_auth_failed`** | Drive delegation scope missing or key revoked. | Re-check step 2 of §14 — `drive.file` **and** the Calendar scope in the same domain-wide delegation grant. Then retry from the admin action. |
| **`storage_quota_exceeded`** | Workspace Drive storage full. | Free space or raise the quota, then retry. Transient, so the sweep will also pick it up. |
| **`storage_verification_failed`** | Truncated upload, or the file was trashed in Drive. | Restore the file from Drive trash, or retry the ingestion to upload afresh. |
| **`storage_not_configured`** | Root folder id, credentials, or platform account missing. | Complete §14. Fails closed by design. |
| **Recording unavailable to a student** | Not `available`, expired, or they are not a participant. | Check the row's status and `expires_at`. Access is participant-only by design. |
| **Staging disk filling up** | Crashed transfers leaving files behind. | `recordings:capture` purges files older than `RECORDING_STAGING_STALE_HOURS`. If growth is fast, look for repeated failures on large recordings. |

**Operational signals to watch:** operational alerts of type
`recording_capture_failed`; the count of rows in `failed`; rows sitting
in `pending`/`transferring` longer than the stale threshold; and disk
usage under `storage/app/private/recording-ingestion/`. The admin
Recordings resource surfaces status, backend, size, attempts, and the
failure label — never a credential, token, locator, or provider URL.

---

## 16. Key files

| Area | File |
|---|---|
| Contract | `app/Booking/Contracts/RecordingStorage.php` |
| Backends | `app/Booking/Storage/{Filesystem,GoogleDrive}RecordingStorage.php`, `RecordingStorageResolver.php` |
| Drive SDK seam | `app/Booking/Contracts/GoogleDriveClient.php`, `app/Booking/Gateways/GoogleDriveSdkClient.php` |
| Meet acquisition | `app/Booking/Contracts/{GoogleMeetClient,DiscoversRecordingArtifacts}.php`, `app/Booking/Gateways/GoogleMeetSdkClient.php`, `app/Booking/Services/{GoogleMeetRecordingLocator,GoogleMeetRecordingStager}.php` |
| Native ingestion | `app/Booking/Contracts/SupportsNativeIngestion.php`, `app/Booking/DTOs/{NativeRecordingSource,NativeIngestionRequest,DiscoveredRecording}.php` |
| Domain | `app/Models/Recording.php`, `app/Booking/Services/Recording{Service,IngestionService,EligibilityResolver,AvailabilityResolver,StagingArea,FileNamer,LifecycleNotifier}.php` |
| Lifecycle | `app/Booking/Enums/Recording{Status,FailureCode}.php` |
| Jobs / commands | `app/Booking/Jobs/CaptureLessonRecordingJob.php`, `app/Console/Commands/{CaptureLessonRecordings,ExpireLessonRecordings}.php` |
| Access | `app/Policies/RecordingPolicy.php`, `app/Http/Controllers/Dashboard/RecordingDownloadController.php` |
| Admin | `app/Filament/Resources/Recordings/` |
| Config | `config/recordings.php`, `config/queue.php` (`recordings` connection) |
| Tests | `tests/Feature/Booking/Recording*.php`, `GoogleDriveRecordingStorageTest.php`, `tests/Support/InMemoryRecordingStorage.php` |
