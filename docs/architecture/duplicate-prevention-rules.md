# Duplicate Prevention Rules

## Purpose

This document defines hard rules to prevent parallel concepts, duplicate tables, duplicate services, and duplicate UI systems.

Before adding any model, migration, service, setting, policy, component, or resource, check:

1. `docs/architecture/domain-registry.md`
2. Existing migrations
3. Existing models
4. Existing services/contracts/repositories
5. Existing settings classes
6. Existing Filament resources/pages
8. Existing Livewire and Blade components

If something already exists, enhance it.

## Global Rules

- One Laravel application.
- One user identity model.
- One CMS page/block system.
- One navigation system.
- One booking domain.
- One settings system.
- One permission system.
- One media upload system.
- One audit/activity log pipeline.
- One transactional email log.

## User Identity

Reuse:

- `app/Models/User.php`
- Spatie roles and permissions
- `app/Services/PortalResolver.php`
- `app/Settings/RegistrationSettings.php`
- `app/Services/Auth/*`

Rules:

- Do not create `Student`, `Instructor`, `Tutor`, `Admin`, or `Manager` identity models.
- Do not create separate login tables by role.
- Do not add role helper methods such as `isStudent()`, `isInstructor()`, `isManager()`.
- Only `User::isSuperAdmin()` is allowed as a role helper outside `PortalResolver`.
- Portal selection must call `PortalResolver`.
- Authorization must use policies/permissions, not portal decisions.

## Student Profiles

Reuse:

- `User`
- `UserProfile`
- `UserEducation`
- `UserExperience` where relevant
- `resources/views/layouts/student.blade.php`
- `app/Livewire/Frontend/Student/*`
- `app/View/Composers/AccountPortalComposer.php`

Rules:

- Do not create `student_profiles` unless a future decision proves `user_profiles` cannot support required fields.
- Add missing student profile fields to `user_profiles` only after schema review.
- Student dashboard data must use services or existing Livewire components.
- Do not query bookings/homework/payments directly from Blade.

## Instructor Profiles

Reuse:

- `User`
- `UserProfile`
- `UserExperience`
- `UserEducation`
- `TeacherSubject`
- `TeacherAvailability`
- `TeacherUnavailability`
- `InstructorService`
- `InstructorPolicy`

Rules:

- Public wording may be “Instructor”; internal tables currently use `Teacher*`.
- Do not create `instructors`, `tutors`, `instructor_availability`, or `tutor_subjects` tables in Phase 1.
- Add instructor fields to `user_profiles` or existing teacher-named tables when appropriate.
- Route/controller work should delegate to `InstructorService`.

## Booking

Reuse:

- `app/Booking/*`
- `Booking`, `BookingType`, `BookingGuest`, `BookingActivity`
- `TeacherAvailability`, `TeacherUnavailability`, `TeacherSubject`, `Holiday`
- `BookingServiceInterface`
- `GuestBookingServiceInterface`
- `StudentBookingServiceInterface`
- `AvailabilityServiceInterface`
- `BookingPaymentServiceInterface`
- `BookingWizardService`

Rules:

- Do not create a second booking table.
- Do not create a second slot generator.
- Do not create booking business logic in Livewire/controllers.
- Do not directly query booking models from UI layers for business workflows.
- Booking lifecycle changes should dispatch/use existing booking events.
- Payment providers must be registered through `PaymentProviderRegistry`.

## CMS

Reuse:

- `Page`
- `Post`
- `ContentBlock`
- `PageRenderService`
- `BlockRenderer`
- `ContentBlockService`
- `resources/views/components/blocks/*`
- `app/Forms/Blocks/*`

Rules:

- Do not create new CMS page/block tables.
- Do not hardcode page content that should come from CMS.
- Do not create a separate homepage system outside CMS pages/blocks.
- New block types require a block enum/form/rendering/component path.
- SEO fields should reuse existing page/post SEO columns and `SeoSettings`.

## Navigation

Reuse:

- `NavigationMenu`
- `NavigationItem`
- `NavigationManager`
- `NavigationRepository`
- `NavigationRenderer`
- `NavigationCacheManager`
- `LinkTypeRegistry`
- `UrlResolver`

Rules:

- Do not create separate menu config for public navigation.
- Do not hardcode navigational trees in layouts.
- New link types must be link drivers.
- Navigation visibility should use existing role/permission/publish-window features.
- Cache invalidation must go through `NavigationCacheInterface`.

## Settings

Reuse:

- `app/Settings/*`
- `database/settings/*`
- `app/Filament/Pages/Settings/*`
- `app/Filament/Pages/Security/*`

Rules:

- Do not create another settings table.
- Do not add configurable business behavior as hardcoded constants unless truly invariant.
- Settings migrations belong in `database/settings`, not `database/migrations`.
- Every settings page save must authorize through Gate/policy.
- Sensitive settings should be encrypted or stored as environment secrets where appropriate.

## Permissions

Reuse:

- Spatie Permission tables
- Filament Shield
- `config/filament-shield.php`
- `app/Policies/*`
- `app/Providers/AppServiceProvider.php`

Rules:

- Do not create custom ACL tables.
- Do not check role IDs.
- Do not duplicate `Gate::before()` super-admin behavior in policies.
- Resource permissions use Shield style such as `ViewAny:Page`.
- Operational permissions may use dotted names such as `security.session.update`.
- Policies should call `hasPermissionTo()` and catch missing permissions where possible.

## Media Uploads

Reuse:

- Spatie Media Library
- `media` table
- Existing model media collections

Rules:

- Do not add avatar, cover, document, certificate, gallery, or logo file path columns.
- Add new media collections to existing models.
- Validate MIME types and single-file behavior in media collections.
- Use profile/media services/actions for upload behavior.

## Activity Logs

Reuse:

- `AuditTrailService`
- `Activity`
- Spatie Activitylog
- `ActivityLogResource`
- `NotificationMapper`
- `AdminNotificationService`

Rules:

- New business code must not call `activity()` directly.
- Services/actions/listeners should call `AuditTrailService`.
- Admin notifications originate from the Activity Log pipeline.
- Do not create a separate audit table for normal domain activity.
- Do not send admin notifications directly from services.

## Transactional Email

Reuse:

- Laravel notifications
- `TransactionalNotificationService`
- `EmailLog`
- `EmailLogService`
- Resend mailer config

Rules:

- Do not call `Mail::send()` or `notifyNow()` in new business code.
- Notifications should be queued.
- Provider status belongs in `email_logs`.
- Sender addresses should come from `MailSettings`.

## Countries and Currencies

Reuse:

- `Country`
- `State`
- `countries`
- `states`
- payment settings where currency behavior is configuration-only

Rules:

- Do not create duplicate country/state tables.
- Do not add free-text country/state fields when FK-backed fields exist.
- Add currency support only after checking payment settings and booking/payment requirements.
- If a currency master becomes necessary, document why settings are insufficient before adding a table.

## Academic Masters

Admin-managed academic master tables: `AcademicCategory`, `Subject`, `AcademicLevel`, `SkillLevel` — named to avoid colliding with `App\Enums\EducationLevel`'s existing meaning (an instructor's own credential type, not a grade band; the grade-band model was named `AcademicLevel` instead). See `docs/archive/reports/academic-master-foundation.md` (historical — design rationale and naming decisions only, not current instruction) for the full background.

Existing related concepts (unchanged, not duplicated):

- `EducationLevel` enum — instructor's own credential type, unrelated to `AcademicLevel`
- `EmploymentType` enum
- `TeacherSubject` — has a nullable `subject_id` FK to `Subject` (added by the reconciliation described in `docs/architecture/subject-teacher-subject-reconciliation.md`) alongside its original free-text `subject` field; `InstructorService` prefers the `subject_id` relation when set, falling back to free text
- `BookingType`
- `FaqCategory`
- `PostCategory`
- `Country` and `State` — reused directly via the `subject_country` pivot; no new country/state tables added
- `Language` — reused directly as the "teaching language" master; no separate `TeachingLanguage` table added

Rules:

- Do not create a second subject/category/level/skill-level table — enhance the existing models instead.
- Grades currently appear in booking flows via `TeacherSubject::grade_from/grade_to`; `AcademicLevel::min_grade/max_grade` is an admin-manageable label over the same numbers, not a replacement.
- Course/catalog concepts should first check CMS blocks and booking types before adding new tables.

## Naming Consistency Rules

- Public UI may say "Instructor".
- Existing DB/domain internals may still say `Teacher*`.
- Do not mix new `Tutor*` names in without a migration plan that renames the existing teacher concepts.
- Prefer "Instructor" in public-facing routes/views/docs.
- Prefer existing class/table names in code until a formal rename is planned.

## Migration Approval Checklist

Before creating a migration, answer yes to all:

1. Does no existing table represent this concept?
2. Is this not a settings value?
3. Is this not a media collection?
4. Is this not a role/permission distinction?
5. Is this not already represented by an enum?
6. Is this not better added as a nullable field to an existing table?
7. Are tests planned for the schema behavior?

If any answer is no, do not create the migration.

