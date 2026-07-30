# User Lifecycle Foundation

## Executive Summary

`users` is the single identity table for every account — students, instructors, managers, super admins. There is no separate `StudentProfile` or `InstructorApplication` table: both roles share one `UserProfile` row (1:1 with `User`), distinguished by which of two independent, nullable lifecycle columns is populated — `student_status` and `instructor_status`. A user with neither role set has a profile but both columns stay null.

This phase did not create new profile tables. It: expanded `InstructorStatus` to the full required lifecycle, added the previously-missing `StudentStatus` enum/column, and — while auditing requirement 1 — found and fixed a real bug where every registration silently created two profile rows per user with no database constraint stopping it.

## Three Separate Statuses

| Status | Lives on | Values | Meaning |
|---|---|---|---|
| `User::status` | `users.status` | `pending_verification`, `active`, `inactive`, `blocked`, `suspended` | Account/login eligibility. Can this person authenticate at all? |
| `StudentStatus` | `user_profiles.student_status` (nullable) | `registered`, `active`, `suspended`, `archived` | Student-side lifecycle. Null = never held the `student` role. |
| `InstructorStatus` | `user_profiles.instructor_status` (nullable) | `draft`, `submitted`, `under_review`, `documents_pending`, `interview_required`, `approved`, `active`, `vacation`, `suspended`, `archived`, `rejected` | Instructor application/professional lifecycle. Null = never applied to teach. |

These are deliberately independent. A `blocked` user (can't log in at all) can still have `instructor_status = Active` on record — blocking is an account-level decision, not a professional-status decision, and un-blocking them shouldn't require re-approving their instructor application. Conversely, an `active` user (can log in fine) with `instructor_status = Suspended` is exactly the "temporarily can't teach, but the account itself is fine" case.

## Instructor Status: What Changed and Why

The existing `InstructorStatus` enum had 4 values: `Pending`, `Approved`, `Rejected`, `Published`. The required Phase 1 lifecycle has 11. Two of the old values were **renamed**, not left duplicated alongside new ones:

- `Pending` → `Submitted` — "awaiting review" is the same concept under the spec's name.
- `Published` → `Active` — this was already the "publicly visible and bookable" state; the spec's lifecycle calls that state `active`, not a separate publishing step.

`Approved` and `Rejected` are unchanged. Seven new values were added with no code path producing them yet (admin-settable via the existing Filament Select, which reads options directly off the enum): `Draft`, `UnderReview`, `DocumentsPending`, `InterviewRequired`, `Vacation`, `Suspended`, `Archived`.

**Column type change**: `instructor_status` was a native MySQL `enum('pending','approved','rejected','published')` — physically incapable of holding 11 values. `database/migrations/2026_07_08_100000_expand_instructor_status_lifecycle.php` widens it to `string(30)` (the same string-backed-enum convention as `AcademicStatus`, `PageStatus`, `FaqStatus`) and backfills existing rows: `pending`→`submitted`, `published`→`active`.

**Bookability preserved, not tightened**: an `Approved` instructor was already bookable before this rename (nothing gated on `Active`/`Published` alone). `InstructorStatus::bookable()` returns `[Approved, Active]` — both remain the eligibility set for booking, public listing, and public profile viewing. This was a deliberate choice: requiring the new `Active` state on top of `Approved` would have silently deactivated every already-approved instructor in the database. `InstructorStatus::bookable()` / `::bookableValues()` also replaced four separate hardcoded `[Approved, Published]` arrays (`InstructorService`, `InstructorPolicy`, `TeacherCandidateRepository` ×3, `TeacherAvailabilityForm`, `TeacherLeaveForm`) with one source of truth.

## Student Status: New

`StudentStatus` (`registered`/`active`/`suspended`/`archived`) and `user_profiles.student_status` are new — added in `database/migrations/2026_07_08_100100_add_student_status_to_user_profiles_table.php`. `RegistrationService` sets it to `Registered` immediately after assigning the configured default role, **only when that role is literally `student`** — not unconditionally, since `RegistrationSettings::default_role` is admin-configurable and isn't guaranteed to be `student`. The migration backfills `registered` for existing users already holding the `student` role.

## Bug Found and Fixed: Duplicate Profile Creation

Auditing requirement 1 ("every student can have exactly one student profile") surfaced a real defect: `UserObserver::created()` already creates a `UserProfile` for every new `User` unconditionally — but `RegisterUserAction` *also* explicitly called `UserProfile::create()` right after. Both fired on every registration. This wasn't caught before because the migration that declares `user_id` as `unique()` never actually applied that constraint to the live schema (the column existed as a plain, non-unique foreign-key index) — so the second insert succeeded silently instead of throwing.

Fixed in two places:
1. `RegisterUserAction` no longer calls `UserProfile::create()`. It now updates the profile the observer already created, only to set `phone` (the one field registration collects that the profile doesn't default).
2. `database/migrations/2026_07_08_100200_enforce_unique_user_id_on_user_profiles_table.php` dedupes any existing duplicate rows (kept the earliest per `user_id`) and adds the missing unique index — the 1:1 guarantee is now enforced by the database, not just by convention.

## What Was Reused (Deliverable: Summary of Reused Code)

Nothing new was built for the concepts below — they already existed and needed no changes beyond what's described:

- **`UserProfile`** — the shared profile table for both students and instructors (1:1 with `User` via `user_id`). Already had `avatar`/`cover` on Spatie Media Library (`registerMediaCollections()`), `profile_visibility`, and the instructor-only fields (`is_featured`, `is_instructor_verified`, `assignment_priority`). Reused as-is; only `student_status` was added.
- **`user_educations` (`UserEducation`)** — already a full model with `education_level` (`App\Enums\EducationLevel` — an instructor's own credential type, e.g. Bachelor/Master/Doctorate; unrelated to the Academic Master Foundation's `AcademicLevel`), country/state FKs, and Media Library document collections (`certificate`, `transcript`, `degree_document`). This already functions as the education-credential half of instructor verification — no KYC table was built on top of it.
- **`user_experiences` (`UserExperience`)** — already a full model with `employment_type` (`App\Enums\EmploymentType`), `skills` (array), and Media Library collections (`company_logo`, `supporting_documents`). Reused as-is for the professional-history half of instructor verification.
- **Profile photo / Media Library** — `UserProfile::avatar`/`cover`, `User::instructor_cover` (public-profile banner, kept separate from the profile's own `cover` since one is the generic profile cover and the other is instructor-page-specific), `UserEducation`'s three document collections. All already Spatie Media Library; nothing moved to a new mechanism.
- **Activity logging** — `UserProfileObserver` already logs every `instructor_status` change generically as `profile_{status->value}` via `AuditTrailService`, and `NotifyInstructorOnProfileActivity` already notifies the instructor on approved/rejected/(now)active transitions. Both are status-value-driven, so the enum rename required updating only the specific `match` arms referencing the old names (`profile_published` → `profile_active`) — the logging mechanism itself needed no change.
- **Policies** — `ProfilePolicy` (self profile, gated on `User::isActive()`), `InstructorPolicy` (public instructor-profile visibility, gated on role + `profile_visibility` + `InstructorStatus::bookable()`), and `UserPolicy` (Shield-style admin CRUD permissions) already cover profile access at every level actually exercised today. No new policy classes were added; `InstructorPolicy` was simplified to call the new shared `InstructorStatus::bookable()` helper instead of duplicating the state list.

## What Is Explicitly Not Built Yet

Per the spec's own boundary:
- No full KYC/document-verification workflow — `UserEducation`'s existing document collections remain the only document-upload surface; nothing new was added.
- No self-service "become an instructor" application flow — instructor status is still admin-driven entirely through the Filament User form's `instructor_status` Select. The 11-state lifecycle now *supports* a future multi-step application flow (draft → submitted → under_review → documents_pending/interview_required → approved → active), but nothing currently transitions a profile through those intermediate states automatically.
- No enforcement wiring for `Draft`/`UnderReview`/`DocumentsPending`/`InterviewRequired`/`Vacation`/`Suspended`/`Archived` beyond "not in the bookable set" — these are available states an admin can set, not states any workflow currently produces.

## Tests

- `tests/Unit/Enums/InstructorStatusTest.php`, `tests/Unit/Enums/StudentStatusTest.php` — every case has a label/color; the enum has exactly the required value sets; `bookable()`/`bookableValues()` correctness.
- `tests/Feature/Instructor/InstructorLifecycleTest.php` — for every non-bookable status: excluded from public listing, public profile forbidden, excluded from booking eligibility (`TeacherCandidateRepository`). For both bookable statuses: included in all three.
- `tests/Feature/Security/RegistrationIntegrationTest.php` — `student_status` set to `Registered` only when the default role is `student`; stays null otherwise; registration creates exactly one profile row.
- `tests/Feature/Profile/UserProfileCreationTest.php` — a second `UserProfile::create()` for an existing `user_id` now throws `UniqueConstraintViolationException` (regression test for the fixed bug).
- Existing instructor tests (`InstructorServiceTest`, `InstructorDetailTest`, `InstructorListingTest`, `InstructorActivityLogTest`, `InstructorNotificationTest`) updated for the renamed values, unchanged in intent.
