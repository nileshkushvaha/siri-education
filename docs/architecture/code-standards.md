# Code Standards

## Purpose

These standards define how new code should be written in this Laravel 13 modular monolith, making the current architecture rules explicit for anyone adding to it.

If current code differs from these standards, do not rewrite it wholesale. Align gradually when touching the relevant file.

## Folder Structure

### Existing Top-Level Application Structure

| Concern | Preferred location |
|---|---|
| Domain actions | `app/{Domain}/Actions` or `app/Actions/{Domain}` for existing cross-cutting modules. |
| Domain services | `app/{Domain}/Services` or `app/Services/{Domain}` matching current domain placement. |
| Repositories | `app/{Domain}/Repositories` when the domain already has repository contracts. |
| Contracts | `app/{Domain}/Contracts`. |
| DTOs | `app/{Domain}/DTOs` or `app/DTOs` for cross-cutting DTOs. |
| Enums | `app/{Domain}/Enums` or `app/Enums`. |
| Events | `app/{Domain}/Events` or `app/Events/{Domain}`. |
| Listeners | `app/Listeners/{Domain}`. |
| Policies | `app/Policies`. |
| Settings | `app/Settings`; defaults in `database/settings`. |
| Filament resources | `app/Filament/Resources/{Name}`. |
| Filament pages | `app/Filament/Pages/{Area}`. |
| Livewire frontend components | `app/Livewire/Frontend/{Area}` and matching `resources/views/livewire/frontend/{area}`. |
| Blade UI components | `resources/views/components/ui`. |
| CMS block components | `resources/views/components/blocks`. |
| Tests | Mirror existing `tests/Feature/{Domain}` or `tests/Unit/{Domain}` folders. |

### Domain Placement Rule

Follow the domain’s current structure. For example:

- Booking code belongs under `app/Booking`.
- Navigation code belongs under `app/Navigation`.
- Forms code belongs under `app/Forms`.
- Homework code belongs under `app/Homework`.
- Profile code currently belongs under `app/Services/Profile` and `app/Actions/Profile`.

Do not move a whole domain just to make it look more uniform.

## Naming Conventions

| Type | Pattern | Example |
|---|---|---|
| Service | `{Domain}Service` | `InstructorService`, `SecuritySettingsService`. |
| Interface | `{Domain}ServiceInterface`, `{Domain}RepositoryInterface` | `BookingServiceInterface`. |
| Repository | `{Domain}Repository` | `BookingRepository`. |
| Action | `{Verb}{Noun}Action` | `RegisterUserAction`. |
| DTO | `{Noun}Data` or `{Noun}Result` | `CreateBookingData`, `RecurringBookingResult`. |
| Enum | Singular concept name | `BookingStatus`, `PageStatus`. |
| Event | Past-tense domain event | `BookingConfirmed`, `UserRegistered`. |
| Listener | Imperative action | `SendBookingNotifications`, `RecordBookingLifecycleAudit`. |
| Policy | `{Model}Policy` | `BookingPolicy`. |
| Settings | `{Domain}Settings` | `BookingSettings`. |
| Settings group | `{domain}_{sub}` | `security_auth`, `payment_bank`. |
| Filament resource | `{Model}Resource` | `BookingResource`. |
| Livewire component | Noun or workflow name | `BookingWizard`, `SiteHeader`. |
| Gate ability | `{domain}.{resource}.{action}` | `security.authentication.update`. |

Use existing internal names where already established. Example: do not introduce `TutorAvailability` while the current model is `TeacherAvailability`.

## PHP Standards

- Use `declare(strict_types=1);` for new PHP files.
- Prefer `final` for services, actions, listeners, notifications, requests, and DTO-like classes.
- Do not mark Eloquent models `final`.
- Prefer constructor property promotion for dependencies.
- Prefer `readonly` dependencies where mutation is not needed.
- Use named arguments for long or ambiguous method calls.
- Avoid inline `app(Foo::class)` except in static contexts, Filament closures, enum helpers, or framework callbacks where injection is unavailable.
- Do not add large business logic to models, controllers, Livewire components, or Filament resources.

## Service Interface Pattern

Use interfaces when a domain already uses them or when multiple implementations are realistic.

Existing examples:

- `app/Booking/Contracts/BookingServiceInterface.php`
- `app/Booking/Contracts/BookingRepositoryInterface.php`
- `app/Forms/Contracts/PublicFormServiceInterface.php`
- `app/Homework/Contracts/HomeworkServiceInterface.php`
- `app/Navigation/Contracts/NavigationRepositoryInterface.php`

Pattern:

```php
interface ExampleServiceInterface
{
    public function execute(ExampleData $data): ExampleResult;
}

final class ExampleService implements ExampleServiceInterface
{
    public function __construct(
        private readonly ExampleRepositoryInterface $repository,
    ) {}
}
```

Register bindings in the domain provider, not ad hoc in controllers.

Do not create interfaces for every tiny service if the surrounding domain does not use interface-based bindings.

## DTO Pattern

DTOs should be immutable input/output carriers for services/actions.

Rules:

- Use constructor-promoted `public readonly` properties.
- Keep DTOs free of database queries.
- Add factory helpers only when they reduce controller/request mapping duplication.
- Use typed properties and enums where possible.

Existing examples:

- `app/Booking/DTOs/CreateBookingData.php`
- `app/Booking/DTOs/GuestBookingData.php`
- `app/Booking/DTOs/TimeSlotData.php`
- `app/DTOs/NotificationPayload.php`

## Enum Pattern

Use enums for finite domain states or option sets.

Rules:

- Use backed enums for persisted values.
- Keep labels/colors/icons in enum methods only when they are presentation metadata already established by the domain.
- Do not use enums for admin-managed master data that requires CRUD, ordering, localization, or metadata.

Existing examples:

- `app/Booking/Enums/BookingStatus.php`
- `app/Enums/PageStatus.php`
- `app/Enums/InstructorStatus.php`
- `app/Forms/Enums/PublicFormType.php`

## Policy Pattern

Access control belongs in policies and gates.

Rules:

- `Gate::before()` in `AppServiceProvider` owns global super-admin bypass.
- Do not duplicate super-admin logic in policies.
- Prefer `$user->hasPermissionTo()` in policies.
- Catch `Spatie\Permission\Exceptions\PermissionDoesNotExist` where permissions may not exist in all environments.
- Do not use portal selection to decide authorization.
- Do not use `$user->can()` inside policies for new code because it can recurse through Gate.

Pattern:

```php
private function hasPermission(User $user, string $permission): bool
{
    try {
        return $user->hasPermissionTo($permission);
    } catch (PermissionDoesNotExist) {
        return false;
    }
}
```

Current alignment note: some existing policies still call `$user->can(...)`. Replace gradually when touching those policies.

## Password Validation

`PasswordRuleBuilder` (`app/Services/Security/PasswordRuleBuilder.php`) is the single source of truth for password validation rules — it reads the admin-configured password policy. Always use it instead of building a `Password::min()` chain inline:

```php
// Correct — respects the admin-configured policy
'password' => [app(PasswordRuleBuilder::class)->build()],

// Wrong — ignores admin configuration
'password' => [Password::min(8)->mixedCase()->numbers()],
```

## Event and Listener Pattern

Use events for domain lifecycle moments and listeners for cross-domain reactions.

Rules:

- Events describe what happened.
- Listeners do the side-effect.
- Expensive listeners should implement `ShouldQueue`.
- Register new listeners in `app/Providers/EventServiceProvider.php` because event discovery is disabled.
- Do not send mail, write audit logs, or call payment sync code directly from UI layers when a domain event exists.

Pattern:

```php
final class BookingConfirmed
{
    public function __construct(public readonly Booking $booking) {}
}

final class SendBookingConfirmation implements ShouldQueue
{
    public string $queue = 'notifications';

    public function handle(BookingConfirmed $event): void
    {
        // delegate to notification/service
    }
}
```

## Migration Safety Rules

- Never modify vendor/package migrations.
- Never recreate an existing concept under a new table name.
- Check existing migrations before adding fields.
- Use additive migrations for schema changes.
- Use settings migrations in `database/settings` for Spatie Settings defaults.
- Use nullable columns or backfills carefully when production data may exist.
- Do not use destructive migration operations without an explicit data migration plan.
- Do not rely on role IDs in migrations/seeders.
- Keep backfills idempotent.
- Tests that use `RefreshDatabase` must run only in the testing environment.

Before adding a table, document why existing tables, settings, enums, media collections, and JSON fields are insufficient.

## Test Requirements

Every meaningful change needs focused tests.

Recommended test placement:

- Domain behavior: `tests/Feature/{Domain}` or `tests/Unit/{Domain}`.
- Services with pure logic: `tests/Unit/Services` or domain unit folder.
- Filament resource behavior: `tests/Feature/Filament` or module-specific feature folder.
- Livewire interactions: feature tests using Livewire test helpers.
- Policies: feature/authorization tests.
- Routes/controllers: feature tests.
- Migrations/settings: feature tests when behavior depends on schema/config.

Minimum expectations:

- Test service behavior, not just controller HTTP status.
- Test authorization for admin and non-admin where relevant.
- Test events/listeners when cross-domain side effects matter.
- Test queue/notification dispatch with fakes where appropriate.
- Avoid brittle tests that assert implementation details not visible to the domain.

Filament/Livewire-specific gotchas:

- A field with `.visible(fn ($get) => ...)` is excluded from `getState()` when hidden. In tests, always set the controlling toggle before setting the dependent field:

  ```php
  Livewire::test(PasswordPolicyPage::class)
      ->set('data.expiry_enabled', true)   // must come first
      ->set('data.expiry_days', 60)
      ->call('save');
  ```
- Settings tests must call `$this->artisan('migrate', ['--path' => 'database/settings'])` in `setUp()` — the `settings:migrate` artisan command does not exist in this project.
- After a settings `save()` in a test, call `$settings->refresh()` to read the value back from the DB rather than trusting in-memory state.
- Always `actingAs($this->superAdmin)` before a Filament Livewire test.

## Filament Resource Rules

Filament resources must stay thin.

Rules:

- Resource classes define metadata and delegate form/table to schema/table classes.
- Form schemas live in `Schemas`.
- Table schemas live in `Tables`.
- Non-resource pages use `content(Schema $schema)`, not `form(Form $form)` — Filament v5's `Schema` class replaces `Form` for standalone pages. See any Security page for the current pattern.
- Page classes should call services/actions for lifecycle work.
- Do not put business rules in table actions.
- Use policies for resource access.
- Use settings services for settings saves when such service exists.
- Use `Gate::authorize()` at the start of settings/security saves.
- Avoid direct `activity()` calls in Filament; use `AuditTrailService` when touching audit behavior.

Preferred structure:

```text
app/Filament/Resources/{Name}/
├── {Name}Resource.php
├── Pages/
├── Schemas/
└── Tables/
```

Current alignment note: some existing Filament pages/resources still perform direct updates or raw activity logging. Treat them as gradual cleanup targets, not templates for new code.

## Livewire Component Rules

Livewire components must stay interaction-focused.

Rules:

- Public properties represent UI state.
- Validation may live in the component.
- Business workflows must call services/actions.
- Do not directly query models for business data if a service/repository exists.
- Do not send mail directly.
- Do not write audit logs directly.
- Do not make portal/role decisions directly; use policies, gates, middleware, or `PortalResolver`.
- Use loading states, accessibility attributes, and responsive Blade views for public components.

Existing acceptable patterns:

- `app/Livewire/Frontend/Booking/BookingWizard.php` delegates booking operations to `BookingWizardService`.
- Layout components use `NavigationManager`.
- Form components delegate persistence to form services.

Current alignment note: `app/Livewire/Navigation/MenuBuilder.php` contains direct lookup queries and should be aligned gradually through navigation services.

## Controller Rules

Controllers should:

- Accept requests.
- Use FormRequests where available.
- Delegate to actions/services.
- Return redirects, views, or resources.
- Avoid model query chains for business workflows.
- Avoid sending notifications/mail directly.
- Avoid raw `activity()` calls.

Route closures should be limited to trivial views/redirects. If a closure queries or mutates models, move it to a controller/action in a cleanup phase.

## Repository Rules

Repositories are already used in Booking, Navigation, Forms, and Homework.

Rules:

- Put database query composition in repositories for domains that use repositories.
- Do not add generic repositories for every model by default.
- Do not bypass repositories from UI layers in repository-backed domains.
- Keep repositories free of business decisions.

## Settings Rules

- Configurable business behavior belongs in Spatie Settings.
- Settings classes live in `app/Settings`.
- Defaults live in `database/settings`.
- Filament settings pages live in `app/Filament/Pages/Settings` or `Security`.
- Secrets should remain in `.env` or be encrypted where persisted.
- Do not hardcode sender addresses, payment behavior, booking windows, captcha flags, or security thresholds.

## Media Rules

- Use Spatie Media Library.
- Define collections on the owning model.
- Validate collection MIME types.
- Use `singleFile()` for avatar/cover/single-document use cases.
- Do not add file path columns for media-owned concepts.

## Activity and Notification Rules

- Use `AuditTrailService` for business audit logs.
- Use Activity Log pipeline for admin database notifications.
- Use queued Laravel notifications for transactional email.
- Use `TransactionalNotificationService` for on-demand mail routing.
- Use `EmailLogService` and Resend webhooks for provider status.
- Do not send admin notifications directly from services.

## Frontend Rules

- Public frontend is Blade + Livewire + Alpine, not a separate SPA.
- Use `resources/views/layouts/frontend.blade.php` for public pages.
- Use existing UI components in `resources/views/components/ui`.
- Use existing block components for CMS-rendered sections.
- Do not hardcode CMS content into pages when CMS content exists.
- Keep accessibility, SEO, and responsive behavior mandatory.

## Gradual Alignment Policy

When existing code does not match this standard:

1. Do not rewrite unrelated code.
2. Keep the feature change small.
3. Improve the touched boundary only if it reduces risk.
4. Add tests around the behavior being preserved.
5. Document larger cleanup as a follow-up.

