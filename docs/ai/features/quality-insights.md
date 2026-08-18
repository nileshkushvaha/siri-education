# AI Quality Insights (P1)

Status: **shipped, disabled by default.** Admin-only, advisory, and
incapable of acting on its own output.

> Help administrators understand instructor quality signals faster,
> while keeping humans fully responsible for decisions.

## What it is

An administrator picks an instructor and a reporting period and asks for
a briefing. A queued run sends **anonymized** statistics and review
excerpts to the configured AI provider, validates what comes back
against a schema, and stores it for a person to read and mark reviewed.

## What it is not

Nothing in the platform reads a stored insight. It does not:

- suspend, approve, reject or change an instructor's status;
- change compensation, payouts, refunds or any financial record;
- change ratings, reviews or their moderation state;
- raise a quality alert, send a notification, or modify a booking;
- rank instructors — there is deliberately no score column, and the
  confidence column is not sortable in the admin table.

`QualityInsightArchitectureTest` enforces this: the domain may not
reference `App\Wallet`, `App\Payments`, `App\Earnings`, `App\Compliance`,
`InstructorQualityAlertService`, `InstructorStatus`, or any notification,
and no code outside the admin surface may read `AiQualityInsight`.

**Deliberately distinct from `InstructorQualityAlert`.** An alert is a
deterministic, rule-derived signal with a resolution workflow that feeds
real operational process. An insight is a model's non-deterministic
reading of existing signals. Merging them would let a probabilistic
opinion enter a pipeline built for facts.

Also distinct from `App\Reviews\Services\InstructorQualityInsightsService`
— the instructor's *own* deterministic dashboard figures. Same word,
different thing; neither reads the other.

## Data sources — nothing new is measured

| Signal | Comes from | Scope |
|---|---|---|
| Demo/paid bookings, completed lessons, no-shows, unique students, booked hours | `InstructorPerformanceRepository` (the Instructor Performance report's own queries) | period |
| Open quality alerts | `InstructorPerformanceRepository::activeQualityAlertsFor()` | current |
| Average rating, review count, dimension averages with sample sizes | `InstructorRatingAggregateService::summaryFor()` | lifetime, labelled as such |
| Review tag counts | `LessonReviewRepository::tagCountsForInstructor()` | lifetime |
| Review excerpts | `QualitySignalRepository::publishedReviewsInWindow()` | period, capped |

`QualityInsightInputBuilder` only labels and caps — it computes no
metric of its own, so an insight can never disagree with the report an
admin checks it against.

It reads the reporting **repository** rather than the report **service**
on purpose: the service builds drill-down URLs (no Filament panel exists
inside a queue worker) and demands reporting permissions the requesting
admin need not hold. Authorization for generating an insight belongs to
`AiQualityInsightPolicy`, checked before dispatch.

## Privacy boundary

`QualityInsightInput` is the boundary made explicit: if a field is not on
that DTO it cannot reach a provider. Reviewing "what do we send?" means
reading one class.

**Never sent:** student names, ids or emails; phone numbers; the
instructor's own name or contact details; review, booking, lesson or
insight identifiers; payment, wallet, earning or payout figures; KYC
state; anything authentication-related.

**Sent:** the period label, the aggregate counts above, dimension
averages with sample sizes, tag counts, and up to
`QualityInsightInputBuilder::MAX_EXCERPTS` (12) anonymized review
excerpts labelled positionally ("Review A"), each capped at 400
characters.

`QualityInsightAnonymizer` applies three overlapping layers:

1. **`ReviewContentSanitizer`, re-run.** Stored review text is already
   sanitized at submission; re-running it costs nothing and covers rows
   written before that rule, imported, or edited by a future path.
2. **Known-name redaction.** Generic PII scrubbing cannot recognise a
   name — but we know whose review it is, so the student's and
   instructor's name parts are removed by exact word-boundary match.
   This is the layer that catches *"my daughter Mira loved it"*.
3. **Residual-digit stripping and a hard length cap.**

Redaction is irreversible and never logged.

## Human review requirement

- `requires_human_review` defaults true, and `QualityInsightData` forces
  it true whenever the model raised any concern — the model may *raise*
  the review requirement, never waive it.
- The admin page requires an explicit **Mark reviewed**, which records
  who read it, when, and an optional note, and writes an audit event.
- The infolist leads with an advisory banner; concerns are rendered as
  "Worth looking into", never as findings.

## Flow

```
Admin → Generate (instructor + period preset)
  → QualityInsightService::request()      feature gate, budget, duplicate guard
  → AiQualityInsight row (Pending)        + audit event
  → ExecuteAiTaskJob (queue: ai)          identifiers only, no content
      → QualityInsightInputResolver       reads + anonymizes at execution time
      → AiExecutionServiceInterface       P0: gate, budget, prompt, provider, model
      → provider                          OpenAI today
      → StructuredOutputValidator         quality_insight schema
      → QualityInsightResultHandler       stores validated data, or the failure
  → Admin reads → Mark reviewed
```

Every failure path resolves the Pending row: a blocked run, an
unresolvable subject, a provider outage, or a schema rejection after the
retry budget all end as `Failed` with a stable, admin-readable reason.
A too-sparse period is refused before any provider call, so an empty
month costs nothing.

## Prompt and schema

`quality_insight:v1` (`QualityInsightPrompt`) — frozen; new wording means
a v2. The system prompt forbids verdicts and any recommendation of a
consequence (suspension, pay, ranking, discipline), requires sample size
to be weighed, and requires hedged language.

`QualityInsightSchema` shapes the output to stay advisory: summary,
strengths, concerns, `recommended_review`, confidence,
`requires_human_review`. There is no score, grade, rank or action field —
a model cannot emit "suspend this instructor" because there is nowhere
to put it.

Both are registered into the P0 registries by `QualityServiceProvider`,
not by `AiPromptCatalog`: the AI module still knows nothing about any
feature, which is what lets P2-P4 land without touching `app/Ai`.

## Storage

`ai_quality_insights` — the validated output, its period, its
provenance, and who asked/read. `source_snapshot` holds **counts and
averages only**; the excerpts the model saw are never stored. Prompts and
responses are stored nowhere. `ai_run_id` links to P0 telemetry for
model, tokens, cost and latency.

## Permissions

`ViewAny:AiQualityInsight`, `View:AiQualityInsight`,
`Generate:AiQualityInsight`, `Review:AiQualityInsight` — manager-only
(`AiQualityInsightPermissionSeeder`). No instructor or student grant of
any kind: an insight is an internal prompt for attention, not feedback
delivered to its subject. Generate is separate because it spends budget;
Review is separate because it is a statement of responsibility.

## Enabling it

1. Configure the provider and key in **Settings → AI Platform**.
2. Turn on `FeatureSettings::ai_enabled`.
3. Turn on `AiSettings::quality_insights_enabled` (the P0 capability flag
   — no new flag was added).
4. Run `php artisan db:seed --class=AiQualityInsightPermissionSeeder`.
5. Ensure a worker runs the `ai` queue.

With `provider = fake` the whole pipeline runs with no external call —
useful for staging.

## Cost

Every generation creates an `ai_runs` row with tokens, model, prompt
version and estimated cost, and is subject to the P0 daily/monthly
budget. Typical run: one generation-model call with ~12 short excerpts.

## Files

| Concern | Location |
|---|---|
| Domain | `app/Quality/Intelligence/**` |
| Model | `app/Models/AiQualityInsight.php` |
| Policy | `app/Policies/AiQualityInsightPolicy.php` |
| Admin UI | `app/Filament/Resources/AiQualityInsights/**` |
| Wiring | `app/Providers/QualityServiceProvider.php` |
| Tests | `tests/Feature/Quality/Intelligence/**` |
