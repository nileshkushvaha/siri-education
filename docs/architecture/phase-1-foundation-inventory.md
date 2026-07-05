# Phase 1 Foundation Inventory

## 1. Executive Summary

The application already has a substantial Phase 1 foundation: CMS, Blog, Navigation, Settings, Security, Booking, Public Frontend, Student Portal, Instructor profiles, Forms, transactional email logging, and Filament administration are present. The codebase is not a blank Laravel install and future work must extend the existing modules rather than recreating them.

Current inventory highlights:

- 32 Eloquent models across `app/Models` and `app/Content/Models`.
- 19 Filament resources under `app/Filament/Resources`.
- 28 Livewire component classes under `app/Livewire`.
- 14 Spatie Settings classes under `app/Settings`.
- 141 test files under `tests`.
- Established bounded contexts exist for Booking, Navigation, Content/CMS, Forms, Homework, Mail, Profile, Security, and Auth.

Primary architectural rule for future phases: reuse existing service/provider boundaries and avoid adding parallel concepts such as a second page model, second navigation system, second booking engine, second profile table, second notification log, or second public design system.

## 2. Existing Architecture Map

### Core Application Shape

| Area | Existing source of truth | Notes |
|---|---|---|
| Admin panel | `app/Providers/Filament/AdminPanelProvider.php` | Filament v5 panel at `/admin`, auto-discovers resources/pages/widgets, uses Shield plugin. |
| Portal routing | `app/Services/PortalResolver.php` | Single source for Admin vs Frontend portal selection. Do not duplicate role routing logic. |
| CMS rendering | `app/Services/PageRenderService.php`, `app/Content/Rendering/ContentRenderer.php` | Page rendering pipeline already exists. |
| CMS blocks | `app/Content/Models/ContentBlock.php`, `app/Forms/Blocks/*BlockForm.php`, `resources/views/components/blocks/*.blade.php` | Dynamic block forms and block Blade components already exist. |
| Navigation | `app/Navigation/*`, `app/Providers/NavigationServiceProvider.php` | Has repository, renderer, cache manager, permission evaluator, link drivers. |
| Booking | `app/Booking/*`, `app/Providers/BookingServiceProvider.php` | Strong domain module with contracts, repositories, services, DTOs, events, actions, registries. |
| Forms | `app/Forms/*`, `app/Providers/FormsServiceProvider.php` | Public form service/repository already exists. |
| Frontend | `app/Providers/FrontendServiceProvider.php`, `resources/views/layouts/frontend.blade.php`, `app/Livewire/Frontend/*` | Blade/Livewire public frontend foundation already exists. |
| Account/Student portal | `resources/views/layouts/account.blade.php`, `resources/views/layouts/student.blade.php`, `app/View/Composers/AccountPortalComposer.php` | Shared authenticated frontend layout data is composed by provider. |
| Security | `app/Services/Security/*`, `app/Filament/Pages/Security/*`, `app/Policies/Security/SecurityPolicy.php` | Six security settings sections are already permission-gated. |
| Settings | `app/Settings/*`, `app/Filament/Pages/Settings/*`, `database/settings/*` | Uses Spatie Settings migrations, not normal migrations. |
| Audit trail | `app/Services/AuditTrailService.php`, `app/Models/Activity.php`, `app/Observers/*` | Activitylog is already wrapped, but some legacy raw `activity()` calls remain. |
| Transactional email | `app/Services/Mail/*`, `app/Models/EmailLog.php`, `app/Filament/Resources/EmailLogs/EmailLogResource.php` | Resend provider and email logs already exist. |

### Providers

| Provider | Responsibility |
|---|---|
| `app/Providers/AppServiceProvider.php` | Policies, super admin gate, observers, scheduler listeners, rate limiters, destructive DB guard. |
| `app/Providers/BookingServiceProvider.php` | Booking interface bindings, registries, payment provider registry, booking types, assignment strategies. |
| `app/Providers/CmsServiceProvider.php` | CMS morph map, content block observer/policy, page renderer binding. |
| `app/Providers/FormsServiceProvider.php` | Public form repository/service bindings. |
| `app/Providers/FrontendServiceProvider.php` | Frontend view composers for account/student portal pages. |
| `app/Providers/HomeworkServiceProvider.php` | Homework repository/service bindings and policy. |
| `app/Providers/NavigationServiceProvider.php` | Navigation interfaces, singletons, link drivers, observers. |
| `app/Providers/EventServiceProvider.php` | Explicit domain event/listener map. Auto-discovery is disabled. |

## 3. Existing Module Inventory

### 3.1 Domain Folders Under `app/`

| Folder | Module | Inventory |
|---|---|---|
| `app/Actions` | Auth/Profile/CMS actions | `AttemptLoginAction`, `RegisterUserAction`, `UpdateProfileAction`, `UploadAvatarAction`, `ValidateBlockContentAction`, `GeneratePageSlugAction`. |
| `app/Booking` | Booking domain | Actions, contracts, DTOs, enums, events, exceptions, payments, registries, repositories, services, booking types, validation rules. |
| `app/Content` | CMS content blocks/rendering | `ContentBlock`, `HasContentBlocks`, `ContentRenderer`, `SeoManager`, `ContentBlockService`. |
| `app/Forms` | Public forms | Block forms, public form contracts/repository/service, `PublicFormType`. |
| `app/Homework` | Student homework | Action, contracts, enum, exception, repository, service. |
| `app/Navigation` | CMS navigation | Contracts, DTOs, drivers, registry, services, helper. |
| `app/Services` | Cross-module services | Auth, Admin, Account, Faq, Instructor, Mail, Payment, Permission, Profile, Security plus CMS/search/cache/page/post services. |
| `app/Livewire/Frontend` | Public/frontend interactivity | Auth forms, booking wizard, CMS widgets, public forms, layout components, student dashboard widgets. |
| `app/Filament` | Admin UI | Resources, pages, widgets, auth page, settings/security pages. |
| `app/Notifications` | Transactional and system notifications | Auth, Booking, Forms, CMS, Instructor, Newsletter, Mail category bases. |

### 3.2 Models

| Model | Table/concept | Reuse guidance |
|---|---|---|
| `app/Models/User.php` | `users` | Identity, auth, roles, portal access, instructor relation anchors. Do not create `Student`, `Instructor`, or `Tutor` user tables without a real domain split. |
| `app/Models/UserProfile.php` | `user_profiles` | Profile, instructor flags/status, notification prefs, avatar/cover. Enhance for profile fields. |
| `app/Models/UserExperience.php` | `user_experiences` | Work history with media. Reuse for instructor experience. |
| `app/Models/UserEducation.php` | `user_educations` | Education documents/certificates. Reuse for instructor/student education. |
| `app/Models/UserSession.php` | `user_sessions` | Session tracking. |
| `app/Models/UserPasswordHistory.php` | `user_password_histories` | Password reuse policy. |
| `app/Models/LoginHistory.php` | `login_histories` | Login audit/history. |
| `app/Models/Page.php` | `pages` | CMS pages. Do not create another page model. |
| `app/Models/Post.php` | `posts` | Blog/content posts. |
| `app/Models/PostCategory.php` | `post_categories` | Blog categories. |
| `app/Models/Tag.php` | `tags` | Blog tags. |
| `app/Content/Models/ContentBlock.php` | `content_blocks` | Reusable CMS blocks for pages/posts. |
| `app/Models/NavigationMenu.php` | `navigation_menus` | Navigation containers. |
| `app/Models/NavigationItem.php` | `navigation_items` | Nested navigation items. |
| `app/Models/Faq.php` | `faqs` | FAQ content. |
| `app/Models/FaqCategory.php` | `faq_categories` | FAQ taxonomy. |
| `app/Models/Booking.php` | `bookings` | Booking aggregate. |
| `app/Models/BookingType.php` | `booking_types` | Booking type catalog. |
| `app/Models/BookingGuest.php` | `booking_guests` | Guest attendees. |
| `app/Models/BookingActivity.php` | `booking_activities` | Booking lifecycle activity. |
| `app/Models/TeacherAvailability.php` | `teacher_availability` | Instructor/teacher weekly availability. Naming is legacy teacher wording. |
| `app/Models/TeacherUnavailability.php` | `teacher_unavailability` | Leave/unavailable windows. |
| `app/Models/TeacherSubject.php` | `teacher_subjects` | Instructor subject mapping. |
| `app/Models/Holiday.php` | `holidays` | Availability exclusions. |
| `app/Models/HomeworkAssignment.php` | `homework_assignments` | Student homework. |
| `app/Models/PublicFormSubmission.php` | `public_form_submissions` | Public forms. |
| `app/Models/NewsletterSubscriber.php` | `newsletter_subscribers` | Newsletter subscriptions. |
| `app/Models/EmailLog.php` | `email_logs` | Transactional email audit. |
| `app/Models/Country.php` | `countries` | Reference data. |
| `app/Models/State.php` | `states` | Reference data. |
| `app/Models/SchedulerHistory.php` | `scheduler_histories` | Scheduler monitor. |
| `app/Models/Activity.php` | `activity_log` | Activitylog extension. |

### 3.3 Migrations

Existing migrations cover:

- Framework tables: `database/migrations/0001_01_01_000000_create_users_table.php`, cache, jobs.
- Settings: `database/migrations/2022_12_14_083707_create_settings_table.php`.
- Permission/roles: `database/migrations/2026_06_26_175107_create_permission_tables.php`.
- Activity: `database/migrations/2026_06_26_174252_create_activity_log_table.php`, `database/migrations/2026_06_29_230000_add_audit_trail_columns_to_activity_log_table.php`.
- Profile/auth/security: `user_profiles`, login histories, sessions, password histories, user slugs, profile rebuild/backfill, instructor columns.
- CMS/blog/navigation/media: pages, posts, categories, tags, pivots, content blocks, navigation menus/items, media.
- Booking: booking types, bookings, guests, availability, unavailability, activities, holidays, guest support, reservations, analytics indexes, manage token hashing.
- Forms/newsletter/homework: public form submissions, newsletter subscribers, homework assignments.
- Mail: `database/migrations/2026_07_07_000000_create_email_logs_table.php`.

Settings migrations live under `database/settings`, including mail, SEO, security, payment, booking, captcha, and Resend sender settings. Future settings defaults should be added there, not in normal migrations.

### 3.4 Filament Resources and Pages

Resources already exist for:

- Activity logs: `app/Filament/Resources/ActivityLog`.
- Booking admin: `app/Filament/Resources/Bookings`, `BookingTypes`, `TeacherAvailability`, `TeacherLeave`.
- CMS/content: `Pages`, `PageBlocks`, `Posts`, `PostCategories`, `Tags`, `Faq`.
- Navigation: `app/Filament/Resources/Navigation`.
- Users/roles/permissions: `Users`, `Roles`, `Permissions`.
- Reference data: `Countries`, `States`.
- System email logs: `EmailLogs`.
- Login history: `LoginHistory`.

Filament pages already exist for dashboard, cache, queue, scheduler, booking reports, profile, change password, security settings, payment settings, general/mail/SEO settings, and custom login.

Risk: several resources/pages still contain direct updates or raw activity calls. See Risks section.

### 3.5 Livewire Components

Existing Livewire components:

- Auth: `app/Livewire/Frontend/Auth/*`.
- Booking: `app/Livewire/Frontend/Booking/BookingWizard.php`.
- CMS widgets: `FeaturedTeachers`, `Newsletter`.
- Public forms: `CallbackForm`, `FeedbackForm`, `GeneralInquiryForm`, `SupportForm`.
- Layout: `AnnouncementBar`, `CookieBanner`, `SearchOverlay`, `SiteFooter`, `SiteHeader`.
- Student portal: attendance, bookings, dashboard, homework, notifications, payments, progress, upcoming classes.
- Admin navigation builder: `app/Livewire/Navigation/MenuBuilder.php`.

Risk: `app/Livewire/Navigation/MenuBuilder.php` performs direct queries against Page/Post/Category/Tag/NavigationItem. This may be acceptable for an admin builder, but it violates the strict “Livewire thin” rule and should eventually move search/delete lookups behind `NavigationItemService`/repository methods.

### 3.6 Policies

Policies exist for users, CMS, posts, tags, navigation, booking, booking type, teacher availability/unavailability, homework, activity, email log, security, settings-related pages, cache/queue/scheduler, profile, roles, permissions, countries/states, and content blocks.

Important note: several policies call `$user->can(...)`, e.g. `app/Policies/PagePolicy.php`, `app/Policies/PostPolicy.php`, `app/Policies/UserPolicy.php`, while `docs/standards.md` says policies should call `$user->hasPermissionTo()` and catch `PermissionDoesNotExist`. This is a consistency risk.

### 3.7 Enums

Existing enums include:

- General: `ActivityActorType`, `BlockType`, `EducationLevel`, `EmploymentType`, `FaqAudience`, `FaqStatus`, `InstructorStatus`, `LoginResult`, `NewsletterSubscriberStatus`, `PageStatus`, `PageVisibility`.
- Navigation: `NavigationLayoutType`, `NavigationLinkType`, `NavigationLocation`, `NavigationStatus`, `NavigationVisibility`.
- Booking: `app/Booking/Enums/*` for status, actors, payment status, guest status, location type, weekdays, webhook event, activity action.
- Homework: `app/Homework/Enums/HomeworkStatus.php`.
- Forms: `app/Forms/Enums/PublicFormType.php`.

### 3.8 Services, Actions, Repositories, Contracts, DTOs

Reuse these service boundaries:

- Booking: `app/Booking/Contracts/*`, `app/Booking/Services/*`, `app/Booking/Repositories/*`, `app/Booking/Actions/*`, `app/Booking/DTOs/*`.
- Navigation: `app/Navigation/Contracts/*`, `app/Navigation/Services/*`, `app/Navigation/DTOs/*`, `app/Navigation/Drivers/*`.
- Forms: `app/Forms/Contracts/*`, `app/Forms/Services/PublicFormService.php`, `app/Forms/Repositories/PublicFormSubmissionRepository.php`.
- Homework: `app/Homework/Contracts/*`, `app/Homework/Services/HomeworkService.php`, `app/Homework/Repositories/HomeworkRepository.php`.
- Profile: `app/Services/Profile/*`, `app/Actions/Profile/*`.
- Auth/security: `app/Services/Auth/*`, `app/Actions/Auth/*`, `app/Services/Security/*`.
- Mail: `app/Services/Mail/*`.
- CMS: `app/Services/PageService.php`, `PostService.php`, `PageRenderService.php`, `BlockRenderer.php`, `BlockContentHydrator.php`, `BlockContentConverter.php`, `ContentBlockService.php`.
- Admin notification pipeline: `app/Services/Admin/*`, `app/DTOs/NotificationPayload.php`.

### 3.9 Settings Classes

Existing settings classes:

- `GeneralSettings`
- `SeoSettings`
- `MailSettings`
- `AuthenticationSettings`
- `PasswordPolicySettings`
- `LoginSecuritySettings`
- `SessionSettings`
- `RegistrationSettings`
- `AccountProtectionSettings`
- `BookingSettings`
- `BankSettings`
- `PaymentGatewaySettings`
- `PaymentConfigurationSettings`
- `PaymentAdvancedSettings`

Do not create duplicate settings tables or config-only replacements. Extend these classes plus `database/settings/*` and corresponding Filament pages.

### 3.10 Permissions and Filament Shield

Shield config lives at `config/filament-shield.php`.

Current behavior:

- Super admin enabled via Shield config and reinforced by `Gate::before` in `app/Providers/AppServiceProvider.php`.
- Permission naming is Pascal with `:` separator, e.g. `ViewAny:Booking`.
- `panel_user` is enabled.
- Shield discovers current panel resources/pages/widgets only.
- Custom permissions array is empty.

Seeders:

- `database/seeders/SuperAdminSeeder.php`
- `database/seeders/DefaultRolesAndUsersSeeder.php`
- `database/seeders/StudentRoleSeeder.php`
- `database/seeders/BookingPermissionSeeder.php`
- `database/seeders/PagePermissionSeeder.php`
- `database/seeders/PostPermissionSeeder.php`
- `database/seeders/FaqPermissionSeeder.php`

Naming overlap: settings/security/cache permissions use dotted names such as `security.authentication.view` and `cache_manager.view`, while Shield resource permissions use `ViewAny:Model`. This is acceptable but should be documented and kept intentional.

### 3.11 Media Collections

Existing media collections:

| Model | Collections |
|---|---|
| `app/Models/User.php` | `instructor_cover` |
| `app/Models/UserProfile.php` | `avatar`, `cover` |
| `app/Models/UserExperience.php` | `company_logo`, `supporting_documents` |
| `app/Models/UserEducation.php` | `certificate`, `transcript`, `degree_document` |
| `app/Models/Page.php` | `featured-image` |
| `app/Models/Post.php` | `featured-image`, `gallery` |

Do not add separate avatar/cover columns or custom upload tables. Use Spatie Media Library.

### 3.12 Activity/Audit Logging

Existing audit structures:

- Wrapper service: `app/Services/AuditTrailService.php`.
- Activity model: `app/Models/Activity.php`.
- Admin notification bridge: `app/Listeners/NotifyAdminsOnActivity.php`, `app/Services/Admin/NotificationMapper.php`, `AdminNotificationService.php`.
- Booking audit listener: `app/Listeners/Booking/RecordBookingLifecycleAudit.php`.
- Many observers use `AuditTrailService`.

Legacy/raw `activity()` calls remain in:

- `app/Console/Commands/PublishScheduledContent.php`
- `app/Http/Controllers/Admin/PagePreviewController.php`
- `app/Http/Controllers/Admin/PostPreviewController.php`
- `app/Http/Controllers/Auth/ForcePasswordChangeController.php`
- `app/Services/Auth/*`
- `app/Services/Security/*`
- `app/Services/Payment/PaymentWebhookProcessor.php`
- `app/Filament/Resources/Users/Pages/*`
- `app/Filament/Resources/Roles/Pages/*`
- `app/Filament/Pages/AdminChangePassword.php`

Recommendation: enhance these over time to use `AuditTrailService`, but do not block Phase 1 if behavior is already covered by tests.

### 3.13 Tests

Tests already cover:

- Auth and security settings.
- Profile, public profile, media, authorization.
- CMS pages/posts/blocks/rendering.
- Navigation system and cache.
- Booking engine, analytics, payments, guest booking, booking wizard.
- Forms/newsletter.
- Frontend integration/rendering/search.
- Filament admin resource behavior.
- Permissions, roles, policies, portal architecture.
- Activity/audit log and notifications.
- Mail transactional logging.
- Student dashboard sections.

Future Phase 1 work should add focused tests beside existing module tests instead of creating a new broad test style.

### 3.14 Routes and Middleware

Routes:

- Public: homepage, search, sitemap/robots, booking wizard/manage, FAQs, blog, contact/forms/newsletter, instructors, public profile, CMS catch-all.
- Auth: register/login/password reset/unlock/email verification/logout/password change.
- Dashboard/student: `/dashboard` and student subpages.
- API: payment webhooks and guest booking API in `routes/api.php`.
- Admin preview routes are manually declared under `/admin`; Filament panel owns most admin routes.
- Console scheduler lives in `routes/console.php`.

Middleware:

- `EnsureAccountIsActive`
- `EnsureAdminPortal`
- `EnsureEmailVerifiedIfRequired`
- `EnsureFrontendPortal`
- `EnsureLoginEnabled`
- `EnsurePasswordChangeRequired`
- `EnsureRegistrationEnabled`
- `TrackUserSession`

Risk: `routes/web.php` includes inline closures with user lookups and status updates for email verification/resend. Move into controllers/actions later if Phase 1 hardens route architecture.

### 3.15 Frontend/Public UI Structure

Existing public UI:

- Layouts: `resources/views/layouts/frontend.blade.php`, `guest.blade.php`, `auth.blade.php`, `student.blade.php`, `account.blade.php`, `error.blade.php`, `landing.blade.php`, `page.blade.php`.
- UI components: `resources/views/components/ui/*` includes button, input, select, checkbox, radio, toggle, badge, card, alert, modal, drawer, tabs, accordion, tooltip, dropdown, avatar, breadcrumb, pagination, skeleton, empty state, spinner, etc.
- Frontend layout Livewire: site header/footer, announcement bar, cookie banner, search overlay.
- CMS block components: `resources/views/components/blocks/*`.
- Assets: `resources/css/frontend/theme.css`, `resources/css/app.css`, `resources/js/frontend/alpine.js`, `resources/js/app.js`.
- Public pages already present for blog, FAQ, forms, booking, instructors, profile, dashboard, student sections.

Do not introduce a SPA or second design system. Extend existing Blade components and Livewire components.

## 4. Duplicate Prevention Table

| Need | Existing asset to reuse | Do not recreate |
|---|---|---|
| Public pages/CMS | `Page`, `ContentBlock`, `PageRenderService`, block components | New `WebsitePage`, `LandingPage`, or hardcoded page renderer. |
| Blog/content | `Post`, `PostCategory`, `Tag`, `PostService` | New article/news tables unless requirements differ substantially. |
| Navigation/menu | `NavigationMenu`, `NavigationItem`, `NavigationManager`, link drivers | New static menu config or separate menu tables. |
| Users/students/instructors | `User`, `UserProfile`, roles, `InstructorService` | Separate `students`, `instructors`, `tutors` identity tables. |
| Instructor subjects/availability | `TeacherSubject`, `TeacherAvailability`, `TeacherUnavailability` | New tutor availability tables until naming migration is intentionally planned. |
| Booking | `app/Booking/*` | Second booking wizard/service/repository. |
| Payments | `BookingPaymentService`, `PaymentProviderRegistry`, payment settings | Direct gateway calls from controllers/resources. |
| Forms | `PublicFormService`, `PublicFormSubmission`, form Livewire components | One-off form handlers with direct mail logic. |
| Email | `TransactionalNotificationService`, `EmailLog`, Resend config | Direct `Mail::send`, duplicate notification log table. |
| Audit | `AuditTrailService`, Activity pipeline | Direct `activity()` in new business code. |
| Media | Spatie Media Library collections | File path columns for avatar/cover/documents. |
| Settings | Spatie Settings classes and settings migrations | New app settings table or config-only admin settings. |
| Auth/security | Auth services/actions/settings | Inline user status/password/security logic in controllers/Livewire. |
| Student dashboard | `app/Livewire/Frontend/Student/*`, `StudentBookingService`, `HomeworkService` | New dashboard data access in Blade/controllers. |
| Permissions | Spatie Permission + Shield config/seeders | Custom ACL table. |

## 5. Missing Foundation Checklist

| Item | Status | Notes |
|---|---|---|
| Domain modules | Present | Booking, CMS, Navigation, Forms, Homework, Mail, Profile, Auth/Security. |
| Base public frontend layout | Present | `resources/views/layouts/frontend.blade.php`. |
| Guest/auth/student layouts | Present | `guest.blade.php`, `auth.blade.php`, `student.blade.php`, `account.blade.php`. |
| Reusable UI components | Present | `resources/views/components/ui/*`. |
| CMS block rendering | Present | Page renderer + block components. |
| Navigation integration | Present | Header/footer/mobile Livewire components use `NavigationManager`. |
| Booking wizard | Present | `app/Livewire/Frontend/Booking/BookingWizard.php`. |
| Transactional email provider | Present | Resend + email logs. |
| Queue/database tables | Present | `jobs`, `failed_jobs` via framework migration. |
| Media library | Present | `media` table + model collections. |
| Activity audit trail | Present | Activitylog + `AuditTrailService`, with some legacy direct calls. |
| Portal resolver | Present | Use for routing decisions. |
| Permission model | Present | Shield + Spatie Permission. |
| Field-level foundation gaps | Needs review per feature | Add migrations only when a specific field is missing from existing tables. |
| Dedicated repository layer outside bounded modules | Partial | Booking/Navigation/Forms/Homework have repositories; CMS/Profile/Auth mostly use services directly. Do not add generic repositories without need. |
| Naming cleanup for teacher vs instructor | Partial | Public UI is instructor-oriented, DB/domain still uses teacher names. Plan an intentional migration only if worth the churn. |

## 6. Reuse/Enhance Recommendations

1. Enhance `app/Services/Instructor/InstructorService.php` for instructor directory/profile/subjects/availability features; do not create `TutorService` or direct user queries in controllers.
2. Enhance `app/Booking/Services/*` and `app/Booking/Contracts/*` for booking/payment changes; register new providers in `PaymentProviderRegistry`.
3. Enhance `app/Forms/Services/PublicFormService.php` for new public forms; do not add controller-specific persistence/email logic.
4. Enhance `app/Navigation/Services/*` for menu behavior, including admin builder search/lookups currently in `app/Livewire/Navigation/MenuBuilder.php`.
5. Enhance `app/Services/PageRenderService.php`, `app/Services/BlockRenderer.php`, and `resources/views/components/blocks/*` for CMS block output.
6. Enhance `app/Forms/Blocks/*BlockForm.php` for new block editor fields.
7. Enhance `app/Settings/*` and `database/settings/*` for configurable behavior.
8. Enhance `AuditTrailService` adoption before adding more auditing; new business code should never call `activity()` directly.
9. Enhance `resources/views/components/ui/*` for design system gaps; do not add a second component folder for the same primitives.
10. Enhance existing policies to align with `docs/standards.md` by replacing `$user->can()` inside policies with `hasPermissionTo()` plus exception handling.

## 7. Implementation Priority

1. Stabilize naming and boundaries: document instructor/teacher legacy naming and decide whether to leave DB names as-is for Phase 1.
2. Harden UI-layer boundaries: move direct queries from `app/Livewire/Navigation/MenuBuilder.php` and route closures into services/actions.
3. Normalize policy permission checks to the documented standard.
4. Replace remaining raw `activity()` calls in services/controllers/resources with `AuditTrailService`.
5. Add only missing fields to existing tables after confirming requirements against migrations/models.
6. Continue expanding frontend/CMS blocks using existing block forms/components/rendering pipeline.
7. Extend booking/payment provider support through the existing registry and settings.
8. Add targeted tests per module using the existing test structure.

## 8. Risks

| Risk | Evidence | Recommendation |
|---|---|---|
| Business logic in routes | Email verification closures in `routes/web.php` query/update `User`. | Move to controller/action/service when touching auth flow. |
| Direct queries in Livewire | `app/Livewire/Navigation/MenuBuilder.php` queries Page/Post/Category/Tag/NavigationItem directly. | Add Navigation repository/service methods. |
| Direct queries in controllers | `ContactFormController`, `PostController`, `CategoryController`, `TagController`, `SeoController`, auth controllers use model queries. | Prioritize service extraction for new work; avoid expanding query logic. |
| Raw activity helper in business/UI code | Multiple `activity()` calls outside `AuditTrailService`. | Migrate gradually to `AuditTrailService`. |
| Policies not matching documented standard | Several policies use `$user->can(...)`. | Normalize to `hasPermissionTo()` and catch missing permissions. |
| Teacher vs instructor naming | DB/domain files use `Teacher*`; public routes/views use instructors. | Do not duplicate. Either accept internal teacher naming or plan a separate rename migration. |
| Role seed inconsistency | `SuperAdminSeeder` comments mention ID 1 while portal rules say role name is authoritative. | Update comments/docs later; keep logic role-name based. |
| Filament pages with direct saves | Settings pages save settings directly, some use services. | Keep settings-specific pattern but ensure Gate checks and audit logging. |
| Widget direct queries | Widgets such as `StatsOverviewWidget`, `RecentUsersWidget`, `BookingStatsWidget` query directly. | Accept for read-only dashboard summary or introduce dashboard query services if logic grows. |
| Backup/stale view file | `resources/views/profile/show.blade.php.bak` exists. | Remove in a cleanup phase if not needed. |

## 9. Next Phase Prompt Recommendation

Use this as the next implementation prompt:

> Continue Phase 1 Foundation using the inventory in `docs/architecture/phase-1-foundation-inventory.md`.
>
> Do not recreate existing modules, tables, models, services, settings, navigation, CMS blocks, booking logic, notification/email logging, or UI components.
>
> Implement the next feature by enhancing the existing service/repository/action boundaries. Keep controllers, Filament resources, and Livewire components thin. Use `PortalResolver` for portal routing decisions, `AuditTrailService` for audit logging, Spatie Settings for configurable values, Spatie Media Library for uploads, existing `resources/views/components/ui/*` for UI primitives, and existing tests as the structure for new coverage.
>
> Before coding, identify the exact existing files to enhance and list any migration only if a required field is missing from the existing schema.
