# Meeting Providers

How SIRI creates lesson meetings, which providers it supports, and how
recording relates to provider choice.

Recording *storage* is documented separately in `docs/recordings.md`;
this file covers provider selection, meeting lifecycle, and recording
*acquisition* per provider.

---

## 1. The two independent decisions

The single most important rule in this area:

> **Which provider hosts the meeting** and **whether that meeting is
> recorded** are separate decisions. Neither implies the other.

Every combination is expressible and supported:

| | Recording off | Recording on |
|---|---|---|
| **Google Meet** | ✅ | ✅ |
| **Zoom** | ✅ | ✅ |
| **Manual** | ✅ | not capable |

Two settings per provider express it:

| Setting | Means |
|---|---|
| `google_meet_enabled` / `zoom_enabled` | SIRI may create meetings with this provider |
| `google_meet_recording_enabled` / `zoom_recording_enabled` | meetings from this provider may enter the recording workflow |

A provider that is disabled cannot record (the settings page normalizes
this on save, so the stored state can never read "recording on, provider
off"). Above both sit the platform-wide master switches —
`FeatureSettings::recording_enabled`, `MeetingSettings::recording_enabled`
and the country feature rules — which can only ever *narrow*. All of
these govern whether NEW recordings are made; whether a student may
watch one that already exists is a separate switch
(`meeting.recording_student_playback_enabled`, see §5 and
`docs/recordings.md` §8).

---

## 2. Providers

| Capability | Google Meet | Zoom | Manual |
|---|---|---|---|
| Create meeting | ✅ Calendar API | ✅ Zoom API | ✅ admin-entered link |
| Update meeting | ✅ | ✅ | ✅ |
| Cancel meeting | ✅ | ✅ | ✅ |
| Join URL | ✅ | ✅ | ✅ |
| Host/start URL | — (Meet has none) | ✅ stored, never exposed | — |
| Provider meeting id | ✅ meeting code | ✅ numeric id | — |
| Recording capable | ✅ | ✅ | ❌ |
| Recording optional | ✅ | ✅ | n/a |
| Auto-recording | ✅ Meet-API space (see §3) | ✅ per meeting | n/a |
| Recording discovery | ✅ Meet REST v2 | ✅ Zoom API | n/a |
| Recording webhook | ❌ | ✅ | n/a |
| Reconciliation sweep | ✅ | ✅ | n/a |
| SIRI storage integration | ✅ | ✅ | n/a |
| Student playback (policy-gated, SIRI-proxied) | ✅ | ✅ | n/a |

### Provider selection

`MeetingSettings::default_provider` chooses the provider for **new**
meetings; `MeetingProviderResolver` resolves and validates it. There is
no routing by country, instructor, subject or package — the SRS defines
none, and none was invented.

**Selection fails closed.** A provider that is disabled or
misconfigured raises a clear error; SIRI never silently falls back to a
different provider, because an admin who mis-typed a Zoom credential
should learn that immediately, not discover months later that lessons
quietly became Google Meet.

**Changing the default affects only new meetings.** An existing
`BookingMeeting` is always operated through the provider persisted on
the row, so a booking created under Google Meet stays Google Meet
forever — including its update, cancellation and recording paths. The
same is true of recordings: each row is read back through its own
`provider` and `storage_driver`, so toggling a setting never invalidates
history.

---

## 3. Google Meet

Meetings are created as Calendar events with Meet conference data, under
a platform Workspace account via a service account with domain-wide
delegation.

**Recording acquisition** uses the Meet REST API v2: a lesson maps to
its conference by meeting code plus an explicit time window (a Meet
*space* hosts many conferences over its life, so the window is what pins
one to one lesson), then to the recording artifact's Drive file id.
Because Meet writes its recording into the same Google Drive that SIRI
uses for storage, the transfer is normally a **server-side copy** — no
bytes cross the application server.

Full detail, scopes and setup: `docs/recordings.md`.

### Auto-recording

Since 2026-09-05 a recording-eligible lesson's space is created through
the Meet REST API with automatic recording ON and attached to the
Calendar event, so nobody presses Record. Needs the
`meetings.space.created` and `meetings.space.settings` scopes in the delegation grant and automatic
recording allowed in the Workspace admin console; without them the
lesson falls back to a Calendar-created conference recorded manually.
Detail: `docs/recordings.md` §3.

### Teacher co-host — deferred (decision 2026-09-05)

Giving the instructor host controls (admit, mute, remove, end) needs the
Meet API's space **members** with role `COHOST`. That resource is not in
Meet REST API v2 (verified live: `/v2/spaces/{space}/members` is a plain
404); it exists only in `v2beta` under the Workspace Developer Preview
Program, which is not for production. Decision: wait for general
availability rather than run a preview API in production. Classes do not
depend on it — spaces are OPEN and auto-record without a host.

Design when it ships: a nullable "Google account for Meet" on the
instructor profile (defaulting to the registered email), one
`addCoHost(space, email)` call in `GoogleCalendarMeetProvider` right
after `createSpace()`, non-fatal on failure (log + continue), and a note
on the instructor's lesson page to join with that account. Until then a
person signed in as the platform account can promote a co-host in the
Meet UI for exceptional cases.

---

## 4. Zoom

> **Status: implementation complete, external account not provisioned.**
> No Zoom subscription exists yet, so nothing below has been exercised
> against real Zoom infrastructure. Activation requires configuration
> only — no application development. See `docs/deployment/zoom-activation.md`.

### Authentication

**Server-to-Server OAuth**, against a platform-owned Zoom account.
SIRI mints an account access token from the account id, client id and
client secret; the token is cached and never persisted or logged.

Deliberately **not** implemented: per-instructor OAuth, instructors
connecting personal Zoom accounts, or anyone pasting tokens from a
dashboard. SIRI owns the Zoom integration; meetings are created under a
platform host user (`zoom_host_user_id` / `zoom_host_email`).

Secrets (`zoom_client_secret`, `zoom_webhook_secret`) are encrypted at
rest in settings, masked in the admin UI, never re-displayed after save,
never logged, and never present in `.env.example` as values.

### Meeting lifecycle

Create, update (reschedule) and cancel all run through
`ZoomMeetingProvider` on the shared `MeetingProviderInterface`, driven
by the same `BookingMeetingService` and events as Google Meet. No Zoom
API call is made from a controller or Livewire component.

Meetings are created as **scheduled** (type 2) with waiting room on,
join-before-host off, and participant video off. The meeting topic and
agenda use the same PII-safe builder as Google Meet: booking reference,
subject and duration — never a student name, email, phone or price.

**The start URL is a host credential.** Zoom's `start_url` grants host
privileges to whoever opens it, so it is stored in the hidden
`host_url` column, excluded from serialization, and never shown to
students.

### Recording

Zoom cloud recording only — never local recording, which would land on
the host's own computer, outside SIRI's storage, retention and access
control entirely.

`auto_recording` is set **per meeting** from the full SIRI eligibility
chain, so a lesson SIRI will not record is created with recording *off*
at Zoom. Consent is enforced at the provider, not merely after the fact.

Discovery is **webhook-first with bounded reconciliation**:

```text
Zoom recording.completed webhook
        ↓ verify signature, identify lesson, queue
        └──────────┐
recordings:capture │  (bounded, every 15 min — the guarantee)
        ↓          ↓
   ZoomRecordingLocator  → selects the class VIDEO
        ↓
   ZoomRecordingStager   → streamed download to private staging
        ↓
   RecordingIngestionService → RecordingStorage → verify → available
```

The webhook is an optimization; the sweep is the guarantee. A webhook
that was never delivered costs latency, never a recording.

### Which file is the class video

Zoom returns a mixture for one meeting: several MP4 layouts, an M4A
audio track, a chat log, a transcript. Selection is explicit and
deterministic — never "element zero":

1. MP4 only, status completed, with a download URL;
2. ordered by `config('recordings.zoom.preferred_layouts')`
   (shared-screen-with-speaker first);
3. ties broken by earliest start, then largest file.

An unrecognised future layout sorts last rather than being discarded.

### Download security

Download URLs and Zoom's short-lived download token are **never
persisted, serialized or logged**. The webhook is treated purely as a
signal; the artifact is re-fetched server-side at ingestion time. The
gateway refuses any download host that is not Zoom, so a URL that
somehow reached the database could not become an arbitrary outbound
request.

---

## 5. Recording access — who may open the file

> **Class recordings are a platform asset (SRS §12.18). Who may open
> one is decided by `RecordingPolicy` alone — never by the meeting
> provider and never by the storage backend.** SRS §12.20 allows
> Version 1 visibility to be "limited to administrators only or
> expanded to students based on policy"; the policy switch is
> `meeting.recording_student_playback_enabled`, which ships OFF.

| Who | Watch (in SIRI) | Download original |
|---|---|---|
| Admin with `View:Recording` | ✅ | ✅ |
| Student — their own lesson, **while student playback is enabled**, the recording is `available`, and it has not been withheld | ✅ | ❌ |
| Student — any other lesson | ❌ | ❌ |
| Instructor — even one they delivered | ❌ (no SRS grant exists) | ❌ |
| Any other user | ❌ | ❌ |
| Public / link | ❌ | ❌ |

This applies identically to Google Meet and Zoom recordings, and to
Drive and future S3 storage. Playback is inside the student's account
only (`/dashboard/recordings/{recording}`), proxied by SIRI with the
policy re-checked on every request; an administrator may withhold any
single recording from its student (`Withhold:Recording`, audited with
a reason). There is still **no "your recording is available"
notification** — SRS §17 permits one "if enabled", but no decision has
enabled it, and an architecture test keeps it absent until one does.
Full detail: `docs/recordings.md` §8.

Two concepts that must not be confused:

| Consent / notice | Access |
|---|---|
| Participants agree to be recorded and see the provider's in-meeting indicator | Who may open the finished file |
| Enforced by `RecordingEligibilityResolver` + consent snapshot | Enforced by `RecordingPolicy` |
| **Unchanged** by the access rule | Administrators; the student under the playback policy |

Recording consent is platform-wide (profiles default to consenting; the
opt-out toggle was withdrawn on 2026-09-05) and notice is given through
the Terms, the booking confirmation and the provider's in-meeting
indicator. Nothing here creates hidden recording.

### Provider-side controls are required too

Application authorization is not sufficient on its own: Zoom can email
recording links to the host independently of SIRI. The Zoom account
must therefore be configured so hosts cannot reach cloud recordings —
see `docs/deployment/zoom-activation.md` §5. Where a control requires a
particular Zoom plan, that is an external prerequisite, documented
rather than worked around.

**Honest boundary:** SIRI can control what the *provider* exposes and
what *SIRI* serves. It cannot prevent a determined participant from
running third-party screen-capture software on their own computer. That
is a policy and terms-of-service matter, not a technical one.

---

## 6. Storage independence

Meeting provider and storage backend are orthogonal:

```text
Google Meet ─┐
             ├─→ Recording ─→ RecordingIngestionService ─→ RecordingStorage ─→ Drive now
Zoom ────────┘                                                              └─→ S3 later
```

There is no `ZoomRecordingGoogleDriveService` and there never will be.
Both providers produce the same canonical `Recording`, ingested by the
same service into the same storage abstraction. Moving to S3 is a
configuration change for both providers at once.

---

## 7. Configuration reference

| Setting | Purpose |
|---|---|
| `meetings_enabled` | platform kill switch, all providers |
| `default_provider` | provider for new meetings |
| `google_meet_enabled` / `zoom_enabled` / `manual_provider_enabled` | may create meetings |
| `google_meet_recording_enabled` / `zoom_recording_enabled` | may record |
| `recording_enabled`, `recording_retention_days` | platform recording policy |
| `zoom_account_id`, `zoom_client_id`, `zoom_client_secret` | Server-to-Server OAuth (secret encrypted) |
| `zoom_host_user_id` / `zoom_host_email` | platform host the meetings run under |
| `zoom_webhook_secret` | webhook signature verification (encrypted) |
| `zoom_recording_webhooks_enabled` | accept recording webhooks |

Webhook endpoint to configure in the Zoom app:

```text
POST https://<your-domain>/api/webhooks/meetings/recordings/zoom
```

---

## 8. Absence of Zoom is not a failure

With no Zoom subscription configured, Zoom simply reports **not
configured**. It does not break application boot, Google Meet, booking,
the settings page, or the queues, and it generates no repeated errors —
`ZoomMeetingProvider::isConfigured()` and `supportsRecording()` are pure
configuration checks that never call Zoom.

---

## 9. Related documentation

| Topic | File |
|---|---|
| Recording storage, ingestion, retention, Google scopes | `docs/recordings.md` |
| Google recording cutover runbook | `docs/deployment/recording-cutover.md` |
| Zoom activation & staging runbook | `docs/deployment/zoom-activation.md` |
| Meeting creation internals | `docs/architecture/meetings.md` |
