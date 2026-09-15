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

### Recording needs a host present (decision 2026-09-10)

Google Meet records only while the host or an in-organisation co-host
is in the call, whatever the space's auto-recording setting. Chosen for
now: **a platform staff member joins each recorded class as the
platform account** (host), muted and camera-off. Space access is an
admin setting (Settings → Meetings → **Meet Space Access**,
`meeting.google_meet_space_access`, `GoogleMeetSpaceAccess`):
*Host admits participants* (default) — participants wait until the host
has started the class and admits them, so only the host starts a class
and the whole lesson is recorded; *Anyone with the link* — the class
can run without staff, recording from the moment the host joins;
*Invited members only*. Applies to new lessons.

Alternatives, in order of preference if staffing does not scale:

1. **Instructor Workspace accounts.** Create `name@sirieducation.com`
   for each active instructor (Meet-recording-capable edition) and let
   SIRI create the lesson's space impersonating that account, making
   the instructor the host. Moderate code change (delegated subject per
   lesson; Meet original in the instructor's org Drive, SIRI copy as
   now). Licence cost per instructor.
2. **Zoom** (`ZoomMeetingProvider`, already implemented, never run
   against a real account): join-before-host with cloud auto-recording
   starts without the host; licences per concurrent host.
3. **Embedded classroom** (100ms / Daily / LiveKit): server-side
   recording, no host concept; the SRS's future provider strategy.

### Teacher co-host — deferred (decision 2026-09-05)

Giving the instructor host controls (admit, mute, remove, end) needs the
Meet API's space **members** with role `COHOST`. That resource is not in
Meet REST API v2 (verified live: `/v2/spaces/{space}/members` is a plain
404); it exists only in `v2beta` under the Workspace Developer Preview
Program, which is not for production. Decision: wait for general
availability rather than run a preview API in production. Classes do not
depend on it — the platform host starts each class and auto-recording
begins when the host joins.

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

Meetings are created as **scheduled** (type 2) and **hostless** (since
Phase 4, 2026-09-11): the licensed platform user owns the meeting but
never has to join. Zoom settings SIRI fixes on every create and update
(`ZoomMeetingProvider::meetingPayload()`), never admin knobs:

| Zoom field | Value | Why |
|---|---|---|
| `join_before_host` / `jbh_time` | `true` / `5` | participants enter from five minutes before the start without the host; SIRI's own join window stays authoritative for who gets the link and when |
| `waiting_room` | `false` | nobody needs admitting |
| `use_pmi` | `false` | a generated id per lesson, never the reusable PMI |
| `alternative_hosts` | `''` | the teacher is never host, co-host or alternative host; the student is a participant |
| `meeting_authentication` | `false` | guests join by link and passcode |
| `approval_type` | `2` | no registration step |
| `auto_recording` | `cloud` when the lesson is recording-eligible, else `none` | recording starts when the first participant joins; consent and policy still decide |
| `password` (create only) | SIRI-generated, 10 alphanumeric | strong per-lesson passcode, never rotated by a reschedule |
| `mute_upon_entry`, `host_video`, `participant_video` | `true`, `true`, `false` | unchanged |

Account-level Zoom settings can lock the opposite of any of these; the
exact portal state is in `docs/deployment/zoom-activation.md` §5a and
is confirmed by inspecting the first staging meeting. The topic and
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

**Ownership.** An event is correlated to a lesson only through the Zoom
meeting id of a `booking_meetings` row SIRI itself created, and an
event whose `payload.account_id` names a different Zoom account is
refused (422) even when correctly signed. **Source disposal.** With
`zoom_recording_trash_source_after_persistence` on (ships off), the
Zoom copy is moved to the account's recoverable trash strictly after
the SIRI copy is stored, read back, matched and `available` — never
before, never permanently (`docs/recordings.md` §11a).

**Webhook authenticity** (`VerifiesZoomWebhooks`): the HMAC-SHA256 of
`v0:<x-zm-request-timestamp>:<raw body>` under `zoom_webhook_secret` is
compared constant-time against `x-zm-signature`; a missing secret fails
closed. The `x-zm-request-timestamp` header is Unix time in **seconds**
and must fall within five minutes of the server clock (past or future),
or the delivery is refused as a replay. The payload's `event_ts` is
**milliseconds** and only ever feeds the idempotency key — it plays no
part in verification, and the two units are never interchanged.

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
signal; the artifact is re-fetched server-side at ingestion time.

The download itself (`ZoomApiClient::openRecordingStream()`, hardened
2026-09-11) is the SSRF and credential boundary for ingestion:

- **Every destination is validated, not just the first.** Zoom answers
  a download with one or more redirects to a signed CDN URL. The
  client follows redirects *by hand*, one hop at a time, and each hop
  — the API-issued URL and every `Location` it is sent to — must be
  **HTTPS** on a host matching `config('recordings.zoom.download_hosts')`
  (`zoom.us`, `*.zoom.us`, `*.zoom.com`). Anything else, including a
  lookalike such as `zoom.us.attacker.example` or a downgrade to plain
  `http://`, is refused *before a connection is opened*. Approving a new
  CDN host is a reviewed configuration change, never runtime data.
- **The bearer token never reaches an unapproved destination.** Because
  a hop is validated before it is requested, the Authorization header
  is only ever sent to hosts that passed the check; a redirect to
  anywhere else ends the transfer with no request made there.
- **Bounded.** At most `RECORDING_ZOOM_MAX_REDIRECTS` hops (default 5),
  a connect timeout (`RECORDING_ZOOM_CONNECT_TIMEOUT`, default 15 s)
  and a read timeout (`RECORDING_ZOOM_DOWNLOAD_TIMEOUT`, default 900 s).
- **Refused early when oversized.** A declared `Content-Length` above
  `RECORDING_MAX_SOURCE_BYTES` is rejected before the first body byte;
  the staging pump re-applies the same ceiling to the bytes that
  actually arrive, checks every write, and treats a stream that ends
  short of its declared length as an incomplete download to retry —
  see `docs/recordings.md` §10 "Streaming safety".
- **Diagnostics name the host, never the URL** (a signed download URL
  is a credential), and never the token.

`ZoomApiClientTest` drives each of these against a faked Zoom.

### Zoom-side originals and retention

SIRI's 30-day retention (`docs/recordings.md` §11a) applies to **SIRI's
copy only**. The original cloud recording stays in the Zoom account
until Zoom's own retention removes it — SIRI never deletes it. Zoom's
auto-delete (Account Settings → Recording → "Delete cloud recordings
after N days") must be configured **deliberately**; left at the default
the originals accumulate indefinitely, and the admin-only access rule
SIRI enforces is only as good as the Zoom account's own sharing
settings (§5 below).

---

## 4a. Zoom host capacity — one licence, many instructors

> **Status: implemented 2026-09-11, ships OFF**
> (`meeting.zoom_host_capacity_enabled`). Nothing below changes booking
> behaviour until an operator registers the host and enables the flag —
> see the rollout steps in `docs/deployment/zoom-activation.md` §6a.

### The problem

Every Zoom lesson is created under **one platform-owned Zoom user**
(`zoom_host_user_id`), and a Zoom Pro user may run **one meeting at a
time**. Instructor availability cannot express this: two instructors
with empty calendars can both be sold 10:00 on Monday, and the second
Zoom meeting will refuse to start. Google Meet has no such limit, which
is one reason it stays the default until the controlled cutover.

### Data

| Table / column | Holds |
|---|---|
| `platform_meeting_hosts` | one row per host identity: `provider`, `host_reference` (the Zoom user id/email meetings are created under), `capacity` (Zoom Pro: 1), `is_active`, `sort_order`. **Identity only, never a credential** — the one Server-to-Server OAuth client in settings addresses every host. A second licence later is one more row (`meetings:zoom-hosts:register --host=…`), not another secret and never instructor OAuth. |
| `meeting_host_reservations` | one row per (booking, host) claim: the occupied UTC interval, `status` (`active`/`released`), `expires_at` (mirrors a pending-payment hold), `released_at` + `release_reason`. Never deleted — a rescheduled booking has one released and one active row. |
| `bookings.meeting_provider_intent` | the provider the booking was **accepted** for (written only while the feature is on). `BookingMeetingService` prefers it to today's `default_provider`, so flipping the default later cannot route an accepted booking onto a host nobody reserved, nor away from one it holds. |
| `booking_meetings.platform_meeting_host_id` | the host the remote meeting was actually created under. A Zoom meeting belongs to its user, so reschedules stay on this host. |

Instructor and student identities never appear on a host row; the
booking keeps its own `instructor_id`/`student_id`.

### When capacity is reserved — the acceptance boundary

`BookingService::request()` is where every commitment is made — a free
demo, a paid **pending-payment hold**, a **package-funded** lesson and
each **recurring occurrence** (`BookingSeriesService` calls the same
method) all create their booking row there, inside the instructor lock
and one transaction. Capacity is reserved in that same transaction,
after the instructor's availability passed and after the package unit
was taken, so:

- no capacity → `MeetingHostCapacityException` → the whole transaction
  rolls back: no booking, no hold, no package unit consumed, nothing
  charged, and **no silent switch to another provider**. The message is
  safe to show ("No Zoom host is available between … Please choose
  another time"). A recurring occurrence that hits this is recorded as
  a series conflict exactly like an instructor clash — never dropped
  silently;
- a **pending-payment hold** carries `reserved_until` on the
  reservation for visibility, but is released only when the hold is
  actually cancelled (`booking:release-expired` → `cancel()`), exactly
  as the instructor's slot is. A verified payment that lands late but
  before the sweep therefore still finds its capacity; `confirm()` just
  clears the expiry. If the sweep wins, `markPaid()`'s existing
  late-terminal path redirects the money to the wallet as before;
- `confirm()` **rechecks**: a booking that somehow reaches confirmation
  without a reservation (accepted before the feature was on) is
  reserved then if there is room. That late attempt never throws — the
  money has moved — it is audited (`meeting_host_capacity_unreserved`)
  and meeting creation will refuse a Zoom meeting for the booking until
  capacity exists or the provider is changed deliberately.

### What is reserved — the occupied interval

UTC, half-open, and wider than the lesson:

```text
[ starts_at − meeting_link_visible_before_minutes − buffer ,
  ends_at   + meeting_link_visible_after_minutes  + buffer )
```

The two window settings are the ones §5b already uses for when a
participant may join and when SIRI closes the meeting; `buffer` is
`meeting.zoom_host_capacity_buffer_minutes` (ships 5), an explicit
operational turnaround. With the current 10/5/5 a 10:00–10:30 lesson
occupies 09:45–10:40; the next lesson may begin at 10:55 (its interval
starts exactly at 10:40, and touching is not overlapping). Reservations
are computed from the settings at booking time; shortening the window
leaves existing, longer reservations in place (they are conservative,
never wrong).

**This prevents planned overlap only.** A reservation says two lessons
were never *scheduled* on the host at once. It does not prove a remote
meeting has *ended*: a class that runs past its window still occupies
the licence at Zoom. Ending it at the provider (Zoom auto-end) is a
later phase; until then the join window and `meetings:close-expired`
are the operational controls.

### Lock order — atomic across instructors

Instructor locks are per instructor, so they cannot serialize two
instructors competing for one host. `MeetingHostCapacityService` does,
with database locks, in this fixed order:

1. the instructor advisory lock (existing, `withInstructorLock()`);
2. **the `platform_meeting_hosts` rows, `FOR UPDATE`, ordered
   `sort_order, id` — taken at the very start of the transaction**,
   before any locking read on `bookings`;
3. the existing duplicate/availability re-reads and the booking insert;
4. a **locking** overlap count on `meeting_host_reservations` (so it
   reads the latest committed rows, not the transaction's snapshot),
   then the insert or release.

Step 2 before step 3 is load-bearing: the availability re-read takes
InnoDB gap locks on `bookings`, and a transaction holding those gaps
while waiting for the host rows deadlocks with the host-holder trying
to insert into the same gap. `MeetingHostCapacityConcurrencyTest`
races two real processes — two instructors, one host, one instant —
and asserts exactly one booking and a `MeetingHostCapacityException`
for the loser.

### Reschedule, cancel, expire, finish

- **Reschedule** (`BookingService::reschedule()`): the replacement
  interval is checked and reserved **before** the original is released,
  all in the same transaction under the host lock. If it cannot be, the
  transaction rolls back and the booking keeps both its time and its
  reservation. A booking may shift within its own interval (its own
  reservation never blocks it). Once a Zoom meeting exists the booking
  is **pinned to that meeting's host**; another host with room is not
  used, because a Zoom meeting cannot change user without being
  recreated. History is kept: the old row is `released` with reason
  `rescheduled`.
- **Cancel / hold expiry / complete / no-show**: released in the same
  transaction as the status change (`cancelled`, `hold_expired`,
  `finished`). Idempotent — a replayed event finds nothing active.

### Cancelling the video meeting is not cancelling the lesson

Two different actions, two different effects:

| Action | Booking | Instructor slot | Zoom host reservation | Refund / notifications |
|---|---|---|---|---|
| **Cancel Lesson** (Admin → Bookings → Cancel Lesson; `BookingService::cancel()`) | `cancelled` | freed — `Booking::scopeOverlapping()` only counts pending/confirmed | released (`cancelled`), same transaction | refund policy, `BookingCancelled` listeners, meeting cancelled at the provider |
| **Cancel Video Meeting Only** (`BookingMeetingService::cancelMeeting()`) | unchanged (`confirmed`) | **still reserved** | **still held** | none — the lesson is still on |

"After cancelling the 6 PM meeting the instructor still shows as
occupied" is therefore correct: only the link was cancelled. Cancel the
lesson to free the slot. Cancelling only the meeting exists so the
meeting can be re-created for the same lesson — on the same or another
provider (§4a, `Pin Meeting Provider`) — without ever freeing the slot
in between. `CancellationFreesInstructorSlotTest` proves both.

### Meeting creation — at most one remote create

`BookingMeetingService::createMeeting()` now runs under a per-booking
advisory lock (`booking:meeting:<id>`) and re-reads the row inside it.
The unique `booking_meetings.booking_id` only proved one *local* row;
two callers racing past the "already created?" read (a redelivered
listener and an admin retry) would each have asked Zoom. The lock makes
the remote create at-most-once — `MeetingHostCapacityConcurrencyTest`
counts provider calls across two processes and asserts one.

Before talking to Zoom the service reads the booking's reservation (or,
for a booking accepted before the feature, takes one — refusing clearly
as a failed meeting when there is no room) and creates the meeting
under **that host's** identity (`MeetingCreationContext::$hostReference`),
so the host that was reserved is the host used.

**Ambiguous creates fail closed.** If the create request leaves this
server and no answer returns (connection error, timeout), Zoom may hold
a meeting SIRI has no id for. `ZoomApiClient` raises
`GatewayAmbiguousRequestException`, the provider maps it to
`AmbiguousMeetingCreationException`, and the row is recorded `failed`
with `metadata.remote_state_unknown = true`. Automatic paths never
re-run for a failed row. The next *explicit* attempt (admin retry)
first **queries** Zoom — `GET /users/{host}/meetings?type=upcoming`,
walked page by page, matching the booking reference SIRI writes into
the agenda, and reading `GET /meetings/{id}` for a same-start entry
whose list row omits the agenda — and acts only on evidence:

| Reconciliation result | Action |
|---|---|
| exactly one remote meeting carries the reference (`found`) | aligned (PATCH) and **adopted** as the booking's meeting; audited `meeting_adopted_after_ambiguity` |
| no match, search exhaustive (`none`) | **nothing is created.** A missing result does not prove the original create failed (the listing is eventually consistent and bounded to upcoming meetings). Row stays failed + flagged; what was seen is recorded in `metadata.reconciliation` |
| several matches, or the bounded walk did not reach the end (`inconclusive`) | **nothing is created or adopted.** Candidate ids recorded; row stays failed + flagged |

Repeated attempts repeat only the query. Resolution is a person's
decision, audited with the acting administrator:

```bash
php artisan meetings:resolve-ambiguous BK-…                              # show what reconciliation established
php artisan meetings:resolve-ambiguous BK-… --admin=me@… --adopt=<zoom meeting id>
php artisan meetings:resolve-ambiguous BK-… --admin=me@… --none --reason="Checked the Zoom account: nothing scheduled"
```

`--adopt` aligns and adopts the identified meeting (reserving host
capacity first, like any Zoom meeting); `--none` clears the flag so a
fresh create may proceed. **What the local lock guarantees and what
remains uncertain:** the per-booking lock guarantees SIRI issues at
most one create request per booking at a time and never another once
a row is Created. It cannot know whether an unanswered request reached
Zoom; that gap is closed only by the reconciliation evidence above or
by an administrator who looked.

**Adoption is verified, never trusted.** Whether reconciliation found
the single match or an administrator supplied an id
(`meetings:resolve-ambiguous --adopt=…`), the meeting is READ
(`GET /meetings/{id}`) and checked before anything is written: it
exists; it is hosted by the platform host the booking is reserved on
(Zoom user id or email); no other local booking owns that id; its
agenda does not name a different booking reference; and, when the
agenda carries no reference at all, its start is within 24 hours of
the lesson. Only then is it aligned (PATCH) and adopted. The evidence
is stored with the adoption.

**Evidence is append-only.** `booking_meetings.metadata.ambiguity_history`
records the unanswered create, every reconciliation query with what it
established (status, exhaustive, candidate ids), and the resolution —
adopted (by whom, which id, on what evidence) or declared none (by
whom, why) — each stamped. Declaring none clears the
`remote_state_unknown` flag and keeps everything else; a later
successful create carries the record forward.

**No provider switch after creation.** A booking whose meeting is
`created` on one provider is never moved to another: an explicit
request for a different provider (the admin action, or
`meetings:pin-provider`) is refused with
`MeetingProviderSwitchNotSupportedException`, the existing meeting and
its join link stand, and the administrator is told so — never
"Meeting created". The automatic path stays a silent idempotent no-op
for the same provider. The supported way to route a single booking to
Zoom while Google Meet remains the default is to pin it **before** its
meeting exists (`BookingMeetingService::pinProvider()` →
`bookings.meeting_provider_intent`, reserving the host at once);
`docs/deployment/zoom-activation.md` §6c.

### Rollout control, preflight, backfill

- `meeting.zoom_host_capacity_enabled` (Settings → Meetings → Zoom →
  *Reserve Zoom Host Capacity*) ships **off**. It governs whether NEW
  reservations may be granted. Zoom is capacity-governed **whether the
  switch is on or off**: a Zoom-bound booking is never accepted without
  a reservation, so with the switch off Zoom-bound acceptance is
  **refused** (fail closed), not accepted unreserved. Existing
  reservations keep being honoured, moved and released regardless.
- `php artisan meetings:zoom-hosts:register` creates the host row from
  the configured host identity (idempotent; `--capacity`, `--label`,
  `--host` for an additional licence).
- `php artisan meetings:zoom-hosts:preflight [--days=60]` is
  **read-only**: the pool and its capacity; every upcoming accepted
  (pending or confirmed) online booking that will run on Zoom — pinned
  to it, already carrying a Zoom meeting, or unpinned while Zoom is the
  default — and holds no active reservation; and where their planned
  intervals plus existing reservations exceed capacity. Generated
  recurring occurrences and pending-payment holds are bookings and are
  covered; occurrences not yet generated reserve when they are. Exit 1
  when anything needs attention. It rewrites nothing.
- `php artisan meetings:zoom-hosts:backfill [--apply]` is the
  supported reconciliation for bookings accepted before the feature:
  earliest lesson first, each unallocated Zoom-bound booking is given a
  reservation under the same host lock and interval rules as a fresh
  acceptance, and a legacy Zoom meeting is pinned to the host it lands
  on. What does not fit is reported and left untouched — the operator
  reschedules it to a free hour or cancels it. **A booking whose Zoom
  meeting already exists is never switched to another provider**; that
  transition is not supported. Dry-run by default; deliberately usable
  while the switch is still off, because that is how the pool is made
  clean before enabling.

**Two gates are enforced on the settings page**, so the stored state
can never express an unsafe combination:

1. the switch cannot be turned **on** while the preflight has findings
   (the page runs the same `ZoomHostCapacityPreflightService` and
   refuses the save with the summary);
2. Zoom cannot be made the **default provider** while the switch is
   off (every Zoom booking would be refused).

**Rollback — safe sequence.** 1) Set `default_provider` back to Google
Meet: new bookings are accepted for Meet and pinned to it. 2) Only then
turn *Reserve Zoom Host Capacity* off. Afterwards: new Zoom-bound
acceptance (an explicit admin Zoom choice, or a Zoom default) is
refused; existing pending holds keep their reservations and are
released by the normal expiry/cancel path; existing pinned bookings
keep `meeting_provider_intent = zoom` and, if reserved, still get their
Zoom meeting on the reserved host; a pinned booking with no reservation
gets no meeting until backfilled; reschedules of reserved bookings
still check capacity, reschedules of unreserved Zoom bookings are
refused; created Zoom meetings and all reservation history are
untouched. Nothing needs a migration to undo.

### Known limits (this phase)

- The wizard's slot list and the recurring **preview** do not consult
  host capacity; a slot the instructor has free can still be refused at
  submit with the message above, and a recurring occurrence can be
  recorded as a conflict that the preview did not predict.
- Reconciliation matches on the agenda text SIRI writes. If the
  listing ever omits both the agenda and an exact start time, an
  ambiguous create resolves only by an administrator's decision.
- The `none` reconciliation result is deliberately not trusted as
  proof of a failed create; an operator confirms in the Zoom account.
- Capacity is per provider pool, not per country or instructor group.
- See "does not prove a remote meeting has ended" above.

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

## 5b. Meeting window — when a lesson can be joined, and when it closes

A lesson's meeting is bounded by the lesson's own timeslot. For a
10:00–11:00 class with the current settings (10 before / 5 after; the
package shipped 15/15, changed by the 2026-09-11 completion-policy
settings migration — see docs/lessons.md, Completion policy):

```text
        09:50              10:00 ─── lesson ─── 11:00              11:05
          │                                                          │
   join link appears                                    link withdrawn AND
   (visible_before)                                     meeting closed at the
                                                        provider (visible_after)
```

**The link participants receive is SIRI's, not the provider's.** Every
student- and instructor-facing surface (booking detail, both
dashboards, the student's My Bookings "Next up" card and rows, Upcoming
Classes, `StudentBookingResource`, the meeting-created/updated
notifications) carries `/dashboard/meetings/{booking}/join`
(`BookingMeetingService::joinLinkFor()`, `MeetingJoinController`). It
is an authenticated gateway on SIRI's own domain — not a custom Zoom
domain and not a proxy of the meeting. Following it re-runs the whole
decision at click time — signed in and active (dashboard middleware),
participant of this booking (`BookingPolicy::view`, 403 otherwise),
the student's strict lifecycle guard or the instructor's publicly
visible status, the role visibility setting, booking `confirmed`,
meeting `created`, and the window below — and only then answers a 302
to the provider's **participant** join URL, audited as
`meeting_join_redirected` (provider and role, never the URL). Outside
the window it renders a "not yet" / "cannot be joined" page. The Zoom
host start URL is never returned by anything. A copied link therefore
stops working the moment the lesson ends, the booking is cancelled or
the account is suspended. Google Meet bookings take the same path.
`MeetingJoinGatewayTest` covers the matrix.

**Dedicated participant host.** `MeetingSettings::participant_join_base_url`
(Admin → Settings → Meetings → "Join Link Domain") must be a bare HTTPS
origin such as `https://meet.sirieducation.com` — no path, query,
fragment or credentials; anything else is rejected on save and an
unusable stored value falls back to the main host. When set,
`joinLinkFor()` generates `<origin>/join/{booking}`. `APP_URL` is not
changed and links already sent on the main host keep working; both
paths run the same controller and checks.

The session cookie is host-only (`SESSION_DOMAIN` unset), so a
participant signed in on the main site is a guest on the meeting host.
`ConsumeMeetingJoinHandoff` sends that guest to the main host's
`/dashboard/meetings/{booking}/handoff` (served only on the main host,
behind the normal dashboard middleware, so login with intended URL
applies). That endpoint issues a random token bound to the user, the
booking and the meeting origin, valid 60 s, audited as
`meeting_join_handoff_issued`, and redirects back with `?handoff=`. The
meeting host redeems it **exactly once across all web nodes** — the
redemption marker is an atomic `Cache::add()` on the shared store, so
two nodes cannot both accept the same token — and only over HTTPS on
the configured origin. Redemption does **not** sign the user in: it
stores a booking-scoped **join grant** in the meeting host's session
(10 minutes) that only `/join/{booking}` for that booking honours;
dashboard and account routes on that host still see a guest, and a
token is never applied over a different user already signed in there.
The gateway then applies every rule as usual, including account status.
Responses that carry the token are `Cache-Control: no-store` and
`Referrer-Policy: no-referrer`; see `docs/deployment/meeting-join-domain.md`
for access-log redaction, since the redirect to a clean URL does not
remove the token from the original request line in server logs.

**One window, two enforcement points.**
`BookingMeetingService::joinAvailabilityFor()` decides what SIRI hands
out; `closeExpiredMeeting()` decides what stays alive at the provider.
Both read the same `ends_at + meeting_link_visible_after_minutes`, and
the boundary instant itself is still joinable — closing waits until
strictly past it, so a participant is never shown a link to a meeting
that has been shut.

**Why closing matters, not just hiding the link.** A Meet space
outlives any single conference. Withholding the link stops SIRI
advertising it, but a copied link could still keep a conference running
— or start a new one — after class, and on a recording-eligible lesson
that conference **keeps recording**: footage of an empty or unrelated
room, stored and retained as if it were the lesson.

**How a lesson is closed** (`meetings:close-expired`, every five
minutes, `MeetingSettings::meeting_auto_close_enabled`):

1. the space is narrowed to `accessType: RESTRICTED`, so a kept link
   cannot start another conference in it;
2. any conference still running is ended, which is also what stops an
   automatic recording.

Restriction is best-effort — if it fails, the conference is still
ended, because stopping what is running matters more than preventing a
hypothetical rejoin. The sweep is idempotent (a closed meeting records
`metadata.closed_at` and leaves it), isolates failures per meeting, and
looks back only 48 hours so it never becomes a scan of all history.

**Boundary, stated plainly.** Only a space SIRI created through the
Meet API can be closed this way — Meet's `meetings.space.created` scope
does not reach a Calendar-created conference. Lessons that fell back to
a Calendar conference (see §3) are governed by link withholding alone;
they are recorded as `closed_at_provider: false` rather than silently
retried forever. Manual and Zoom meetings implement no closing
capability today and are simply skipped.

Nothing else moves: closing ends what is running and writes
`metadata.closed_at`. It never changes `booking_meetings.status` (which
describes how the meeting was CREATED), the booking, or the lesson.

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
| `meeting_link_visible_before_minutes` / `..._after_minutes` | the join window around the lesson (10/5 since the completion-policy migration; shipped 15/15) |
| `meeting_auto_close_enabled` | close the meeting at the provider when that window ends (§5b) |
| `default_provider` | provider for new meetings |
| `google_meet_enabled` / `zoom_enabled` / `manual_provider_enabled` | may create meetings |
| `google_meet_recording_enabled` / `zoom_recording_enabled` | may record |
| `recording_enabled`, `recording_retention_days` | platform recording policy; retention counts from `recorded_at`, ships 30 days (`docs/recordings.md` §11a) |
| `zoom_account_id`, `zoom_client_id`, `zoom_client_secret` | Server-to-Server OAuth (secret encrypted) |
| `zoom_host_user_id` / `zoom_host_email` | platform host the meetings run under |
| `zoom_host_capacity_enabled`, `zoom_host_capacity_buffer_minutes` | one-host capacity reservation (§4a); ships off / 5 |
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
