# Phase 3.1 — EducationSystemLevel & Simplified Country-Aware Booking Levels

Refactor of the (still-uncommitted at the time of writing) Phase 3
country-aware Free Demo booking flow. Phase 3 shipped a student flow of
`Education System → Academic Level → Subject → Curriculum → Grade`,
deriving grade options from `AcademicLevel.min_grade/max_grade` (with a
hardcoded `1..12` fallback when a level defined no band) and writing a
hardcoded `"Grade %d"` string into the booking snapshot. Phase 3.1
replaces the two-step Academic Level + Grade choice with one
admin-configured, student-facing entity — `EducationSystemLevel` — and
removes every hardcoded `1..12`/`"Grade %d"` assumption from the
country-aware flow.

## Model

`EducationSystemLevel` (`app/Models/EducationSystemLevel.php`,
`education_system_levels` table) is the exact, selectable level a
student picks under an Education System — "Class 10" (CBSE), "Grade 10"
(US), "Year 10" (UK). It is **not** the same concept as `AcademicLevel`
(the broad internal band — Middle School/Secondary/Higher Secondary —
used for Curriculum ownership, reporting, and future Learning Plan
integration). Selecting an `EducationSystemLevel` implies its
`academic_level_id`; students never choose `AcademicLevel` directly.

Fields: `id` (uuid), `education_system_id`, `academic_level_id`,
`value` (e.g. `"10"`), `display_label` (e.g. `"Class 10"`),
`normalized_grade` (nullable `unsignedTinyInteger`), `display_order`,
`is_active`, `created_by`/`updated_by`, timestamps, soft-deletes.
Unique on `(education_system_id, value)`.

### Why a new table, not a reuse of `education_system_academic_level`

The existing pivot (`EducationSystemAcademicLevel`) is unique per
`(education_system_id, academic_level_id)` — one row per broad band. A
system legitimately needs several distinct levels sharing one band
(CBSE Class 6, 7, and 8 all map to the "Middle School" `AcademicLevel`),
which the pivot's uniqueness constraint cannot represent. No FK was
added to `academic_levels` itself — both FKs live on this new
mapping-style table, matching the existing pivot's pattern and
respecting `docs/architecture/domain-registry.md`'s "do not add
`academic_levels.education_system_id`" rule. The old pivot is
untouched and keeps its original purpose (declaring which broad bands
a system spans); `EducationSystemLevel` is additive.

## Terminology configuration

`EducationSystem` gained `level_term_singular`/`level_term_plural`
(e.g. `"Class"`/`"Classes"`, `"Grade"`/`"Grades"`, `"Year"`/`"Years"`).
`EducationSystem::levelTermSingular()`/`levelTermPlural()` return the
configured value or fall back to the generic `"Level"`/`"Levels"` —
never hardcoded per-country PHP/Blade branching. Owned by
`EducationSystem` (not `Country`) because one country can host multiple
education systems, each with its own terminology.

## Resolver integration

`AcademicContextResolver` (Curriculum domain, unchanged in scope/intent)
gained two additive methods:

- `levelsForSystem(Country, EducationSystem): Collection<EducationSystemLevel>`
  — active levels for a system, ordered by `display_order`. Returns an
  empty collection when none are configured; callers must show an
  "unavailable" state, never synthesize a `1..12` fallback.
- `resolveContextForLevel(Country, EducationSystem, EducationSystemLevel, Subject, Curriculum): AcademicContextData`
  — validates the level belongs to the system and is active, that its
  linked `AcademicLevel` is active, then derives the `AcademicLevel`
  and delegates entirely to the existing `resolveContext()`. No
  parallel resolution logic exists.

### DTO decision: extend `BookingAcademicContextData`, not `AcademicContextData`

`App\Curriculum\DTOs\AcademicContextData` is the Curriculum domain's
generic, persistence-agnostic id carrier — intended to be reused by
booking, instructor eligibility, and future Learning Plan integration
alike. Adding Booking-specific snapshot fields (level term/value/
display) to it would couple that shared DTO to one consumer's
presentation needs. Instead, the already Booking-domain-specific
`App\Booking\DTOs\BookingAcademicContextData` (which previously carried
the hardcoded `academicLevelGrade` string) was extended with
`educationSystemLevelId`, `levelTerm`, `levelValue`, `levelDisplay`,
`normalizedGrade`. This keeps the Curriculum domain's DTO clean while
reusing an existing Booking-domain type rather than introducing a third
parallel structure.

## Non-numeric levels: currently unsupported for Demo booking

`normalized_grade` is nullable to allow future non-numeric levels
(Undergraduate, Foundation, ...) without forcing a fake int.
`TeacherSubject`/`TeacherCandidateRepository`/`AssignmentCriteriaData`
candidate matching is numeric-grade-based throughout the codebase, and
no existing business rule defines a subject-only matching fallback.
Rather than invent one, `DemoAcademicContextResolver::resolveForDemo()`
throws a `BookingException` ("This level is not currently supported for
demo booking. Please select a different level.") when the selected
level has no `normalized_grade`; the Livewire wizard refuses the
selection with the same message before ever reaching submission. This
is a documented current limitation, not a permanent design constraint —
a future phase can add non-numeric candidate matching without touching
this snapshot shape.

## Booking snapshot

`BookingAcademicContext` (`booking_academic_contexts` table) replaced
`academic_level_grade` (a hardcoded `"Grade %d"` string) with
`education_system_level_id`, `level_term`, `level_value`,
`level_display`, `normalized_grade` — all denormalized at booking time
so a later admin rename of the `EducationSystemLevel`'s label never
rewrites a booking's historical display. `academic_level_id`/
`academic_level_name` remain as the internal broad-classification link.
`bookings.meta.grade` continues to be written for legacy downstream
readers, sourced from `EducationSystemLevel.normalized_grade` (never
the display value or id).

## Wizard flow

`BookingWizard` (Livewire) collapsed the old
`education_system → academic_level → academic_subject → curriculum → grade`
phase sequence into
`education_system → level → academic_subject → curriculum` — one
dynamic `level` phase, heading `"Choose a {Class|Grade|Year|Level}"`
derived from the selected system's configured terminology. Selecting a
level implicitly resolves `academic_level_id` and `normalized_grade`
server-side; the client never independently submits a raw grade for
this flow. The legacy (non-academic) `subject → grade` phases and their
hardcoded `1..12` array remain, used only while the feature is off
globally or for a student's specific country (§14 rollout policy) —
they are not touched by this refactor.

## Admin UX

`EducationSystemResource` (Filament) gained an `EducationSystemLevelsRelationManager`
("Levels" tab) alongside the existing "Countries", "Academic Levels",
and "Curricula" tabs — add/edit/remove a level (broad Academic Level,
value, display label, normalized grade, active flag, display order),
all mutations routed through new `EducationSystemService::addLevel()`/
`updateLevel()`/`removeLevel()` methods, never raw Eloquent
attach/detach, matching every other mapping in this domain.
`AcademicLevelForm`'s `min_grade`/`max_grade` fields were relabeled
"(internal)" with corrected helper text — they are internal grade-band
metadata only, no longer the source of truth for country-aware booking
level options — and their hardcoded `maxValue(12)` was removed so a
future band spanning e.g. Year 13 remains representable.

## Timezone architecture (audited, unchanged)

Traced end to end: `UserTimezoneResolver::resolve()` (student's
stored `user_profiles.timezone`, `GeneralSettings::default_timezone`
fallback, `UTC` last resort) → `BookingWizard::$timezone` →
`WizardBookingService::availableDates()/availableSlots()` →
`AvailabilityQueryData::$timezone` → `AvailabilityService::slots()`
(computes every conflict/holiday/blackout check in UTC, converts to the
caller-supplied timezone only in the final step) → `Booking::timezone`
(frozen at submit time). This was already correct — the country-aware
Demo flow did not require any timezone code changes. See
`tests/Feature/Booking/BookingWizardStudentTimezoneSlotsTest.php` for
dedicated same/different-timezone, date-crossover (both directions),
DST, submission-instant-equality, and historical-display-stability
coverage, and `docs/booking.md` for the pipeline summary.
