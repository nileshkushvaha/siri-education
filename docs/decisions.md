# Architectural Decisions

Permanent decisions. Do not reverse without updating this file.

---

Content Blocks replace Page Blocks and Post Blocks. All content lives in `content_blocks`.

Users are Authors. No separate Author model.

Navigation uses Kalnoy NestedSet. Never a raw adjacency list.

Activity Log is the Audit Trail. One unified `activity_log` table, three actor types: User, Guest, System.

Notifications are generated from the Activity Log pipeline — never directly from Services.

Settings use Spatie Laravel Settings. Never store settings in `.env` or `config/` alone.

Filament Resources remain thin. Form schemas and table definitions live in `Schemas/` and `Tables/` subdirectories.

Business logic belongs in Services. Controllers and Filament pages only orchestrate.

Repositories handle database access. Services do not write raw Eloquent queries.

Policies handle authorization. Never inline `Gate::check()` inside models.

Queue long-running tasks. Notifications, emails, and webhook processing are queued.

UUIDs are used as primary keys on Pages and Posts.

Content rendering uses centralized Render Services. `PageRenderService` extends `ContentRenderer`.

SEO priority: Page/Post fields → Global SEO Settings → Defaults.

`PasswordRuleBuilder` is the single source of truth for password validation. Never build `Password::min()` chains inline.

Security settings pages route all saves through `SecuritySettingsService`, which logs field-level diffs.

`super_admin` has unrestricted access via `Gate::before()` in `AppServiceProvider`. Never replicate this in individual policies.

Instructor onboarding review lives on `InstructorOnboardingResource`, never on the general `UserResource`. Shared code (`HasInstructorLifecycleActions`, `InstructorOnboardingForm`) is reused between them, not duplicated.
