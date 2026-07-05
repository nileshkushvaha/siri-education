# Subject / TeacherSubject Reconciliation

## Executive Summary

This is a small, focused fix for the one open risk flagged in the Phase 1 implementation audit: `Subject` (the admin-managed academic master introduced in the Academic Master Foundation phase) and `TeacherSubject.subject` (the pre-existing free-text field every booking flow reads) were two unreconciled mechanisms for the same real-world concept. **`Subject` is now the long-term source of truth.** `TeacherSubject` gained an optional, nullable `subject_id` link to it. Nothing else changed: the free-text `subject` column, every booking DTO, every booking query, and every existing test keep working exactly as before.

This was deliberately **not** a rebuild. No table was dropped, no column was removed, no booking behavior changed. It is the smallest change that gives future code (starting with Instructor Onboarding) a structured `Subject` to select from, without breaking anything that depends on the string today.

## What Was Reconciled and How

| Before | After |
|---|---|
| `TeacherSubject.subject` (string) — the only way to record what a teacher teaches | Unchanged, still there, still what every booking query reads |
| No link between a `TeacherSubject` row and the `Subject` master | `TeacherSubject.subject_id` (nullable uuid, FK to `subjects.id`, `nullOnDelete`) |
| No relationship between the two models | `TeacherSubject::subjectMaster(): BelongsTo Subject`; `Subject::teacherSubjects(): HasMany TeacherSubject` |
| `InstructorService::subjectsFor()` always formatted the raw string | Prefers `subjectMaster->name`/`slug` when linked; falls back to the same formatted string as before when not |

## Why This Approach (Not a Rebuild)

- **`TeacherSubject` was not removed or replaced.** It has no Filament admin UI today (confirmed by inspection — no resource, no relation manager exists for it) and is populated only by tests/factories in this codebase currently. Even so, every booking DTO (`AssignmentCriteriaData`, `GuestBookingData`, `StudentBookingData`) and the assignment repository (`TeacherCandidateRepository`) read the plain string, and removing it would require rewriting all of them — explicitly out of scope for this task ("Do not build pricing/curriculum/booking changes in this phase").
- **A new `InstructorSubject` pivot table was not created.** The existing `TeacherSubject` table already *is* the "what does this teacher teach" record; adding a nullable FK column to it is strictly less than creating a parallel table, and the task's own rules require a strong, approved reason before adding a new pivot — none existed.
- **The booking-matching logic (`scopeForSubject`, `TeacherCandidateRepository`) was deliberately left untouched.** Booking criteria are still plain strings end to end. Making search/booking "Subject-aware" would mean deciding how a guest's free-text search term maps to a `Subject`, which is a real design question (fuzzy matching? category-level search? synonyms?) that belongs to a future phase, not this reconciliation.

## Backfill Strategy

A single migration (`2026_07_09_100000_add_subject_id_to_teacher_subjects_table.php`) both adds the column and backfills existing rows:

1. Add `subject_id` (nullable) to `teacher_subjects`.
2. For every row, look for a `subjects` row whose `name` matches the free-text `subject` value **case-insensitively** — exact match only, no fuzzy matching.
3. Match found → set `subject_id`. No match → leave it `null`; the row keeps working exactly as before (free-text display and booking matching), and the migration logs the unmapped count and the distinct unmapped values for follow-up.
4. Already-linked rows (`subject_id` already set, however that happened) are never touched — the `UPDATE` only targets `WHERE subject_id IS NULL`.

**This was verified against realistic data, not assumed.** The existing test factories and fixtures use lowercase, generic terms like `'maths'` and `'science'` — these are **category** names in the seeded academic data (`Mathematics`, `Sciences`), not subject names (the actual subjects are `Algebra`, `Physics`, etc.), so they correctly do **not** match and stay `null`. `'english'` and `'history'` do match existing `Subject` rows case-insensitively and correctly get linked. This confirms the "exact match only, document the rest" approach is the right one — a looser matching strategy would have either missed real matches or created wrong ones (e.g., auto-creating a "Maths" subject distinct from the "Mathematics" category would have made things worse, not better).

Archived/inactive `Subject` rows are still valid backfill targets — a `TeacherSubject` row describes a fact about what a teacher already teaches, not a new assignment, so a subject being archived later shouldn't sever an existing historical link.

## What Still Reads the Free-Text Column (Unchanged)

- `TeacherSubject::scopeForSubject()` / `scopeCoveringGrade()`
- `TeacherCandidateRepository::eligible()` / `isEligible()` / `availableSubjects()`
- `AssignmentCriteriaData::$subject`, `GuestBookingData::$subject`, `StudentBookingData::$subject` (all plain `string`)
- `InstructorService::availableSubjects()` (the marketplace search-filter dropdown)

None of these were touched. Booking and search continue to match on the free-text string exactly as before, regardless of whether a given `TeacherSubject` row has a `subject_id` or not.

## What Now Prefers the Subject Relation (Changed)

- `InstructorService::subjectsFor(User $instructor)` — used by the instructor card and public profile to render "what this instructor teaches." Now returns the linked `Subject`'s `name`/`slug` when `subject_id` is set, and falls back to the same `formatSubject()`-humanized free-text string as before when it isn't. This is a **display-only** change — it does not affect search, booking, or eligibility in any way.
- `InstructorService::cardRelations()` eager-loads `teacherSubjects.subjectMaster` (was `teacherSubjects`) to avoid an N+1 when the above preference check runs across a listing page.

## Migration Safety

- **Safe to apply**: purely additive (new nullable column + FK), verified against the current dataset (0 existing `teacher_subjects` rows in this environment — confirmed via direct inspection before writing the migration, so there was no real backfill to perform here, but the logic was verified correct via tests that simulate populated data).
- **Rollback**: `down()` drops the FK and column — safe, since nothing outside this reconciliation reads `subject_id` yet.
- **Production data note**: if a production environment has existing `teacher_subjects` rows whose free-text values don't exactly match any `Subject.name`, they will simply keep `subject_id = null` and continue working exactly as they do today — no data is lost, no booking flow breaks. The migration's log output lists exactly which free-text values didn't match, so an admin can decide whether to add matching `Subject` rows later.

## Confirmation: Old Booking Flow Still Works

Directly tested, not assumed:
- A `TeacherSubject` with no `subject_id` (the old shape) is still returned as a valid booking candidate by `TeacherCandidateRepository::eligible()`/`isEligible()`.
- The full existing Booking/Guest/Student booking test suites (129 tests across `tests/Feature/Booking`, `tests/Feature/Guest`, `tests/Feature/Student`) pass unchanged.
- The full existing Instructor test suite (94 tests) passes unchanged.

## Phase 2 Direction

**New instructor onboarding (Phase 2) must select `subject_id` from the `Subject` master, not free-text.** The `subject` column stays populated for backward-compatible display/matching (it can be derived from the selected `Subject`'s name at write time, the same way it already is for any linked row), but the master is the source of truth for anything new. This reconciliation deliberately does not build that onboarding UI — only the data model it will need.

## Files Changed

- `database/migrations/2026_07_09_100000_add_subject_id_to_teacher_subjects_table.php` (new)
- `app/Models/TeacherSubject.php` (added `subject_id` to `$fillable`, added `subjectMaster()` relation, docblock updated)
- `app/Models/Subject.php` (added `teacherSubjects()` relation, docblock updated)
- `app/Services/Instructor/InstructorService.php` (`subjectsFor()` prefers the relation; `cardRelations()` eager-loads it)
- `tests/Feature/Academic/SubjectTeacherSubjectReconciliationTest.php` (new — 8 tests)
