# Domain Registry

## Purpose

This registry documents existing application domains and their current source-of-truth files. It prevents duplicate modules by making clear which models, migrations, services, policies, Filament resources, and tests should be reused or enhanced.

When adding a feature, find the matching domain here first. If a domain already exists, enhance it. Do not create a parallel domain.

## Auth and Security

| Category | Existing assets |
|---|---|
| Purpose | Login, registration, password reset, email verification, account lock/unlock, password lifecycle, session/security settings. |
| Paths | `app/Services/Auth`, `app/Actions/Auth`, `app/Events/Auth`, `app/Listeners/Auth`, `app/Http/Controllers/Auth`, `app/Livewire/Frontend/Auth`, `app/Filament/Pages/Security`. |
| Models | `app/Models/User.php`, `LoginHistory.php`, `UserPasswordHistory.php`, `UserSession.php`. |
| Migrations | `0001_01_01_000000_create_users_table.php`, `2026_06_26_195727_create_login_histories_table.php`, `2026_06_26_205847_create_user_sessions_table.php`, `2026_06_28_220000_create_user_password_histories_table.php`, user/profile backfill migrations. |
| Services/actions | `LoginService`, `RegistrationService`, `PasswordResetService`, `AccountProtectionService`, `LoginSecurityService`, `PasswordLifecycleService`, `PasswordHistoryService`, `AttemptLoginAction`, `RegisterUserAction`. |
| Filament | `app/Filament/Pages/Auth/Login.php`, `app/Filament/Pages/Security/*`. |
| Policies | `app/Policies/Security/SecurityPolicy.php`, `UserPolicy.php`, profile/session-related policies. |
| Tests | `tests/Feature/Auth/*`, `tests/Feature/Security/*`. |
| Reuse notes | Use existing services/actions/settings for auth changes. Use `PortalResolver` for redirect decisions. |
| Do not duplicate | Do not create new auth tables, custom role helpers, or inline portal routing logic. |

## User, Profile, Student, and Instructor Identity

| Category | Existing assets |
|---|---|
| Purpose | Identity, profile, student dashboard identity, public profile, instructor status/profile details. |
| Paths | `app/Models/User.php`, `UserProfile.php`, `UserExperience.php`, `UserEducation.php`, `app/Services/Profile`, `app/Services/Instructor`, `app/Actions/Profile`, `app/Http/Controllers/Profile`, `app/Http/Controllers/Instructor`. |
| Models | `User`, `UserProfile`, `UserExperience`, `UserEducation`. |
| Migrations | `users`, `user_profiles`, `user_experiences`, `user_educations`, `add_slug_to_users`, `add_instructor_columns_to_user_profiles`, profile backfills. |
| Services/actions | `ProfileService`, `ProfileCompletionService`, `UserExperienceService`, `UserEducationService`, `SessionService`, `InstructorService`, `UpdateProfileAction`, `UploadAvatarAction`. |
| Filament | `app/Filament/Resources/Users`, `app/Filament/Pages/AdminProfile.php`. |
| Policies | `UserPolicy`, `ProfilePolicy`, `InstructorPolicy`, `UserExperiencePolicy`, `UserEducationPolicy`. |
| Tests | `tests/Feature/Profile/*`, `tests/Feature/Instructor/*`, `tests/Feature/Filament/UserResourceProfileTest.php`, `InstructorTabTest.php`. |
| Reuse notes | Add profile fields to `user_profiles` only if missing. Use roles for student/instructor distinction. |
| Do not duplicate | Do not create `students`, `instructors`, or `tutors` identity tables in Phase 1. |

### Three separate status enums

Identity has three independent status concepts — do not conflate them:

| Enum | Column | Values | Meaning |
|---|---|---|---|
| `User::$status` (constants) | `users.status` | `active`, `pending_verification`, `inactive`, `blocked`, `suspended` | Account-level access status. |
| `StudentStatus` | `user_profiles.student_status` (nullable) | `registered`, `active`, `suspended`, `archived` | Student-side lifecycle. Null = never held the `student` role. |
| `InstructorStatus` | `user_profiles.instructor_status` (nullable) | `draft`, `submitted`, `under_review`, `documents_pending`, `interview_required`, `approved`, `active`, `vacation`, `suspended`, `archived`, `rejected` | Instructor application/professional lifecycle. Null = never applied to teach. |

`InstructorStatus::bookable()` returns `[Approved, Active]` — this is the single source of truth for booking/public-listing/public-profile eligibility. Never hardcode this list elsewhere.

## CMS Pages and Blocks

| Category | Existing assets |
|---|---|
| Purpose | CMS pages, reusable dynamic content blocks, SEO metadata, page lifecycle, rendering. |
| Paths | `app/Models/Page.php`, `app/Content`, `app/Services/PageService.php`, `PageRenderService.php`, `BlockRenderer.php`, `BlockContentHydrator.php`, `BlockContentConverter.php`, `resources/views/components/blocks`. |
| Models | `Page`, `app/Content/Models/ContentBlock.php`. |
| Migrations | `create_pages_table`, `create_content_blocks_table`. |
| Services/actions | `PageService`, `PageRenderService`, `ContentBlockService`, `BlockService`, `BlockRenderer`, `ValidateBlockContentAction`, `GeneratePageSlugAction`. |
| Filament | `app/Filament/Resources/Pages`, `app/Filament/Resources/PageBlocks`. |
| Policies | `PagePolicy`, `ContentBlockPolicy`. |
| Tests | `tests/Feature/Page*`, `tests/Feature/RichContentRenderingTest.php`, `tests/Feature/Frontend/RenderingPipelineTest.php`, `tests/Unit/Services/Block*`. |
| Reuse notes | New public page content should be CMS-driven through pages/blocks. |
| Do not duplicate | Do not build a second page model, hardcoded page renderer, or separate CMS block table. |

## Blog and Taxonomy

| Category | Existing assets |
|---|---|
| Purpose | Blog posts, categories, tags, related posts, reading time, SEO. |
| Paths | `app/Models/Post.php`, `PostCategory.php`, `Tag.php`, `app/Services/PostService.php`, blog controllers/views. |
| Models | `Post`, `PostCategory`, `Tag`. |
| Migrations | `create_posts_table`, `create_post_categories_table`, `create_tags_table`, pivot and related-post migrations. |
| Services/actions | `PostService`, `GeneratePageSlugAction`. |
| Filament | `app/Filament/Resources/Posts`, `PostCategories`, `Tags`. |
| Policies | `PostPolicy`, `PostCategoryPolicy`, `TagPolicy`. |
| Tests | `tests/Feature/Post*`, `PostCategoryModuleTest.php`, `PostTagModuleTest.php`, `PostLifecycleTest.php`. |
| Reuse notes | Use posts/categories/tags for article-like content unless a different lifecycle is required. |
| Do not duplicate | Do not add `articles`, `news`, or `blog_posts` tables without a formal decision. |

## Navigation

| Category | Existing assets |
|---|---|
| Purpose | Header/footer/mobile/sidebar navigation, tree structure, link resolution, role/permission visibility. |
| Paths | `app/Navigation`, `app/Models/NavigationMenu.php`, `NavigationItem.php`, `resources/views/components/navigation`, `resources/views/livewire/frontend/layout/partials`. |
| Models | `NavigationMenu`, `NavigationItem`. |
| Migrations | `create_navigations_table`, `create_navigation_items_table`, role/permission pivot migrations. |
| Services | `NavigationManager`, `NavigationRepository`, `NavigationRenderer`, `NavigationCacheManager`, `NavigationItemService`, `PermissionEvaluator`, `UrlResolver`, link drivers. |
| Filament | `app/Filament/Resources/Navigation`, `NavigationBuilderWidget`, `app/Livewire/Navigation/MenuBuilder.php`. |
| Policies | `NavigationMenuPolicy`. |
| Tests | `tests/Feature/Navigation/*`, `tests/Unit/Navigation/*`. |
| Reuse notes | Add link types through drivers and `LinkTypeRegistry`. |
| Do not duplicate | Do not create static menu config or separate menu tables. |

## Booking

| Category | Existing assets |
|---|---|
| Purpose | Booking lifecycle, availability, guest/student bookings, teacher assignment, booking types, booking reports. |
| Paths | `app/Booking`, `app/Models/Booking*.php`, `TeacherAvailability.php`, `TeacherUnavailability.php`, `TeacherSubject.php`, `resources/views/booking`, `app/Livewire/Frontend/Booking`. |
| Models | `Booking`, `BookingType`, `BookingGuest`, `BookingActivity`, `TeacherAvailability`, `TeacherUnavailability`, `TeacherSubject`, `Holiday`. |
| Migrations | Booking type, booking, guests, teacher availability/unavailability, subjects, holidays, activities, reservation, analytics, token hashing migrations. |
| Services/actions | `BookingService`, `GuestBookingService`, `StudentBookingService`, `AvailabilityService`, `TeacherAssignmentService`, `BookingPaymentService`, `BookingAnalyticsService`, `BookingWizardService`, booking actions. |
| Filament | `Bookings`, `BookingTypes`, `TeacherAvailability`, `TeacherLeave`, `BookingReports`, booking widgets. |
| Policies | `BookingPolicy`, `BookingTypePolicy`, `TeacherAvailabilityPolicy`, `TeacherUnavailabilityPolicy`. |
| Tests | `tests/Feature/Booking/*`, `tests/Feature/Guest/*`, `tests/Unit/Booking/*`, student booking tests. |
| Reuse notes | All booking changes should enter through booking contracts/services/actions. |
| Do not duplicate | Do not create another booking wizard, booking table, slot generator, or payment workflow. |

## Payments

| Category | Existing assets |
|---|---|
| Purpose | Payment settings, gateway configuration, payment workflow for bookings, webhook processing. |
| Paths | `app/Services/Payment`, `app/Booking/Payments`, `app/Booking/Registry/PaymentProviderRegistry.php`, payment controllers/jobs/settings. |
| Models | Payment status lives on booking-related models. No standalone payments table was identified in Phase 1 inventory. |
| Migrations | Booking payment fields/settings migrations already exist. |
| Services | `BookingPaymentService`, `PaymentGatewayConnectionService`, `PaymentWebhookProcessor`, `PaymentWebhookSignatureService`, `ProcessPaymentWebhookJob`. |
| Filament | `PaymentSettingsPage`, `PaymentGatewayPage`, `PaymentConfigurationPage`, `PaymentAdvancedPage`, `PaymentBankAccountPage`. |
| Policies | Payment access is currently settings/booking-policy driven. |
| Tests | `tests/Feature/Booking/PaymentWorkflowTest.php`. |
| Reuse notes | Add payment gateways via `PaymentProviderInterface` and `PaymentProviderRegistry`. |
| Do not duplicate | Do not call gateway SDKs directly from controllers/Filament resources. |

## Public Forms and Newsletter

| Category | Existing assets |
|---|---|
| Purpose | Callback, feedback, support, inquiry, contact form block, newsletter subscription. |
| Paths | `app/Forms`, `app/Livewire/Frontend/Forms`, `app/Http/Controllers/Forms`, `app/Services/NewsletterSubscriptionService.php`, form views. |
| Models | `PublicFormSubmission`, `NewsletterSubscriber`. |
| Migrations | `create_public_form_submissions_table`, nullable message migration, `create_newsletter_subscribers_table`. |
| Services/repositories | `PublicFormService`, `PublicFormSubmissionRepository`, `NewsletterSubscriptionService`. |
| Filament | No dedicated form submission resource identified; activity/email logs provide traceability. |
| Policies | Not separately modeled in Phase 1. |
| Tests | `tests/Feature/Forms/*`, `tests/Feature/ContactFormTest.php`. |
| Reuse notes | Add new public forms through `PublicFormType`, service/repository, Livewire component/view. |
| Do not duplicate | Do not create one-off submission tables or send mail directly from controllers. |

## Frontend and Student Portal

| Category | Existing assets |
|---|---|
| Purpose | Public frontend layout, CMS-driven homepage/blocks, account/student dashboard shell, student widgets. |
| Paths | `resources/views/layouts/frontend.blade.php`, `guest.blade.php`, `auth.blade.php`, `student.blade.php`, `account.blade.php`, `app/Livewire/Frontend`, `app/View/Composers/AccountPortalComposer.php`. |
| Models | Reuses `User`, `UserProfile`, bookings, homework, notifications. |
| Migrations | Reuses existing auth/profile/booking/homework/notification tables. |
| Services | `AccountMenuService`, booking services, homework service, profile services, navigation services. |
| Filament | Not applicable for public UI. |
| Policies | Portal middleware and policies for underlying resources. |
| Tests | `tests/Feature/Frontend/*`, `tests/Feature/Student/*`, `tests/Feature/AccountPortal/*`. |
| Reuse notes | Use existing Blade components and Livewire components. |
| Do not duplicate | Do not build a separate SPA, separate frontend app, or second design system. |

## Homework

| Category | Existing assets |
|---|---|
| Purpose | Homework assignments and submission behavior. |
| Paths | `app/Homework`, `app/Models/HomeworkAssignment.php`, student homework views/components. |
| Models | `HomeworkAssignment`. |
| Migrations | `create_homework_assignments_table`. |
| Services/actions | `HomeworkService`, `HomeworkRepository`, `SubmitHomeworkAction`. |
| Filament | No dedicated resource identified in current inventory. |
| Policies | `HomeworkAssignmentPolicy`. |
| Tests | `tests/Feature/Student/HomeworkListTest.php`. |
| Reuse notes | Add homework operations through `HomeworkServiceInterface`. |
| Do not duplicate | Do not create alternate homework task/submission tables without schema review. |

## Settings

| Category | Existing assets |
|---|---|
| Purpose | Runtime configuration managed through Spatie Settings and Filament pages. |
| Paths | `app/Settings`, `database/settings`, `app/Filament/Pages/Settings`. |
| Models | Spatie settings table via `settings`. |
| Migrations | `create_settings_table`, settings migrations under `database/settings`. |
| Services | `SecuritySettingsService` for security saves; settings pages for other groups. |
| Filament | General, Mail, SEO, Payment, Security settings pages. |
| Policies | `SecurityPolicy`, settings access traits, custom gates in `AppServiceProvider`. |
| Tests | `tests/Feature/Security/*SettingsTest.php`, settings-related page tests. |
| Reuse notes | Extend settings classes and settings migrations. |
| Do not duplicate | Do not add another settings table or hardcoded env-only admin config. |

## Permissions and Roles

| Category | Existing assets |
|---|---|
| Purpose | Role/permission access control using Spatie Permission and Filament Shield. |
| Paths | `config/permission.php`, `config/filament-shield.php`, `app/Filament/Resources/Roles`, `Permissions`, policies, seeders. |
| Models | Spatie `Role`, `Permission`; `User` uses `HasRoles`. |
| Migrations | `create_permission_tables`. |
| Services | `PermissionGroupingService`. |
| Filament | Roles and Permissions resources, Shield plugin. |
| Policies | `RolePolicy`, `PermissionPolicy`, resource policies. |
| Tests | `tests/Feature/Roles/*`, authorization tests, policy registration tests. |
| Reuse notes | Use Shield permission naming for resources and documented dotted gates for custom operations. |
| Do not duplicate | Do not create custom ACL tables or role helpers outside `User::isSuperAdmin()` and `PortalResolver`. |

## Media

| Category | Existing assets |
|---|---|
| Purpose | User/profile/page/post/education/experience uploads. |
| Paths | Models implementing `HasMedia`, `config/filesystems.php`, `database/migrations/create_media_table`. |
| Models | `User`, `UserProfile`, `UserExperience`, `UserEducation`, `Page`, `Post`. |
| Migrations | `create_media_table`. |
| Services/actions | `UploadAvatarAction`, profile/media services. |
| Filament | User/profile/page/post forms use media where applicable. |
| Policies | Underlying model policies. |
| Tests | `tests/Feature/Profile/ProfileMediaTest.php`. |
| Reuse notes | Add media collections to existing models. |
| Do not duplicate | Do not add file path columns for avatar, cover, certificates, galleries, or logos. |

## Audit, Notifications, and Mail

| Category | Existing assets |
|---|---|
| Purpose | Traceability, admin bell notifications, transactional email, Resend status logging. |
| Paths | `app/Services/AuditTrailService.php`, `app/Models/Activity.php`, `app/Services/Admin`, `app/Notifications`, `app/Services/Mail`, `app/Listeners/Mail`. |
| Models | `Activity`, `EmailLog`, Laravel database notifications. |
| Migrations | Activity log, notifications, email logs. |
| Services | `AuditTrailService`, `NotificationMapper`, `AdminNotificationService`, `TransactionalNotificationService`, `EmailLogService`. |
| Filament | `ActivityLogResource`, `EmailLogResource`. |
| Policies | `ActivityLogPolicy`, `EmailLogPolicy`. |
| Tests | `tests/Feature/AuditTrail/*`, `tests/Feature/Notifications/*`, `tests/Feature/Mail/*`. |
| Reuse notes | Use events/listeners and services for all notification/audit work. |
| Do not duplicate | Do not call `activity()` or `Mail::send()` directly in new business code. |

## Admin Panel Navigation Groups

Filament resources and pages are organized into navigation groups via `$navigationGroup`. The current groups (verified against `app/Filament/**` — do not copy this list without re-checking, it grows as new domains ship):

`Platform` · `Users & Access` · `Academic` · `Scheduling` · `Booking` · `Finance` · `Earnings` · `Wallet` · `Instructor` · `Referral` · `Students` · `Support` · `Compliance` · `Content` · `Communication` · `Reports` · `System`

To see the current, authoritative resource-to-group assignment, run:

```bash
grep -rhoE "navigationGroup\s*=\s*'[^']+'" app/Filament/ | sort -u
```

When adding a new resource, pick the existing group that matches its domain rather than inventing a new one. Add a group only when a resource genuinely doesn't fit any existing group's purpose.

## Reference Data and Academic Masters

| Category | Existing assets |
|---|---|
| Purpose | Countries/states/currencies/languages and academic master data (categories, subjects, grade levels, skill levels). |
| Paths | `app/Models/{Country,State,Currency,Language,AcademicCategory,Subject,AcademicLevel,SkillLevel}.php`, `app/Enums/{EducationLevel,EmploymentType,AcademicStatus}.php`, `app/Filament/Resources/{Countries,States,Currencies,Languages,Academic}`. |
| Models | `Country`, `State`, `Currency`, `Language`, `AcademicCategory`, `Subject` (belongsTo `AcademicCategory`, belongsToMany `Country` via `subject_country`), `AcademicLevel`, `SkillLevel`. |
| Migrations | `create_countries_table`, `create_states_table`, state profile FK migration, `create_currencies_table`, `create_languages_table`, `create_academic_categories_table`, `create_subjects_table`, `create_subject_country_table`, `create_academic_levels_table`, `create_skill_levels_table`. |
| Services | No dedicated master service identified; admin resources exist. |
| Filament | `Countries`, `States`, `Currencies`, `Languages`, `Academic\{AcademicCategoryResource,SubjectResource,AcademicLevelResource,SkillLevelResource}` (nav group `Academic`). |
| Policies | `CountryPolicy`, `StatePolicy`, `CurrencyPolicy`, `LanguagePolicy`, `AcademicCategoryPolicy`, `SubjectPolicy`, `AcademicLevelPolicy`, `SkillLevelPolicy`. |
| Tests | country/state/currency/language coverage via resource/profile tests; `tests/Feature/Academic/*` and `tests/Feature/Filament/AcademicResourceCrudTest.php` for academic masters. |
| Reuse notes | `AcademicLevel` is deliberately NOT named `EducationLevel` — that enum already means an instructor's own credential type (see `app/Enums/EducationLevel.php`, used by `UserEducation`), a different concept. `Subject` (master data) is separate from `TeacherSubject` (the free-text field booking flows actually read) — see `docs/archive/reports/academic-master-foundation.md` (historical) and `docs/architecture/subject-teacher-subject-reconciliation.md` (current) for why they were not merged and how they now relate. |
| Do not duplicate | Do not create new country/state/currency/language tables without a clear gap. Do not create a second subject/category/level/skill-level table — enhance `app/Models/Academic*`/`Subject`/`SkillLevel` instead. |

## Curriculum and Education Systems

| Category | Existing assets |
|---|---|
| Purpose | Curriculum content structure (Subject + AcademicLevel identity → versioned, publishable structure of modules/topics) and the Education System / Academic System configuration layer that expresses which country/level/curriculum combinations are valid for country-specific booking. `EducationSystemLevel` (Phase 3.1) is the exact, student-selectable level within a system (Class 10/Grade 10/Year 10) — students never choose the broad `AcademicLevel` band directly. |
| Paths | `app/Models/{Curriculum,CurriculumVersion,CurriculumModule,CurriculumModuleTopic,EducationSystem,CountryEducationSystem,EducationSystemAcademicLevel,EducationSystemLevel,CurriculumEducationSystem}.php`, `app/Curriculum/{Enums,Exceptions,Services,DTOs}`, `app/Filament/Resources/Academic/{CurriculumResource,CurriculumVersionResource,CurriculumModuleResource,EducationSystemResource}`. |
| Models | `Curriculum` (belongsTo `Subject` + `AcademicLevel`, identity only — SRS Book 2 §4.9) → `CurriculumVersion` (Draft→Published→Archived→Retired, forward-only, `App\Curriculum\Enums\CurriculumVersionStatus`) → `CurriculumModule` → `CurriculumModuleTopic` (maps existing `SubjectTopic` rows, never a second topic master). `EducationSystem` (administrator-managed reference data — CBSE/ICSE/IB/GCSE/SAT/AP are DATA, never hardcoded PHP; status reuses `AcademicStatus`, no separate lifecycle; also carries `level_term_singular`/`level_term_plural` terminology, Phase 3.1). Applicability is expressed entirely through explicit many-to-many mapping models, never a direct FK on Country/AcademicLevel/Curriculum: `CountryEducationSystem` (country ↔ system, `is_active`/`display_order`), `EducationSystemAcademicLevel` (system ↔ level, `is_active`/`display_order` — the broad-band mapping only), `CurriculumEducationSystem` (curriculum ↔ system, no active flag — presence is the signal). `EducationSystemLevel` (Phase 3.1, new standalone table — deliberately NOT a reuse of `EducationSystemAcademicLevel`, whose `(education_system_id, academic_level_id)` uniqueness cannot represent several levels sharing one band): `education_system_id` + `academic_level_id` FKs, `value`, `display_label`, nullable `normalized_grade` (bridges to the universal 1-12 int the booking engine matches on; null = currently unsupported for Demo booking), `display_order`, `is_active`; unique on `(education_system_id, value)`. |
| Migrations | `create_curricula_table`, `create_curriculum_versions_table`, `create_curriculum_modules_table`, `create_curriculum_module_topics_table`, `create_education_systems_table`, `create_country_education_system_table`, `create_education_system_academic_level_table`, `create_curriculum_education_system_table`, `create_education_system_levels_table` (Phase 3.1), `add_level_terminology_to_education_systems_table` (Phase 3.1). All UUID PKs, matching the Curriculum domain's existing convention. |
| Services | `App\Curriculum\Services\CurriculumService` — sole writer of Curriculum/CurriculumVersion/CurriculumModule/topic-assignment mutations and lifecycle transitions. `App\Curriculum\Services\EducationSystemService` — sole writer of EducationSystem config and all mapping tables including `EducationSystemLevel` (`addLevel`/`updateLevel`/`removeLevel`, Phase 3.1) — duplicate-mapping/value prevention + audit, mirrors CurriculumService's role. `App\Curriculum\Services\AcademicContextResolver` — the single authority resolving which Country → EducationSystem → AcademicLevel → Subject → Curriculum → CurriculumVersion combinations are currently valid (mirrors `App\Country\Services\CountryFeatureResolver`'s "single authority" role); always intersects with the pre-existing `Subject::isAvailableInCountry()` rule rather than duplicating it. Also exposes `levelsForSystem()`/`resolveContextForLevel()` (Phase 3.1) — derives `AcademicLevel` from a selected `EducationSystemLevel` and delegates to the same `resolveContext()`, no parallel resolution logic. Returns `App\Curriculum\DTOs\AcademicContextData` (readonly DTO, mirrors `AssignmentCriteriaData`'s shape — no Booking-specific fields; Booking-domain level snapshot fields live on `App\Booking\DTOs\BookingAcademicContextData` instead, see `docs/booking.md`). "Current published version" is defined as the highest `version_number` currently `Published` for a curriculum (`Curriculum::latestPublishedVersion()`) — never `latest(id)`/`latest(created_at)`, because `CurriculumService::publish()` does not auto-archive a prior Published version. |
| Filament | `Academic\{CurriculumResource,CurriculumVersionResource,CurriculumModuleResource,EducationSystemResource}` (nav group `Academic`/`Academics`). `EducationSystemResource` carries four relation managers (`CountryMappingsRelationManager`, `AcademicLevelMappingsRelationManager`, `EducationSystemLevelsRelationManager` — Phase 3.1 "Levels" tab, `CurriculumMappingsRelationManager`) — all mutations route through `EducationSystemService`, never raw Filament attach/detach. |
| Policies | `CurriculumPolicy`, `CurriculumVersionPolicy`, `CurriculumModulePolicy`, `EducationSystemPolicy` (also gates the mapping tables and `EducationSystemLevel` — they have no policy of their own, mirroring `CurriculumModuleTopic`). Permissions seeded via `AcademicPermissionSeeder`'s `MODULES` array (`Curriculum`, `CurriculumVersion`, `CurriculumModule`, `EducationSystem`). |
| Tests | `tests/Feature/Academic/{CurriculumTest,EducationSystemTest,AcademicContextResolverTest,EducationSystemLevelTest}.php`, `tests/Feature/Filament/CurriculumResourceCrudTest.php`. |
| Legacy compatibility | A `Curriculum` (or `AcademicLevel`) with **zero** EducationSystem mappings is globally applicable / system-neutral — it resolves under any otherwise-valid Education System/Country combination for its Subject + AcademicLevel. Once a curriculum has one or more mappings, it resolves only for those mapped systems. No fake "Global"/"Legacy" records are backfilled — absence of a mapping row IS the global signal. |
| Do not duplicate | Do not create board-specific models (CbseBoard, IcseBoard, UsEducationSystem, ...) or a hardcoded board enum — `EducationSystem` rows are admin-managed data. Do not add `countries.education_system_id`, `education_systems.country_id`, `academic_levels.education_system_id`, or a mandatory `curricula.education_system_id` — every one of those relationships is many-to-many by design and must go through the mapping models above (including `EducationSystemLevel`, which is itself a mapping-style table, not a column on `academic_levels`). Do not duplicate `Subject::isAvailableInCountry()` logic inside the resolver. Do not build a second Draft/Published/Archived/Retired workflow for `EducationSystem` — that lifecycle belongs to `CurriculumVersion` only. Do not derive country-aware booking level options from `AcademicLevel.min_grade/max_grade` — that bridge remains internal-only; `EducationSystemLevel` is the sole source of truth for student-facing level selection (Phase 3.1). |

## Instructor Academic Eligibility (Phase 2)

| Category | Existing assets |
|---|---|
| Purpose | Answers "is Instructor X academically eligible to teach this resolved Academic Context" — the Instructor-side counterpart to `AcademicContextResolver`. Foundation for country-aware Demo/Paid booking; does not itself touch BookingWizard/BookingService/TeacherCandidateRepository/pricing/availability. |
| Paths | `app/Models/InstructorCurriculumEligibility.php`, `app/Curriculum/Services/{InstructorAcademicEligibilityService,InstructorAcademicEligibilityResolver}.php`, `app/Curriculum/Exceptions/InstructorAcademicEligibilityException.php`, `app/Policies/InstructorCurriculumEligibilityPolicy.php`, `app/Filament/Resources/InstructorOnboarding/RelationManagers/CurriculumEligibilityRelationManager.php`. |
| Model | `InstructorCurriculumEligibility` (`teacher_id`, `education_system_id`, `curriculum_id`, `is_active`, `notes`, `approved_at`/`approved_by`) — anchors to **Curriculum identity**, never `CurriculumVersion` (a curriculum revision must never silently revoke an instructor's qualification). `education_system_id` is always explicit on the row, never inferred from `Curriculum::educationSystemMappings()`, because a system-neutral Curriculum may be independently approved under more than one Education System for the same instructor. No `country_id` — instructor eligibility is country-independent by design; Student Country only selects which Education Systems are *offered*, validated separately by `AcademicContextResolver`. Unique `(teacher_id, education_system_id, curriculum_id)` — lifecycle is a single active/inactive row, never re-created, so the constraint stays correct without a `deleted_at`-aware partial index. `PreventsHardDeletion`, no `SoftDeletes` — no delete() path exists in application code at all; deactivation is the only supported removal. |
| Services | `InstructorAcademicEligibilityService` — sole writer (`assign`/`deactivate`/`reactivate`/`validateConfiguration`); validates EducationSystem/Subject/AcademicLevel are active, `Curriculum::appliesToEducationSystem()`, and that the instructor already teaches the Curriculum's Subject at a covering grade range via `TeacherSubject` (confirmed authoritative over `UserProfile::instructor_academic_level_ids`, which is unstructured/display-only/not per-subject — see Phase 2 completion report for the inconsistency this surfaced). Concurrency-safe: transaction + `lockForUpdate()` + DB unique-constraint fallback, never `exists()`-then-`create()` alone. `InstructorAcademicEligibilityResolver` — read-only runtime check (`isEligible`/`assertEligible` consume `AcademicContextData`; `eligibleEducationSystemsFor`/`eligibleCurriculaFor` for admin/future-booking listing). Deliberately checks academic capability only — instructor account/lifecycle status remains `TeacherCandidateRepository::isApprovedTeacher()`'s job; a future booking-phase composition layer combines both, never merged here. |
| New model addition | `Curriculum::appliesToEducationSystem(EducationSystem $system): bool` — extracted from `AcademicContextResolver`'s existing "zero mappings = global" predicate so this service reuses it instead of re-deriving it; `AcademicContextResolver`'s own logic was left untouched. |
| Filament | Hung off the existing `InstructorOnboardingResource` edit page via `CurriculumEligibilityRelationManager` (Admin → Instructor → Onboarding → Academic Capabilities) — no new top-level navigation item. Progressive selection (Education System → filtered Curricula) is UI convenience only; `InstructorAcademicEligibilityService::validateConfiguration()` remains the sole authority. |
| Policies | `InstructorCurriculumEligibilityPolicy`, permissions seeded via `AcademicPermissionSeeder`'s `MODULES` array (`InstructorCurriculumEligibility`). |
| Tests | `tests/Feature/Academic/InstructorAcademicEligibilityTest.php` (domain/duplicate/concurrency), `tests/Feature/Academic/InstructorAcademicEligibilityResolverTest.php` (runtime resolver), `tests/Feature/Filament/InstructorCurriculumEligibilityFilamentTest.php`. |
| Do not duplicate | Do not create a second Instructor↔Subject or Instructor↔Level table — `TeacherSubject`/`InstructorSubjectTopic` remain authoritative and are validated, not replaced. Do not infer Education System capability from Curriculum's own system mappings or from Subject capability alone — it must always be an explicit, separately-approved row. Do not add instructor-country-based restrictions. Do not integrate with `TeacherCandidateRepository`/`BookingWizard`/marketplace filtering yet — that is a later phase's job. |

## Personalized Packages (Phase — Instructor Package Proposal & Admin Approval Foundation)

| Category | Existing assets |
|---|---|
| Purpose | An instructor proposes a personalized, multi-lesson package to an existing (already-paid) student, priced automatically from the existing `StudentLessonPrice` matrix; an admin reviews, optionally overrides the final price (audited, in the already-locked currency), and approves/rejects; the student views and accepts. Not in SRS.md — confirmed a brand-new domain (SRS only ever says "lesson packages" as a forward-looking out-of-scope placeholder). Deliberately separate from `StudentLearningPlan`, `Curriculum`, `Booking`, and `Earnings` — those are different concepts and are only ever read from, never written to, by this domain. |
| Paths | `app/Package/{DTOs,Enums,Exceptions,Services}`, `app/Models/{PackageBenefitRule,InstructorPackageProposal}.php`, `app/Policies/{PackageBenefitRulePolicy,InstructorPackageProposalPolicy}.php`, `app/Filament/Resources/{PackageBenefitRules,InstructorPackageProposals}`, `app/Livewire/Frontend/Instructor/PackageProposalCreator.php`, `app/Livewire/Frontend/Student/PackageProposals.php`. Deliberately flat `app/Models`/`app/Policies` (not nested under `app/Package/`) and no `Contracts`/`Repositories`/`Actions` subfolder — mirrors Curriculum's minimal-subset convention; plain Eloquent queries in Services, no interface-swappable repository layer needed at this domain's current size. |
| Models | `PackageBenefitRule` (admin-managed, reusable quantity rule — `name`, `paid_quantity`, `bonus_quantity`, `total_quantity` with a DB CHECK `total = paid + bonus`, `is_active`; carries **no price of any kind**). `InstructorPackageProposal` (`instructor_id`/`student_id` restrictOnDelete; `package_benefit_rule_id`/`subject_id`/`academic_level_id`/`booking_type_id`/`country_id`/`currency_id` nullOnDelete; its own immutable price snapshot — `unit_price_minor`, `paid_quantity`/`bonus_quantity`/`total_quantity` (copied from the rule at submission, so a later rule edit never rewrites a submitted proposal), `calculated_price_minor`, `override_price_minor`/`overridden_by`/`overridden_at`/`override_reason`, `final_price_minor = override ?? calculated`; `status` — `App\Package\Enums\InstructorPackageProposalStatus`). Immutable once `Accepted`: a custom `updating` guard (not the blanket `PreventsUpdates` trait, which would block the legitimate Draft→Submitted→Approved→Accepted progression) throws only when the row's *original* status is `Accepted`. |
| Migrations | `create_package_benefit_rules_table`, `create_instructor_package_proposals_table`. Both UUID PKs (matching every other admin-master/proposal table in this codebase); `instructor_id`/`student_id` are `unsignedBigInteger` (users table). |
| Services | `App\Package\Services\PackagePricingService` — the **only** new math anywhere in this domain (`calculated_price_minor = unit_price_minor * paid_quantity`); everything else is composed from `App\Country\Services\CountryResolver::forStudent()` + `App\Booking\Services\StudentLessonPriceResolver::resolve()` (reused as-is, never duplicated). `App\Package\Services\PackageBenefitRuleService` — sole writer of `PackageBenefitRule`, validates the quantity invariant server-side too (defense-in-depth alongside the DB CHECK). `App\Package\Services\InstructorPackageProposalService` — sole writer of every `InstructorPackageProposal` state transition and price/quantity field (`hasValidRelationship`/`eligibleStudentsFor`/`create`/`recalculate`/`submit`/`proposeAndSubmit`/`approve`/`reject`/`cancel`/`accept`/`previewPrice`); row-locked transactions, enum-owned transition guard, `AuditTrailService` on every mutation. Never touches `App\Earnings\Services\InstructorCompensationResolver` — instructor pay continues to resolve from completed `Lesson` rows only; bonus lessons affect student entitlement, never instructor compensation. |
| Relationship check | No shared "student↔instructor relationship" service/table exists in this codebase — `InstructorPackageProposalService::hasValidRelationship()` mirrors `MessagingEligibilityService`'s direct-query pattern with its own definition (a `Confirmed`/`Completed` + `Paid` `Booking` between them), read-only against `Booking`, never a new table. |
| Filament | `PackageBenefitRuleResource` (standard CRUD) and `InstructorPackageProposalResource` (list-only — no Create/Edit/View pages, `canCreate()` → `false`, mirrors `InstructorWithdrawalRequestResource` exactly; every row action calls the Service and converts `PackageException` into a `Notification`). The calculation breakdown (unit price, paid lessons, calculated price, override, final price) is shown as table columns, not a separate view page. Both live under nav `Academics > Learning Management` alongside `StudentLearningPlanResource` — **deliberately not Finance**: a package is a learning offer, not a finance record. Finance owns payment/wallet/settlement/earnings only. |
| User-facing terminology | Model/table/service names stay `PackageBenefitRule`/`package_benefit_rules` (internal only), but every admin- and instructor-visible label says **"Package Offer"** — "rule" is internal jargon and was confusing in the UI. Quantity fields read **Paid Lessons / Bonus Lessons / Total Lessons** (not "Quantity"). The admin proposal resource is titled **"Instructor Package Proposals"** (admin is reviewing instructor-created offers). Instructor portal menu item is **"Package Offers"**; the student portal keeps plain **"Packages"** (a student receives an actual package, never an offer template). When adding UI here, never surface the word "Rule" to a user. |
| Role boundary | **Admin** authors reusable *Package Offer templates* (e.g. "10 paid + 2 bonus lessons") and reviews/approves/prices proposals. **Instructor** creates a *Package Proposal* — offering one of those templates to one specific student for a subject/level; never sets or overrides price. **Student** views and accepts an approved proposal. **Finance** is downstream and out of this domain: payment, wallet, settlement, earnings. |
| Policies | `PackageBenefitRulePolicy` (admin-only CRUD). `InstructorPackageProposalPolicy` — instructor create/submit/cancel own, admin approve/reject/overridePrice via explicit permissions (`Approve:InstructorPackageProposal`/`Reject:InstructorPackageProposal`/`OverridePrice:InstructorPackageProposal`), student view-only and only once `Approved`/`Accepted`. The specific-student eligibility check is deliberately a **Service**-level business rule, not a Policy concern (mirrors the Curriculum-eligibility vs. `AcademicContextResolver` split). Permissions seeded via `database/seeders/PackagePermissionSeeder.php` — deliberately NOT `AcademicPermissionSeeder`'s uniform CRUD-per-role shape, since the three roles get disjoint action subsets (manager reviews/decides, instructor creates/submits/cancels own, student views/accepts own). |
| Audit events | `package_created`, `package_submitted`, `package_approved`, `package_rejected`, `package_price_overridden` (via `AuditTrailService::logOverride()`, reason required), `package_accepted` — all via `AuditTrailService`, never raw `activity()`. |
| Tests | `tests/Feature/Package/{PackageBenefitRuleTest,InstructorPackageProposalTest}.php`, `tests/Feature/Filament/{PackageBenefitRuleResourceTest,InstructorPackageProposalResourceTest}.php`. |
| Explicitly out of scope this phase | Payment/wallet settlement, instructor payout changes, lesson/booking entitlement creation from an accepted package, Learning Plan integration, country-specific package rules, education-system package pricing matrices, recurring package scheduling. `accept()` is a placeholder: it only flips status/timestamp, no money moves and no Lesson/Booking is created. |
| Do not duplicate | Do not add a price/currency field the instructor can set — price is always resolved server-side via `PackagePricingService`, never accepted from client input, for any role except the admin override (audited, same currency only). Do not create a second student-currency-resolution concept — the currency locked onto a proposal is always the resolved `StudentLessonPrice` row's own currency, exactly as every other paid-lesson flow already works. Do not let package pricing/bonus quantities feed into `InstructorCompensationResolver` — compensation stays lesson-completion-triggered only, per the platform-wide "Student Price ≠ Instructor Pay" rule (see `docs/financial-domain-architecture.md`). Do not create a `Package` table/model inside `app/Booking`, `app/Curriculum`, `app/Learning*`, or `app/Earnings` — this is its own domain. |

