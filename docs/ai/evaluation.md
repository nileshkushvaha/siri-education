# AI Evaluation & Governance (AI-E0)

Status: **shipped.** This is not an AI feature — it adds no capability,
sends nothing to a provider, and changes no AI behaviour. It measures
what P0–P4 already do.

> The biggest AI risk is not implementation. It is shipping something
> nobody trusts or uses.

## What already existed

Almost all of the measurement substrate was built during P0–P4:

| Signal | Source | Since |
|---|---|---|
| Runs, status, model, prompt version, tokens, cost, latency | `ai_runs` | P0 |
| Insight reviewed | `ai_quality_insights.status` | P1 |
| Draft used / discarded | `homework_ai_feedback_drafts.status` | P2 |
| Summary approved / discarded | `lesson_ai_summaries.status` | P3 |
| Finding confirmed / dismissed | `message_safety_findings.status` | P4 |

**Outcomes are derived from those tables, never copied.** A duplicated
"evaluation status" column would be a second version of the truth that
drifts the first time a feature changes its lifecycle.

## What was missing, and what was added

Statuses record **what happened**. None of them records whether the
output was **worth the reader's time** — an insight can be dutifully
reviewed and useless; a draft can be "used" and then rewritten from
scratch.

So one table was added: **`ai_feedback_events`** — an explicit reviewer
verdict (helpful / not helpful + a fixed reason code), attached to the
**AI run**, recorded through `AiFeedbackRecorderInterface`. That is the
whole addition.

### Deliberate constraints on it

- **No free text.** `reason_code` is a fixed enum. A comment box in an
  analytics table is where a reviewer eventually pastes the sentence
  that bothered them — student content, under reporting access instead
  of the owning domain's rules. Reviewers who need prose have their
  domain's own review note.
- **No subject reference.** The row points at the run, not at the
  instructor, student, message or lesson. Evaluation must answer *"is v2
  better than v1"* without being able to answer *"what did staff think
  of this student"*.
- **One verdict per run per reviewer**, updated on change — so nobody
  can weight an average by clicking twice.

### Where the hook is wired

Currently on **P1's review action**, because that is where the derived
signal is weakest ("reviewed" says nothing about usefulness). P2, P3 and
P4 already carry a strong implicit signal — used/discarded,
approved/discarded, confirmed/dismissed — so they were left alone rather
than adding ceremony to a working flow. The recorder is generic; wiring
it into another feature is one call.

## Metric definitions

Every figure has a stated source. A metric nobody can trace is a metric
nobody should act on.

| Metric | Definition | Source |
|---|---|---|
| Runs / succeeded / failed / rejected / blocked | Count by status | `ai_runs` |
| Cost | Sum of `estimated_cost` | `ai_runs` (admin-maintained price table) |
| Median latency | 50th percentile of `latency_ms` | `ai_runs` |
| Awaiting review | Rows in a pending state | feature table |
| Accepted | Reviewed / Used / Approved / Confirmed | feature table |
| Rejected | Discarded / Dismissed | feature table |
| **Acceptance rate** | accepted ÷ (accepted + rejected) | feature table |
| **Found useful** | helpful ÷ (helpful + not helpful) | `ai_feedback_events` |
| **Cost per accepted outcome** | cost ÷ accepted | both |

**Median, not mean, latency** — one 40-second timeout drags a mean far
enough to hide what a typical run costs a waiting user.

**Acceptance means different things per feature** (a used draft, an
approved summary, a confirmed finding) because *"did a human take this
seriously"* is the only question comparable across four features that do
different jobs. Each row names its own definition.

**Rates are null, never zero, when nothing was decided.** "Nobody has
looked yet" must never render as "nobody found it useful".

### The number that decides a feature's future

**Cost per accepted outcome.** Not cost per run: a cheap feature nobody
accepts is not cheap, it is waste.

## Prompt version comparison

`ai_runs` and every feature row both record `prompt_key` + version, so
acceptance and usefulness can be compared **within one prompt key**.

Rows below 20 runs are marked *"Too few runs to act on"* — acting on a
rate drawn from a handful of runs is how a prompt gets changed because
of noise.

Never compare acceptance across different prompt keys: they measure
different human decisions.

## Prompt improvement workflow

```
Observation on the dashboard  (low acceptance, or a cluster of reasons)
   ↓
Read the "why not helpful" reasons — each maps to a different edit
   ↓
Write prompt:v2 in the SAME catalogue, alongside v1
   ↓
Point the feature at v2; v1 stays registered and readable
   ↓
Compare v1 vs v2 on the dashboard once both have enough runs
   ↓
Keep, revert, or iterate
```

**Never edit a frozen prompt in place.** Every phase's prompt class says
so, and this is why: an `ai_runs` row saying `lesson_summary:v1` is only
meaningful if that string refers to the same text forever. Editing v1
silently invalidates every historical measurement of it.

## Monthly AI review

A suggested agenda, using only what the dashboard shows:

1. **Cost** — total, per feature, and per accepted outcome. Anything
   whose cost per accepted outcome is rising is either getting worse or
   being used differently.
2. **Acceptance** — any feature under ~50% is being ignored by the
   people it was built for.
3. **Usefulness reasons** — a cluster on one reason is a prompt edit,
   not a model change.
4. **Failure rate** — schema rejections mean the prompt and schema have
   drifted apart.
5. **Awaiting review backlog** — output nobody reads is output nobody
   needs.

Decisions available: **keep · improve the prompt · disable the
capability · change the model.** In that order — prompt tuning is
cheaper, faster and more reversible than a model change, and a model
change invalidates the comparison you were relying on.

## Cost governance

- P0's `AiBudgetGuard` still enforces the daily/monthly ceilings.
- AI-E0 adds `ai.budget_alert_threshold` (default `0.8`) and the hourly
  `ai:check-budget` command, which raises an **existing**
  `OperationalAlert` (`AiBudgetThresholdReached`, Finance category)
  before the guard starts silently blocking runs.
- Expressed as a **fraction** of the ceiling, not an amount, so it keeps
  warning after somebody raises the limit.
- Scheduled rather than checked inside the guard: the guard runs before
  every AI request, and this condition changes over hours.
- Alerts carry spend figures only — never a feature, prompt, or anything
  about what was analysed.

## Dashboard

**Analytics → AI Evaluation** (`/admin/reports/ai-evaluation`).

Gated on `Configure:AiPlatform` — whoever operates the AI platform and
holds its budget is exactly who should see whether it works. No new
permission was created.

Sections: platform state and spend · per-feature evaluation · prompt
version performance.

## Privacy rules for evaluation

- Evaluation stores **no content and no subject**: not a message, a
  submission, a summary, or who any of them were about.
- Aggregates only on the dashboard — no drill-down to individual
  records, because a drill-down would be a second route to content that
  each domain already gates.
- No AI output becomes an automatic decision as a result of measurement:
  a low acceptance rate prompts a human to change a prompt, and nothing
  else changes on its own.
- Reviewer identity is stored (`actor_id`) so a single reviewer's bias
  can be spotted, and is never displayed as a per-person score.

## What this phase deliberately does not do

No new AI capability, no model auto-switching, no automatic prompt
rewriting, no A/B traffic splitting, and no billing integration.
Everything here informs a human decision.

## Files

| Concern | Location |
|---|---|
| Feedback hook | `app/Ai/Evaluation/**`, `app/Models/AiFeedbackEvent.php` |
| Read model | `app/Reporting/Repositories/AiEvaluationRepository.php`, `app/Reporting/Services/AiEvaluationReportService.php`, `app/Reporting/DTOs/Ai/**` |
| Dashboard | `app/Filament/Pages/AiEvaluationDashboard.php` + its Blade view |
| Budget alerting | `app/Console/Commands/Ai/CheckAiBudgetThreshold.php`, `routes/console.php` |
| Tests | `tests/Feature/Ai/Evaluation/**` |
