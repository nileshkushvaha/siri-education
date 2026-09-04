# Configuration audit — `platform:audit-config`

Read-only check of the **data** a working platform depends on but no test can
see. Run it against staging or production after every configuration change and
before every release:

```bash
php artisan platform:audit-config            # human-readable table
php artisan platform:audit-config --json     # for CI / dashboards
php artisan platform:audit-config --strict   # warnings also fail the run
```

Exit code is `1` when anything fails, so it can gate a deploy pipeline. It never
writes to the database.

## What it checks

| Section | Fails when | Why it matters |
|---|---|---|
| Payments | An enabled provider has no key/secret; no webhook secret at all; no secret valid for the **wallet**, **booking**, or **package** webhook endpoint; the fake provider is on in production | A missing wallet webhook secret meant every Razorpay delivery was rejected with 401 and recharges credited only via the 10-minute sweep (4 Sep 2026). |
| Countries & currencies | An active country has no default currency, an inactive one, or one Razorpay cannot collect without International Payments | A student in that country can register but can never pay. |
| Lesson prices | A paid booking type has no base price row for a subject taught by an approved instructor in a country that has students; a row's duration differs from its type's; a row points at an inactive level | The student sees *"The lesson price is not configured yet"* at the review step. |
| Time zones | A profile or availability window carries a non-IANA time zone | Slots and reminders silently fall back to the platform default. |
| Instructor availability | An approved instructor has no active window | They never appear in search or the wizard. |
| Academic levels | No active level; or (warning) several levels cover the same grade in one country | Price rows keyed on a level must be on the one students pick — see `architecture/phase-10.2d-student-pricing-matrix.md`. |
| Meetings & recordings | Meetings disabled or default provider unconfigured; recording captured but feature flag or student playback off; (warning) auto-completion delay ≥ 12 h | "Meeting link is being prepared" forever; recordings in Drive that no student can open. |
| Queue | Database queue has a job waiting ≥ 15 min (no worker); (warning) failures in the last 24 h | Webhooks, recording capture and emails all ride the queue. |

## Explaining one student's recording — `recordings:explain`

```bash
php artisan recordings:explain BK-6QEOFCJ6NB
```

Prints every gate `RecordingPlaybackAccessResolver` applies for that booking — playback switch, country feature flag, student standing, lesson finalized as Completed, recording ingested, not withheld — marks each OPEN or CLOSED, and names the first closed one. The student UI shows nothing for most closed gates by design, so this is how support answers "the recording is in Drive but the student cannot see it".

Implementation: `App\Platform\Audit\PlatformConfigAuditor` (checks) and
`App\Console\Commands\AuditPlatformConfig` (presentation). Add a new check as a
private method on the auditor returning `ConfigAuditFinding`s and cover it in
`tests/Feature/Platform/AuditPlatformConfigTest.php`.
