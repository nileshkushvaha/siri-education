# Phase 12.5 — Academic Taxonomy, Subject Topics & Instructor Coverage

Topic-level teaching: some instructors teach only parts of a subject
(Mathematics → Algebra + Geometry, but not Calculus). This phase adds a
topic taxonomy, explicit instructor topic coverage, optional topic
selection in booking, and topic filtering in the marketplace — without
touching pricing, payment, meeting, or wallet logic.

## Current-state audit (what existed before this phase)

- `academic_categories` → `subjects` (UUID master data, status,
  `subject_country` availability pivot, soft deletes, activity-logged).
- `academic_levels`: flat, admin-managed named grade bands with
  `min_grade`/`max_grade` bridging to the universal 1–12 grade ints —
  **not** country-specific.
- `skill_levels`: standalone; used only in instructor onboarding, not
  in booking or search.
- `teacher_subjects`: the booking-flow source of truth — free-text
  `subject` slug + optional `subject_id` reconciliation link + a grade
  range. All matching (`TeacherCandidateRepository`) queries the slug +
  grade int.
- Booking receives `subject` (slug) + `grade` (int) and snapshots them
  into `bookings.meta`; `student_lesson_prices` keys on
  booking_type/subject/level/country/currency/duration (+ optional
  instructor override).
- Marketplace (`InstructorService`) filters by subject/level/language/
  country/timezone; instructor profile lists subjects.
- **Gap**: nothing modeled "part of a subject", so an instructor either
  taught all of Mathematics or none of it.

## Target relationship map (implemented)

```
AcademicCategory
 └─ Subject ──────────────┐
     └─ SubjectTopic       │ (subject_topics: parent_id allows one
         └─ SubjectTopic   │  nesting level — Algebra → Linear Equations)
                           │
Instructor ─ TeacherSubject┘        (whole subject + grade range — unchanged baseline)
Instructor ─ InstructorSubjectTopic (explicit topic coverage, optional level scope,
                                     active + admin-approved required to count)
AcademicLevel (min/max grade bridge) ── optional country_id (education system)
```

## UK/US-first levels; India later

`academic_levels` gained a nullable `country_id`: UK rows (Year 1–13,
GCSE, A-Level) and US rows (Grade 1–12, College, Adult) can coexist,
each mapped through `min_grade`/`max_grade` onto the universal 1–12
ints the whole matching/pricing engine already uses; null = global.
`AcademicLevel::forCountry()` scopes lookups. A full
`education_systems` module (boards/exams, e.g. India CBSE/ICSE/State)
was **deliberately deferred** — it would ripple through matching and
pricing for no current requirement; when India needs boards, add an
`education_systems` table and hang `academic_levels.education_system_id`
off it without disturbing the grade-int bridge. India's Class 1–12 fits
the current structure today as country-scoped rows.

## Subject topics

`subject_topics`: uuid, `subject_id`, nullable `parent_id` (one nesting
level, enforced in the admin form — not a curriculum engine), name,
per-subject-unique slug, description, status
(active/inactive/archived), display_order, audit columns, soft deletes,
activity-logged. Admin resource ("Subject Topics", Academic group) with
parent-topic selection scoped to the chosen subject and bulk
activate/deactivate. Subjects list shows a topics count.

## Instructor topic coverage

`instructor_subject_topics`: teacher + subject + topic + optional
`academic_level_id` (null = all levels), proficiency label, is_primary,
is_active, `approved_at`/`approved_by`. **Bookable/marketplace-visible
only when active AND admin-approved** (`scopeBookable`) — the
enterprise rule: explicit, admin-controlled coverage for paid matching.
Whole-subject `teacher_subjects` rows never imply topic coverage.
Coverage grants no pricing access — student prices remain a separate
admin-only module (tested). Admin resource ("Instructor Topic
Coverage", Academic group) with approve bulk action; instructor
self-service assignment is a documented gap (admin-managed this phase).

## Booking impact

`StudentBookingData` gained optional `topic` (slug). In
`StudentBookingService`:

- topic selected → must resolve to an **active** topic of the selected
  subject, and the chosen teacher must pass
  `TeacherCandidateRepository::teachesTopic()` (bookable coverage whose
  level, when set, covers the booking's grade) — otherwise
  `BookingException`.
- no topic → the pre-existing subject+grade rules apply unchanged.
- Snapshot: `meta.topic` (slug) + `meta.topic_id` — no bookings-table
  migration, existing bookings untouched. Lesson lifecycle (Phase 13)
  can read the snapshot for objectives/progress.
- The booking wizard/API UI for picking a topic is a **documented gap**
  — the service/validation layer is complete; UI wiring follows with
  the lesson-lifecycle phase.

## Marketplace impact

- Directory: `?topic=` filter (id or slug) showing only instructors
  with bookable coverage; filter dropdown lists active topics that have
  at least one bookable covering instructor ("Subject — Topic" labels).
- Profile: "Topics taught" chips (active + approved coverage only; safe
  keys name/slug/subject — never pricing/coverage internals).
- Instructors still never see student prices, margins, or payment data.

## Pricing impact — none, by design

`student_lesson_prices` is untouched; topic affects **matching only**.
A topic booking resolves its price exactly as before (tested, including
the instructor-override path via existing suites). If the business
later wants topic-premium pricing, add an optional nullable
`subject_topic_id` to the matrix with "null = subject-wide price"
resolution order — documented here as future work, not built.

## Admin menu (Academic group)

Academic Categories · Subjects (+topics count) · **Subject Topics** ·
Academic Levels (+education-system country) · Skill Levels ·
**Instructor Topic Coverage**. Permissions follow the existing
Shield-style `AcademicPermissionSeeder` (two new modules:
`SubjectTopic`, `InstructorSubjectTopic`; force-delete stays
super-admin-only). Run the seeder on deploy.

## Explicitly not changed

Lesson lifecycle, attendance, homework, reviews, payout, wallet,
payment logic, meeting providers, curriculum content, and no duplicate
subject/academic tables — `subject_topics` and
`instructor_subject_topics` are the only new tables.
