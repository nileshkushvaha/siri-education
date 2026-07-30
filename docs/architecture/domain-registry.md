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

