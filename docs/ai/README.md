# AI Platform (P0 Foundation)

Status: **AI Release 1 complete (P0-P4), plus AI-E0 production
evaluation. Every AI feature ships disabled by default.** The AI layer is configurable, tested and off.
Capabilities built on it: Admin Quality Intelligence
([`features/quality-insights.md`](features/quality-insights.md)), the
Instructor Homework Copilot
([`features/homework-copilot.md`](features/homework-copilot.md)),
AI Lesson Summaries
([`features/lesson-summary.md`](features/lesson-summary.md)) and
Communication Safety
([`features/communication-safety.md`](features/communication-safety.md)).

## Purpose

Provide a provider-neutral AI layer so future AI capabilities can be
added without redesigning architecture, and so no business module ever
depends on a specific AI vendor.

## Architecture

```
Domain feature (P1-P4)
        │
        ▼
AiExecutionServiceInterface        ← the only entry point business code may use
        │
        ├── AiFeatureGate          module switch → capability flag → credential
        ├── AiBudgetGuard          daily / monthly estimated-spend ceilings
        ├── AiPromptRegistry       versioned, frozen prompts
        ├── AiProviderResolver     settings → adapter, capability-checked
        ├── AiModelResolver        model ROLE → configured model name
        ├── StructuredOutputValidator
        ├── AiRunRecorder          ai_runs telemetry
        └── AiLogger               allowlisted log fields only
        │
        ▼
AI capability contracts (app/Ai/Contracts)
        │
        ├── TextGenerationProviderInterface
        ├── StructuredGenerationProviderInterface
        ├── ModerationProviderInterface
        └── EmbeddingProviderInterface
        │
        ├──────────────┬──────────────────────┐
        ▼              ▼                      ▼
   OpenAiProvider  FakeAiProvider     future: Gemini, Claude
```

### Module layout

| Concern | Location |
|---|---|
| Contracts | `app/Ai/Contracts` |
| DTOs | `app/Ai/DTOs` |
| Enums | `app/Ai/Enums` |
| Exceptions | `app/Ai/Exceptions` |
| Provider adapters | `app/Ai/Providers/{OpenAi,Fake}` |
| Provider registry | `app/Ai/Registry` |
| Prompts | `app/Ai/Prompts` |
| Schemas + validator | `app/Ai/Schemas` |
| Services | `app/Ai/Services` |
| Queue job | `app/Ai/Jobs/ExecuteAiTaskJob.php` |
| Repository | `app/Ai/Repositories` |
| Run record | `app/Models/AiRun.php` |
| Settings | `app/Settings/AiSettings.php`, `FeatureSettings::$ai_enabled` |
| Admin page | `app/Filament/Pages/Settings/AiSettingsPage.php` |
| Wiring | `app/Providers/AiServiceProvider.php` |
| Tests | `tests/Feature/Ai` |

Features built on the foundation live in their own domain, never in
`app/Ai`:

| Feature | Domain | Doc |
|---|---|---|
| P1 Admin Quality Intelligence | `app/Quality/Intelligence` | `features/quality-insights.md` |
| P2 Instructor Homework Copilot | `app/Homework/Copilot` | `features/homework-copilot.md` |
| P3 AI Lesson Summaries | `app/Lessons/Summaries` | `features/lesson-summary.md` |
| P4 Communication Safety | `app/Messaging/Safety` | `features/communication-safety.md` |

Measurement and governance for all of the above — not a feature —
lives in [`evaluation.md`](evaluation.md) (AI-E0): reviewer feedback,
per-feature acceptance and cost, prompt-version comparison, the AI
Evaluation dashboard, and budget alerting.

## Provider strategy

- OpenAI is the first adapter, not the architecture. Everything above
  `app/Ai/Contracts` is vendor-agnostic.
- `OpenAiHttpClient` is the only class that ever sends an OpenAI request
  or holds the decrypted key. `OpenAiProvider` is the only class that
  knows OpenAI's request/response shapes.
- Adding a provider: implement the capability interfaces it supports,
  register it in `AiServiceProvider`, select it in settings. No business
  module changes. `AiArchitectureTest` fails the build if any code
  outside the adapter folder names OpenAI.
- `FakeAiProvider` is the shipped default and performs no network call —
  staging can exercise the whole pipeline at zero cost.

## Security rules

| Rule | Mechanism |
|---|---|
| Credentials encrypted at rest | `Crypt::encryptString()` on save (`AiSettingsPage`) |
| Decrypted in exactly one place | `AiCredentialStore` — enforced by test |
| Never redisplayed | field starts blank on mount; blank submit keeps stored value |
| Never in a Livewire payload | proven by `AiSettingsSecurityTest` |
| Never in the audit trail | `LogsSettingsUpdates` records `set`/`replaced`/`cleared` only |
| Never in logs | `AiLogger` allowlists context keys; no `Log::` calls in `app/Ai` |
| Never in exceptions | `RedactsProviderMessages` strips key-shaped runs and quoted spans |
| Never in config cache | settings live in the DB, never in `config/` or `.env` |

### Content discipline

SIRI processes homework, messages and lesson notes belonging to minors.
The AI layer therefore **stores no prompt and no response, anywhere**:

- `ai_runs` has no content column — only identifiers, counters and
  outcomes. Enforced by test.
- Queue payloads carry an `AiTaskDescriptor` (identifiers only). Content
  is fetched at execution time by an `AiTaskInputResolverInterface`
  implementation that lives in the owning domain.
- `AiLogger` cannot log content: unknown keys and non-scalar values are
  dropped.
- Provider error messages are redacted before they become an exception,
  because providers quote the offending input back.

## Shared redaction

`App\Ai\Support\AiTextRedactor` is the platform-wide floor applied to
any user-written text before it reaches a provider: contact patterns,
known-participant name redaction, residual digit runs, and a length cap.

It is deliberately **not** a general-purpose "make this safe" service.
The split that matters:

| | Owns |
|---|---|
| Domain | which fields may travel, WHO the participants are, how much content is appropriate |
| `AiTextRedactor` | enforcing the floor on whatever it is handed |

Known-name redaction is the layer that actually works — no pattern can
recognise a name, but the domain knows exactly whose record this is and
passes the names in. A caller supplying no hints gets pattern scrubbing
only, which is a materially weaker guarantee. The redactor takes name
STRINGS, never User models, so `app/Ai` stays free of domain models.

The consequence, stated plainly: for short third-party text (P1 review
excerpts) redaction is close to sufficient; for a student's own extended
writing (P2 homework) it is not, and the protection is posture —
instructor-initiated, one record at a time, never stored. See each
feature's doc.

## Human initiation, and the one exception

P1-P3 all require a person to press a button before any content leaves
the platform. That human choice is a privacy control, not a UX detail:
it is what makes "content only travels because someone decided it
should" literally true, and it is enforced by architecture tests that
forbid those domains from dispatching AI work from a listener, observer
or scheduled command.

**P4 is the sanctioned exception.** A safety check that runs only when
asked is not a safety check, so message analysis is triggered by the
`MessageSent` event. Its compensating controls are documented in
`features/communication-safety.md`: deterministic rules answer the
obvious cases for free, a narrow triage gate means the overwhelming
majority of messages are never eligible for analysis at all, and the
input is one message with no history and no identities.

Any future phase proposing automatic analysis should be held to the same
bar: what stops this from becoming blanket surveillance, and can you
test it?

## AI safety rules

AI **may**: summarize, classify, suggest, draft, recommend.

AI **may never**: approve KYC, issue refunds, modify a wallet, change a
payment, suspend a user, alter instructor compensation, or complete any
financial action.

Enforcement, not just policy:

- `AiExecutionService` returns data; it never writes business state.
- `app/Ai` may not import a financial or lifecycle namespace, and may not
  import any model other than `AiRun` — asserted by `AiArchitectureTest`.
- No `Execute`/`Approve` AI permission exists; an AI suggestion is acted
  on through the owning domain's existing permissions and review path.
- Structured output is validated before any application service sees it.

## AI execution flow

```
AiTaskRequest
   → feature gate      (module switch, capability flag, credential)
   → budget guard      (daily / monthly estimated spend)
   → prompt registry   (key + version → frozen template)
   → provider resolver (settings → adapter, capability-checked)
   → model resolver    (role → configured model)
   → ai_runs row created (status: running)
   → provider call     (latency measured)
   → schema validation (structured capabilities only)
   → ai_runs completed (tokens, estimated cost, latency, request id)
   → AiTaskResult
```

Failures never throw out of `execute()`. An unavailable AI feature is a
normal operating condition, so callers receive a failed `AiTaskResult`
and make an explicit decision.

Statuses: `blocked` (refused before any provider call), `running`,
`succeeded`, `rejected` (response failed schema — tokens still spent),
`failed`.

## Prompt versioning

- Prompts are code: registered in `AiPromptCatalog`, never stored in the
  database, never inlined in a service.
- Versions are additive and frozen. New wording means a new version, so
  `prompt_key:prompt_version` on a run row always identifies the exact
  text that produced it.
- Templates use `{{ variable }}` substitution only — no expression
  evaluation, because variable values are user content.
- `AiPromptCatalog` holds infrastructure prompts only — currently just
  `platform_connectivity_check:v1` (diagnostics, no variables). **Feature
  prompts are registered by their owning domain's service provider**, so
  the AI module never learns that a feature exists. `quality_insight:v1`
  is registered by `QualityServiceProvider` (P1), `homework_feedback:v1`
  by `HomeworkServiceProvider` (P2), `lesson_summary:v1` by
  `LessonServiceProvider` (P3), and `communication_risk:v1` +
  `message_moderation:v1` by `MessagingServiceProvider` (P4).
- `AiExecutionServiceTest` asserts the registry contains EXACTLY these
  six prompts, so a new one cannot appear unnoticed.

## Structured output

Free-form text is reserved for prose a human will read and edit.
Anything that feeds a decision or a persisted record uses structured
generation:

1. the schema is sent to the provider (constrains decoding);
2. the response is **re-validated locally** with Laravel validation —
   the local rules are authoritative;
3. only validated data is returned; undeclared keys are dropped, so a
   hallucinated field can never reach a DTO.

Invalid output is rejected, recorded as `rejected` with its token cost,
and retried only within the job's bounded budget.

## Cost controls

- Every run records `input_tokens`, `output_tokens`, `estimated_cost`,
  `model`, `feature_key` and `requested_by`.
- Pricing is admin-maintained (`AiSettings::$model_pricing`, per 1M
  tokens as `"input/output"`). An unpriced model estimates 0.0 and is
  reported as unpriced rather than guessed.
- `AiBudgetGuard` enforces daily and monthly ceilings before every run.
  Null = unlimited; 0 = stop all AI spend.
- Estimates are for budgeting, never billing. Keep the provider's own
  spend limits configured as the last line of defence.
- Per-feature and per-user ceilings need no new architecture — the run
  row already carries both dimensions.

## Queue architecture

- Dedicated `ai` connection and queue (`config/queue.php`);
  `retry_after` (180s) exceeds `ExecuteAiTaskJob::$timeout` (120s), so a
  slow generation is never double-dispatched.
- `ExecuteAiTaskJob` is the one reusable job: `tries = 3`, backoff
  `[30, 120]`, retrying only failure codes classified transient.
- A descriptor may name an `AiTaskResultHandlerInterface` implementation
  (in the owning domain) plus a `correlationId` — the domain record
  awaiting the answer. The handler is called on EVERY terminal outcome,
  success or failure, so a waiting record never sits Pending forever. A
  handler that throws never resurrects the job: the AI work is already
  paid for, and retrying would spend again to fix a domain-side bug.
- Run a dedicated worker:
  `php artisan queue:work ai --queue=ai`.
- No AI call ever runs inside a Livewire round trip or controller.

## Logging

`storage/logs/ai.log` (daily, channel `ai`), written only through
`AiLogger`.

Allowed: run id, feature, provider, model, prompt key/version, status,
failure code, latency, token counts, estimated cost, provider request
id, attempt.

Forbidden by construction: API keys, prompts, responses, student
content — unknown keys are dropped rather than redacted.

## Admin configuration

**Settings → Platform → AI Platform** (`/admin/settings/ai`).

| Setting | Default |
|---|---|
| `FeatureSettings::ai_enabled` | `false` |
| `ai.provider` | `fake` |
| `ai.openai_api_key` | none (encrypted when set) |
| `ai.generation_model` / `fast_model` | `gpt-4.1` / `gpt-4.1-mini` |
| `ai.embedding_model` / `moderation_model` | `text-embedding-3-small` / `omni-moderation-latest` |
| `ai.request_timeout_seconds` | `30` |
| capability flags (×4) | `false` |
| `ai.daily_cost_limit` / `monthly_cost_limit` | `5.0` / `100.0` USD |

Permissions (`AiPlatformPermissionSeeder`): `Configure:AiPlatform`,
`TestConnection:AiPlatform`. There is deliberately no "execute AI"
permission — an AI suggestion is acted on through the owning domain's
existing permissions.

## Feature rollout plan

1. Configure the API key on the settings page; leave the module off.
2. Turn the module on with `provider = fake` and confirm runs are
   recorded and blocked/succeeded as expected.
3. Switch `provider` to `openai`; use **Test connection** (sends no
   prompt, no content).
4. Ship the phase's prompt, schema and input resolver.
5. Enable that phase's capability flag alone; watch `ai_runs` and
   `storage/logs/ai.log` for failure codes and spend.
6. Raise budgets only after observing real per-run cost.

## AI Release 1 (P0-P4) — all shipped

| Phase | Capability | Flag | What it must add |
|---|---|---|---|
| ~~P1~~ | ~~Admin Quality Intelligence~~ | `quality_insights_enabled` | **Shipped** — see `features/quality-insights.md` |
| ~~P2~~ | ~~Instructor Homework Copilot~~ | `homework_assistant_enabled` | **Shipped** — see `features/homework-copilot.md` |
| ~~P3~~ | ~~Lesson Summary Generation~~ | `lesson_summary_enabled` | **Shipped** — see `features/lesson-summary.md`. Note: instructor-initiated, deliberately NOT dispatched from a lesson event |
| ~~P4~~ | ~~Communication Safety & Moderation~~ | `communication_moderation_enabled` | **Shipped** — see `features/communication-safety.md`. Flagging only; the one phase with automatic (non-human-initiated) analysis |

Each phase added a prompt, a schema, an input resolver and a result
handler in its own domain, plus an application service that decides what
to do with validated output. **None of them modified `app/Ai`** — the
only change to the foundation across four features was the result-
handler seam added in P1, which every phase since has reused.

The next step is not another feature. It is an evaluation phase:
measure real cost per run, collect reviewer feedback on whether the
output was useful, tune prompts against that evidence, and decide which
of the four deserve scaling. Every feature already records what that
needs — `ai_runs` carries feature, model, prompt version, tokens, cost
and latency, and each domain records what a human did with the output.

## Explicitly out of scope in P0

Chatbot, RAG, vector database/embeddings storage, AI tutor, homework
grading, lesson summaries, moderation rules, transcription, recording
AI, autonomous AI actions.

## Tests

`tests/Feature/Ai/` — execution and gating, OpenAI adapter and failure
classification, credential security, queue behaviour, usage/cost
tracking, and the architecture guarantees above.

`tests/Feature/Quality/Intelligence/` — the P1 feature: privacy
boundary, generation lifecycle, authorization, and the guarantee that an
AI insight cannot reach an operational or financial pathway.

`tests/Feature/Homework/Copilot/` — the P2 feature: privacy boundary,
draft lifecycle, authorization, and the guarantees that no AI path can
grade, publish, or run without an instructor asking.

`tests/Feature/Lessons/Summaries/` — the P3 feature: privacy boundary
(including that no recording data is ever read), summary lifecycle,
authorization, and the guarantees that no AI path can complete a lesson
or move learning-plan progress.

`tests/Feature/Ai/Evaluation/` — AI-E0: feedback recording and its
privacy constraints, evaluation aggregation, dashboard access, and
budget alerting.

`tests/Feature/Messaging/Safety/` — the P4 feature: the triage gate,
the privacy boundary, the soft warning's no-provider/no-record/no-block
properties, the finding lifecycle, and the guarantees that no AI path
can restrict a user or open a compliance flag.
