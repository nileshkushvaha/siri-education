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

AI is reached only through `AiExecutionServiceInterface`. No controller, Livewire component, Filament page, service, or job outside `app/Ai` may call an AI provider, name a provider class, or hardcode a model name. Providers sit behind capability contracts; models are configured as roles in `AiSettings`.

The AI layer never persists prompts, responses, or embeddings. `ai_runs` stores operational metadata only, and queued AI jobs carry identifiers, never content.

AI output never modifies business state on its own. It is validated against a registered schema, returned to an application service, and subject to that domain's existing rules, permissions, and human review.



AI quality insights are advisory and admin-only. An AI insight never changes instructor status, compensation, ratings, reviews, bookings, or any financial record, and nothing in the platform reads a stored insight to decide anything. Instructors and students never see them.

Feature prompts and schemas are registered by their owning domain's service provider, never by `AiPromptCatalog`. The AI module never learns that a feature exists.

AI never grades. The Homework Copilot drafts feedback only; `homework_assignments.grade` and `.feedback` are written exclusively by the instructor review flow, and no AI schema carries a score, mark, or pass/fail property.

AI drafting of student work is instructor-initiated only — one submission at a time, by the tutor who can already read it. No listener, observer, scheduled command, or submission hook may send student work to a provider.

AI never decides student progress. Lesson summaries draft documentation only; lesson status, outcome, completion notes, learning-plan progress and milestones are written exclusively by their owning domains, and no AI schema carries a mastery, level, or progress property.

AI features never read class recordings, transcripts, or meeting artifacts. Recording intelligence is a separate phase with its own consent and retention design.

AI never enforces. Communication-safety findings are evidence for human review: no AI path blocks, hides or alters a message, restricts or suspends a user, or opens a compliance flag. Only findings an administrator has confirmed can escalate, and only through a deterministic threshold rule.

Contact and payment-bypass detection is deterministic first. `LeakageDetector` is the single detector; AI is used only for intent that no pattern can express, and only for messages that pattern rules did not already explain.

Automatic (non-human-initiated) AI analysis is permitted only for communication safety, and only with a triage gate that keeps the overwhelming majority of messages from ever reaching a provider.

AI evaluation derives outcomes from each feature's own records and never duplicates them. The only evaluation-specific storage is `ai_feedback_events`, which holds an explicit reviewer verdict, carries no content, no free text and no subject reference, and is attached to the AI run rather than to the person the run was about.

Frozen prompts are never edited in place. Improvement means registering a new version alongside the old one, so historical measurements of the old version stay meaningful.

