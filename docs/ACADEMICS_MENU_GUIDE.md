# Academics Menu Guide

## Purpose of this guide

The **Academics** area contains the shared educational structure used by booking, instructor discovery, learning plans, homework, packages, reporting, and future recommendations. It looks large because it includes both setup screens and operational records. The client does **not** need to maintain every menu every day.

This guide describes the current application behavior and the intent of the SRS. Where the SRS describes a broader future capability, this guide calls out what the current product does today.

## The three types of menu

| Type            | Meaning                                                                                  | Typical owner                             |
| --------------- | ---------------------------------------------------------------------------------------- | ----------------------------------------- |
| Master data     | Reusable definitions such as subjects, levels, curricula, and education systems          | Admin, usually during setup or expansion  |
| Working records | Goals, plans, instructor coverage, and package proposals that change as teaching happens | Student, instructor, and authorized admin |
| System records  | Purchases and lesson balances created by approved workflows                              | System; admin monitors them               |

## End-to-end academic hierarchy

The main structure is:

```text
Academic Category
└── Subject
    └── Subject Topic (optional parent topic → child topic)

Academic Level (broad internal band)
└── Curriculum
    └── Curriculum Version
        └── Modules
            └── mapped Subject Topics

Country
└── Education System
    ├── student-facing Levels (Class 10 / Grade 10 / Year 10)
    ├── broad Academic Level mappings
    └── applicable Curricula
```

These concepts have distinct jobs:

- **Academic Level** is a broad, reusable band such as Primary School, Middle School, or Higher Secondary. It supports curriculum ownership, reporting, goals, and internal grade matching.
- An **Education System level** is the exact choice a student sees inside an education system, such as CBSE Class 10 or US Grade 10. It is configured inside the Education System screen, not as a separate sidebar menu.
- **Skill Level** is optional proficiency vocabulary such as Beginner, Intermediate, or Advanced. It is not a replacement for a school grade.
- A **Subject Topic** is a reusable topic such as Algebra. A curriculum version may organize that topic into modules without creating a duplicate topic master.

### How this reaches users

1. The admin defines active subjects, topics, broad levels, curricula, and education systems.
2. Countries, levels, and curricula are mapped to each applicable education system.
3. Instructors receive subject assignments and, when needed, approved topic coverage and curriculum eligibility.
4. Students see only active and applicable choices in discovery and booking.
5. A student creates a learning goal; an instructor can then maintain a learning plan with milestones, reviews, and progress.
6. Package offers can be proposed for a specific student/instructor/academic context, purchased, and consumed by eligible lessons.

## Recommended initial setup order

Complete only the parts your launch offering needs.

1. **Academic Categories** — create broad business groupings.
2. **Subjects** — add the subjects offered and configure country availability where applicable.
3. **Subject Topics** — add topics only when topic-level discovery or instructor matching is needed.
4. **Academic Levels** — create broad internal bands and normalized grade ranges.
5. **Skill Levels** — add these only for proficiency-based subjects.
6. **Curricula** — create a curriculum for each required subject and broad academic level.
7. **Curriculum Versions** — prepare modules and topics, then publish the approved version.
8. **Education Systems** — add systems, map countries, broad levels, exact student-facing levels, and curricula.
9. **Instructor Topic Coverage** — approve instructors for specific topics where topic-level matching is used.
10. **Learning Goals and Learning Plans** — use after students begin their learning journey.
11. **Package Offers** — define reusable lesson quantities only if packages are enabled.
12. **Instructor Package Proposals** — review proposals submitted by instructors.
13. **Student Package Purchases and Entitlements** — monitor system-created payment and lesson-balance records; do not create them manually.

Before activating a new option, check the complete path. For example, publishing a curriculum alone does not make it selectable for a student: its subject and level must be active, and the curriculum must be mapped to an education system available in the student's country.

## Menu reference

### 1. Academic Categories

- **Purpose:** Groups related subjects for organization, reporting, and future discovery. Examples: School Academics, Languages, Programming, Music.
- **Managed by:** Admin.
- **Use it when:** Launching a new teaching area or reorganizing the subject catalogue.
- **Requires:** Nothing; this is the top of the subject hierarchy.
- **Affects:** Subjects assigned to the category and category-based administration/reporting.
- **Instructor/student impact:** Users normally interact with subjects rather than the category itself. Active categories provide the structure behind those subjects.

### 2. Subjects

- **Purpose:** Defines the disciplines students can learn, such as Mathematics, English, Physics, or Python Programming.
- **Managed by:** Admin.
- **Use it when:** Adding or retiring a teachable subject, changing its description, or controlling regional availability.
- **Requires:** An Academic Category. Countries must exist before regional mappings can be added.
- **Affects:** Instructor subject assignments, marketplace filters, booking, pricing context, goals, curricula, plans, homework, packages, and reporting.
- **Instructor/student impact:** Active, available subjects can appear in instructor profiles, discovery, booking, learning goals, and related workflows. Archiving a subject prevents new use but preserves historical records.

### 3. Subject Topics

- **Purpose:** Breaks a subject into teachable areas. One optional nesting level is supported, for example Mathematics → Algebra → Linear Equations.
- **Managed by:** Admin.
- **Use it when:** Students need topic-level instructor discovery or the business needs to record that an instructor teaches only part of a subject.
- **Requires:** An active Subject; a child topic also requires a parent topic in the same subject.
- **Affects:** Instructor Topic Coverage, marketplace topic filtering, curriculum module-topic mapping, and topic snapshots when a topic is supplied to booking.
- **Instructor/student impact:** Only topics with active, admin-approved instructor coverage are useful for topic-based discovery. Current service validation supports topic booking, while some booking UI paths may still present subject-level choices.

### 4. Academic Levels

- **Purpose:** Defines broad internal education bands such as Primary School, Middle School, High School, Undergraduate, or Adult Learning.
- **Managed by:** Admin.
- **Use it when:** A new broad stage is needed for curriculum, goals, reporting, or grade-range matching.
- **Requires:** Country is optional; a null country means the level can be global. Internal minimum/maximum grades should reflect the matching range.
- **Affects:** Curricula, student learning goals/plans, instructor topic coverage, education-system mappings, and numeric grade matching.
- **Instructor/student impact:** Students use these levels in learning goals and legacy flows, but in country-aware booking they select an exact Education System level such as “Class 10,” not the broad band itself.

### 5. Skill Levels

- **Purpose:** Provides optional proficiency labels such as Beginner, Intermediate, Advanced, or Fluent.
- **Managed by:** Admin; instructors select configured values during profile/onboarding workflows.
- **Use it when:** Ability is better described by proficiency than by a school year, especially languages, programming, music, or professional learning.
- **Requires:** No category or subject dependency in the current master-data model.
- **Affects:** Instructor profile/onboarding proficiency data.
- **Instructor/student impact:** Helps describe instructor capability. It is currently not a primary booking or marketplace-matching key, so do not create a large list unless the profile workflow needs it.

### 6. Instructor Topic Coverage

- **Purpose:** Records that a specific instructor can teach a specific topic, optionally limited to an Academic Level.
- **Managed by:** Admin in the current implementation; instructor self-service topic assignment is not currently provided.
- **Use it when:** Topic-specific matching is required, for example an instructor teaches Algebra and Geometry but not Calculus.
- **Requires:** Instructor, Subject Topic, its parent Subject, and optionally an Academic Level. Coverage must be active and admin-approved to count as bookable.
- **Affects:** Marketplace topic filters, instructor profile topic labels, and topic-level booking eligibility.
- **Instructor/student impact:** Approved coverage makes that topic visible for the instructor. It does not set prices and does not replace the instructor's normal subject assignment.

### 7. Curricula

- **Purpose:** Defines the stable identity of a structured course for one Subject and one broad Academic Level, such as “CBSE Mathematics — Secondary.”
- **Managed by:** Admin.
- **Use it when:** Teaching needs an organized sequence rather than ad hoc lessons.
- **Requires:** Active Subject and Academic Level.
- **Affects:** Curriculum versions, modules, education-system mappings, academic booking context, instructor curriculum eligibility, and future learning-plan integration.
- **Instructor/student impact:** A curriculum becomes selectable in country-aware booking only when it is active and mapped to the selected Education System. The curriculum identity remains stable while versions evolve.

### 8. Curriculum Versions

- **Purpose:** Preserves revisions of curriculum content without rewriting historical learning records.
- **Managed by:** Admin through a Curriculum's Versions area. The standalone menu is mainly for browsing and lifecycle management; versions are not created directly there.
- **Use it when:** Modules, sequence, or content changes and a new approved edition is needed.
- **Requires:** A Curriculum. Modules belong to a version; module topics map existing Subject Topics.
- **Affects:** Version status and structured curriculum content. Lifecycle moves forward from Draft to Published and later archival/retirement states.
- **Instructor/student impact:** Draft content is for preparation. Published content is the usable edition; historical records can remain linked to the edition that applied at the time.

### 9. Education Systems

- **Purpose:** Configures country-specific academic systems such as CBSE, ICSE, IB, US K–12, GCSE, or AP without hardcoding them in the application.
- **Managed by:** Admin.
- **Use it when:** A country, board, or programme needs its own terminology and valid academic choices.
- **Requires:** Countries, Academic Levels, exact student-facing level rows, and Curricula to map.
- **Affects:** Country-aware booking and academic validation. Each system contains four important mappings: Countries, broad Academic Levels, exact Levels, and Curricula.
- **Instructor/student impact:** Students see terms configured for that system—Class, Grade, Year, or Level—and only valid mapped choices. Instructor curriculum eligibility is recorded against the education-system and curriculum combination.

### 10. Learning Goals

- **Purpose:** Captures what a student wants to achieve, such as improving algebra, preparing for an exam, or learning conversational English.
- **Managed by:** Primarily the student in the frontend portal; authorized admins can create and manage goals for support/oversight.
- **Use it when:** A student begins a new academic, professional, or personal objective.
- **Requires:** Student and active Subject; Academic Level and target date are optional.
- **Affects:** Goal status, priority, target date, and the Learning Plan created from the goal.
- **Instructor/student impact:** Students can create, edit, complete, and archive their own goals. A goal provides the starting context for an instructor-led plan.

### 11. Learning Plans

- **Purpose:** Turns a goal into a managed, long-term learning journey with a primary instructor, milestones, reviews, assessments, current focus, and progress.
- **Managed by:** Instructors manage plans assigned to them; students view their plans; authorized admins oversee and use controlled lifecycle actions.
- **Use it when:** A student has selected an instructor and needs structured progress beyond individual bookings.
- **Requires:** Student Learning Goal, Subject, student, and normally an eligible primary instructor. Academic Level is supported; current plan records are not yet fully linked to every curriculum/outcome concept described by the broader SRS.
- **Affects:** Milestones, assessments, adjustments, progress reviews, plan status, student dashboard progress, instructor teaching queue, and analytics.
- **Instructor/student impact:** Students see active plans and progress. Instructors maintain academic content and reviews. Completion or archival preserves the history and prevents inappropriate further updates.

### 12. Package Offers

- **Purpose:** Defines reusable lesson-quantity templates that instructors may propose, for example 10 paid lessons + 1 bonus lesson.
- **Managed by:** Admin.
- **Use it when:** The business wants standardized package sizes or validity periods.
- **Requires:** Package and payment features must be enabled. No student or instructor is attached at this stage.
- **Affects:** Paid lessons, bonus lessons, total lesson quantity, post-activation validity, and which templates instructors can choose.
- **Instructor/student impact:** Active offers appear to permitted instructors. The offer does **not** store a fixed selling price; price is calculated from the student's applicable lesson price when the instructor creates a proposal.

### 13. Instructor Package Proposals

- **Purpose:** Reviews package offers created by instructors for specific students and academic contexts.
- **Managed by:** Instructor creates/submits in the frontend; authorized admin approves or rejects. Admin may make an audited final-price override with a required reason.
- **Use it when:** A submitted proposal appears in the review queue.
- **Requires:** Active Package Offer, eligible instructor and student, valid academic context, and a resolvable student lesson price.
- **Affects:** Proposal status, calculated/final price, approval history, and what the student can accept.
- **Instructor/student impact:** Instructors track their proposals. After approval, the student sees the package and may accept it. Admin does not create proposals from this menu.

### 14. Student Package Entitlements

- **Purpose:** Shows the student's active or historical package lesson balance—how many lessons were granted, reserved, consumed, or remain.
- **Managed by:** System. Admin has read-only operational visibility.
- **Use it when:** Investigating balance, expiry, reservation, or consumption questions.
- **Requires:** An accepted package purchase whose payment has been successfully verified and activated.
- **Affects:** Whether an eligible booking can use package funding and the remaining lesson balance.
- **Instructor/student impact:** Students can use qualifying package lessons during booking; instructors can teach funded lessons. No one should manually create, edit, or delete entitlement records from admin.

### 15. Student Package Purchases

- **Purpose:** Shows the payment journey for a package the student accepted, including accepted-but-unpaid and settled purchases.
- **Managed by:** System. Admin has read-only visibility.
- **Use it when:** Investigating checkout/payment progress or explaining why an entitlement has not yet been activated.
- **Requires:** An approved proposal accepted by the student.
- **Affects:** Payment status and, after verified settlement, creation/activation of the corresponding entitlement.
- **Instructor/student impact:** Students complete checkout; verified payment processing advances the purchase. Admin cannot hand-edit payment records or manufacture successful purchases.

## Practical setup examples

### India: CBSE Mathematics

1. Create **School Academics** as the Academic Category.
2. Create **Mathematics** as a Subject and make it available in India.
3. Add Subject Topics such as **Algebra**, **Geometry**, and **Trigonometry**.
4. Create broad Academic Levels such as **Middle School** and **Secondary School** with suitable internal grade ranges.
5. Create the **CBSE** Education System with `Class/Classes` terminology and map it to India.
6. Inside CBSE, add exact Levels such as Class 6, Class 7, and Class 8 mapped to Middle School, and Class 9 and Class 10 mapped to Secondary School. Give numeric levels a normalized grade for instructor matching.
7. Create **CBSE Mathematics — Secondary** as the Curriculum, add a draft version, modules, and mapped topics, then publish it.
8. Map the curriculum to CBSE and approve appropriate instructors for the required topics/curriculum context.

The student then selects CBSE → Class 10 → Mathematics → the mapped curriculum in the country-aware booking flow.

### United States: Grade 10 Algebra

1. Reuse **School Academics** and **Mathematics** where appropriate.
2. Create a broad **High School** Academic Level.
3. Create **US K–12** with `Grade/Grades` terminology and map it to the United States.
4. Add exact Grade 9–12 Levels mapped to High School.
5. Create a suitable US Mathematics curriculum and a published version containing an Algebra module and topics such as Linear Equations.
6. Map the curriculum to US K–12 and approve instructor topic coverage for Algebra at High School level.

The same broad academic framework is reused, while the student sees US terminology and US-valid combinations.

### Learning goal to learning plan

1. A student creates **“Improve algebra confidence before final exams”**, linked to Mathematics and the appropriate Academic Level.
2. After choosing an instructor, a plan such as **“Grade 10 Algebra Exam Preparation”** is created from that goal.
3. The instructor records the initial assessment, adds milestones such as Linear Equations and Quadratics, and sets the current focus.
4. Reviews and milestone completion update the student's visible progress.
5. When the objective is achieved, the plan is completed and later archived without deleting its history.

### Instructor package lifecycle

1. Admin creates an active offer: **10 paid lessons + 1 bonus lesson, valid for 90 days after activation**.
2. An instructor chooses that offer for an eligible student and academic context.
3. The platform calculates the price from the student's applicable lesson rate; the instructor submits the proposal.
4. Admin approves it or rejects it. Any price override requires a reason and is audited.
5. The student accepts and pays.
6. Verified payment activates an entitlement with 11 lessons. Eligible bookings reserve and consume that balance through the system.

## What the Client Actually Needs to Manage Regularly

Most clients should focus on this shorter list:

- **Subjects:** keep the live catalogue and country availability accurate.
- **Instructor Topic Coverage:** approve new or changed coverage when topic-level matching is used.
- **Learning Plans:** monitor plans needing assignment, review, assessment, or support; instructors perform the academic updates.
- **Package Offers:** adjust active offer templates only when commercial packaging changes.
- **Instructor Package Proposals:** review submitted proposals promptly.
- **Student Package Purchases/Entitlements:** monitor only when resolving payment or lesson-balance issues.

Academic Categories, broad Levels, Curricula, Curriculum Versions, and Education Systems are normally setup/expansion work. They should not change every week.

## Menus That Are Mostly System/Advanced Use

- **Curriculum Versions:** advanced content governance. Create versions from the parent Curriculum and publish only after review.
- **Skill Levels:** optional profile vocabulary; maintain only if the business uses it.
- **Student Package Entitlements:** system-created, read-only balance records.
- **Student Package Purchases:** system-created, read-only payment-progress records.
- **Education System mappings:** powerful master configuration that can remove choices from booking if changed incorrectly.
- **Academic Level internal grade ranges:** matching metadata, not the exact label students see in country-aware booking.

## Safe operating rules

- Prefer **inactive/archive** over deletion so bookings, plans, and reports retain history.
- Do not create duplicate Subjects or Topics for different countries unless they are genuinely different academic concepts; use country and education-system mappings.
- Do not use Skill Levels as school grades.
- Do not create exact Class/Grade/Year choices as broad Academic Levels; add them inside the Education System's **Levels** area.
- Publish curriculum versions deliberately. Drafts are working copies; historical versions must remain available.
- Topic coverage and instructor curriculum eligibility are approvals, not pricing controls.
- Do not manually manipulate package purchases, payments, entitlements, reservations, or consumed balances.
- Test a complete student booking path after changing country, education-system, level, curriculum, or instructor-eligibility mappings.

## Current-scope notes

The SRS describes the intended shared academic framework, including deeper learning outcomes, competencies, and full curriculum integration across plans and homework. The current application implements the core taxonomy, education-system mappings, versioned curricula/modules/topics, learning goals/plans, and package workflow. Some deeper SRS concepts remain future or partially integrated. Client decisions should therefore follow the screens and lifecycle actions available today rather than assuming every future SRS relationship is already editable.

For technical detail, see `docs/SRS.md`, `docs/architecture/domain-registry.md`, `docs/architecture/phase-12.5-academic-taxonomy-subject-topics.md`, `docs/architecture/phase-3.1-education-system-levels.md`, and `docs/package-academic-context-and-booking-funding.md`.
