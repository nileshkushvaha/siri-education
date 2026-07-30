# Academic Master Foundation

## Executive Summary

The SRS defines the Academic Framework as the shared foundation for marketplace search, instructor expertise, learning plans, homework, pricing, analytics, and future AI features. This Phase 1 pass adds only the **stable academic masters** those future features will read from — not the curriculum/roadmap/competency engine itself, and not a rewrite of the existing booking flow.

Four new admin-managed master tables were added:

| # | Model | Table | Purpose |
|---|---|---|---|
| 1 | `AcademicCategory` | `academic_categories` | Top-level grouping for subjects (Mathematics, Sciences, Languages, ...) |
| 2 | `Subject` | `subjects` | A teachable subject, belongs to one `AcademicCategory` |
| 3 | `AcademicLevel` | `academic_levels` | Named grade band (Primary, Middle School, Undergraduate, ...) |
| 4 | `SkillLevel` | `skill_levels` | Optional proficiency label (Beginner/Intermediate/Advanced/Expert) |

All four are **master data only**. None of them is wired into the live booking flow yet — `TeacherSubject.subject` remains a free-text string, exactly as before. That migration is a deliberate, separate later phase.

## Why Existing Concepts Were Not Duplicated

Before adding anything, the existing codebase was inspected for: subject categories, subjects, education levels, skill levels, languages, subject-country mapping, subject pricing, instructor-subject relationships, Filament resources, policies, and tests. This is what already existed and why each was left alone or reused instead of re-created:

- **`TeacherSubject`** — the table instructor booking criteria actually read (`subject` is a plain `string(100)` column, used throughout `AssignmentCriteriaData`, `GuestBookingData`, `BookingWizardService`, `GuestBookingService`, `StudentBookingService`, `TeacherCandidateRepository`, `InstructorService`). Left untouched. `Subject` (this phase) is a parallel, admin-managed catalog — not yet a replacement.
- **`App\Enums\EducationLevel`** — already means an instructor's own academic credential (Bachelor/Master/Doctorate/...), used by `UserEducation`. The new grade-band model needed a different name — see "Naming Decision" below.
- **`Language`** — already a full model (used for UI/content locale). It satisfies the "TeachingLanguage" requirement in the spec as-is; no `TeachingLanguage` table or `teacher_languages` pivot was created. Wiring per-instructor taught-languages is deferred to a later phase.
- **`Country` / `State`** — reused directly. Subject-by-country availability is a pivot (`subject_country`) against the existing `countries` table; no new country table was added.
- **Subject pricing** — none found; out of scope for this phase (the spec explicitly limits Phase 1 to masters, not pricing).
- **`FaqCategory`, `PostCategory`** — not academic concepts; consulted only as the established UUID-keyed master-data pattern to mirror (see below).

## Naming Decision: `AcademicLevel`, not `EducationLevel`

The spec's Phase 1 list calls this concept "EducationLevel." That name was not reused because `App\Enums\EducationLevel` already exists and means something unrelated and already relied upon (`UserEducation`'s credential type — Bachelor/Master/Doctorate/...). Silently repurposing or colliding with that name would conflate two different concepts under one identifier. The new grade-band model is called `AcademicLevel` instead, with a docblock on the class recording this decision for future readers.

`AcademicLevel::min_grade` / `max_grade` are an **admin-manageable label** over the same raw grade integers (1-12) already used in `TeacherSubject::grade_from/grade_to` and `AssignmentCriteriaData::$grade` — they do not replace that data, they describe it.

## Model Design

All four models follow the existing UUID-keyed master-data convention (`HasUuids`, `protected $keyType = 'string'`, `public $incrementing = false`) established by `FaqCategory`/`PostCategory`, plus `SoftDeletes` so archived/deleted records remain available for historical bookings/reports.

**Status handling** — `AcademicCategory` and `SkillLevel` use a simple `is_active` boolean (matching `FaqCategory`'s convention, since they don't need a distinct "archived" state). `Subject` and `AcademicLevel` use the new `App\Enums\AcademicStatus` backed enum (`Active` / `Inactive` / `Archived`), because the spec explicitly requires archived subjects/levels to remain visible on historical records while being excluded from new assignments — a three-state distinction a boolean can't express.

Every model declares a matching `protected $attributes = [...]` for its DB column defaults (`status => active`, `is_active => true`, `display_order => 0`). This is required because Eloquent's `Model::create()` does not sync DB-applied column defaults back into the in-memory model for attributes omitted from the `create()` array — without this, a freshly created model reports `null` for a column the database actually defaulted correctly.

**Relationships:**
- `Subject belongsTo AcademicCategory` (`academic_category_id`), `AcademicCategory hasMany Subject`.
- `Subject belongsToMany Country` via `subject_country` (no rows = available in every country — see `Subject::isAvailableInCountry()`).
- `AcademicLevel` and `SkillLevel` are standalone (no FK to category), per the spec's Phase 1 scope.

**Scopes** — every model exposes `scopeActive()`. `Subject` and `AcademicLevel` additionally expose `scopeAvailableForAssignment()` (currently identical to `active()`, kept as a distinct name so callers express intent — e.g. "is this a candidate for a new booking" vs. "is this generally active"). Archived/inactive records are excluded from these scopes but remain fully queryable directly (`Subject::where(...)`, `withTrashed()`), satisfying "archived entities stay available for historical records but not new assignments."

## Filament Admin Resources

`app/Filament/Resources/Academic/{AcademicCategoryResource,SubjectResource,AcademicLevelResource,SkillLevelResource}.php`, each with `Pages/{List,Create,Edit}*`, `Schemas/*Form`, `Tables/*Table` — mirroring the existing `PostCategoryResource`/`FaqCategoryResource` structure exactly, including slug auto-generation via the existing `App\Actions\GeneratePageSlugAction` (no new slug-generation logic was written). All four resources register in the existing `Masters` navigation group (alongside `Country`/`Currency`/`Language`/`State`), sort order 5-8.

`SubjectResource`'s form includes a `Select` for `academic_category_id` (relationship) and `status`, plus a multi-select for `countries` (empty = global). `AcademicLevelResource`'s form includes numeric `min_grade`/`max_grade` inputs and a `status` select.

## Policies and Permissions

Four policies (`AcademicCategoryPolicy`, `SubjectPolicy`, `AcademicLevelPolicy`, `SkillLevelPolicy`) mirror `FaqCategoryPolicy`'s Shield-style `Action:Model` gate calls exactly, and are picked up by Laravel's policy auto-discovery convention (no manual `Gate::policy()` registration needed — same as `PostCategoryPolicy`).

`database/seeders/AcademicPermissionSeeder.php` mirrors `LocalizationPermissionSeeder.php`: grants the `manager` role all `MANAGER_ACTIONS` (ViewAny/View/Create/Update/Delete/DeleteAny/Restore/RestoreAny/Replicate/Reorder) for all four modules, and creates (but does not grant to manager) the `SUPER_ONLY_ACTIONS` (ForceDelete/ForceDeleteAny), consistent with `Gate::before()`'s super-admin bypass.

## Seeders

`AcademicCategorySeeder`, `SubjectSeeder`, `AcademicLevelSeeder`, `SkillLevelSeeder` seed sample data (6 categories, 20 subjects across those categories, 6 grade bands, 4 skill levels) using `firstOrCreate()` keyed by `slug` for idempotency. All four are registered in `DatabaseSeeder` after `AcademicPermissionSeeder`.

## What Is Explicitly Not Built Yet

Per the spec's scope boundary, this phase does **not** include:
- Wiring `Subject`/`AcademicLevel` into the booking flow, `TeacherSubject`, or any DTO/validation rule.
- A curriculum, roadmap, or competency engine.
- Subject pricing.
- Per-instructor taught-language or per-instructor subject-expertise pivots.
- Enforcement of `subject_country` availability anywhere in search/booking (the relationship and `isAvailableInCountry()` helper exist; nothing calls them yet).

These are intentionally deferred so this phase stays scoped to "stable academic masters," as instructed.

## Tests

`tests/Feature/Academic/{AcademicCategoryTest,SubjectTest,AcademicLevelTest,SkillLevelTest}.php` cover creation defaults, soft-delete/restore, relationships (`Subject belongsTo AcademicCategory`, `AcademicCategory hasMany Subject`), `scopeActive`/`scopeAvailableForAssignment` exclusion behavior (inactive/archived excluded from scopes but still directly queryable), `AcademicLevel::coversGrade()`, and `Subject::isAvailableInCountry()` (both the "no rows = global" and "restricted to specific countries" cases).

`tests/Feature/Filament/AcademicResourceCrudTest.php` covers Filament list/create/edit for an authorized manager and confirms an unauthorized user gets a 403 on the list page.
