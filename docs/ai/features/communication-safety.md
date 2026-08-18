# Communication Safety & Moderation (P4)

Status: **shipped, AI disabled by default.** Flagging only — no
blocking, no bans, no automatic enforcement of any kind.

> Protect the marketplace and its users by surfacing risky messages for
> human review. Nothing is enforced by software.

## The phase that breaks the pattern

P1–P3 were human-initiated: a person chose each record before anything
left the platform, and that choice was doing real privacy work. **A
safety check that only runs when asked is not a safety check**, so this
phase analyses messages automatically — the first and only place in the
platform that does.

Three controls replace the missing human initiation:

1. **Deterministic first.** `LeakageDetector` already runs on every send
   and catches emails, phone numbers, links and named payment/messaging
   apps. Those messages are answered for free and are **never sent to a
   provider**.
2. **A narrow triage gate.** `AmbiguousIntentDetector` only selects
   messages that trip none of the above *and* contain phrasing
   associated with off-platform arrangements. Ordinary tutoring
   conversation never leaves the platform.
3. **A minimal input.** One message, no history, no names, no ids — just
   the text and the sender's *role*.

## Three detection layers

```
Message sent
   ↓
Layer 1  LeakageDetector (existing, deterministic)
         email · phone · links · WhatsApp/Telegram/PayPal/UPI …
         → finding recorded, no AI, fully explainable
   ↓ (only if nothing above fired)
Layer 2  AmbiguousIntentDetector → communication_risk:v1
         "somewhere else" · "between ourselves" · "skip the fees"
         → AI finding with a confidence, or nothing at all
   ↓
Layer 3  Human review → confirm or dismiss
   ↓ (only after CONFIRMATION, and only as a pattern)
         RepeatedConfirmedMessageRisksRule → existing Compliance Flags
```

Abuse and unsafe content take a separate path: `message_moderation:v1`
runs the provider's safety classifier **only on a message a human has
reported**. Classifying every message anyone writes would be the blanket
surveillance this phase excludes, and the classifier is most useful
exactly when an admin has a report to triage.

## What AI never does

- block, hide, alter or delay a message
- ban, suspend, restrict or warn a user
- close or restrict a conversation
- change reputation, ratings or account status
- open a compliance flag on its own

The schema has no `block`, `ban`, `suspend`, `restrict` or `action`
property, so a model has nowhere to put an instruction. The findings
table has no enforcement column. Both are asserted by tests.

## The soft warning (no AI)

When a message *obviously* contains contact or payment details, the
sender sees this **before** it is delivered:

> Your message looks like it contains an email address.
> Keeping conversations and payments inside SIRI is what lets us support
> you if something goes wrong — lesson records, refunds and dispute help
> only cover what happens here. **You can still send this message.**

Properties that make it acceptable, all tested:

- produced by `LeakageDetector` alone — **no provider call**
- **nothing is recorded** — a user who has not sent a message has done
  nothing to record
- **never blocks** — "Send anyway" delivers the message verbatim
- server-side, so there is exactly one detector; a JavaScript copy of
  those patterns would be a second source of truth that drifts

This is user education, not moderation.

## Privacy boundary

`CommunicationSafetyInput` is the boundary — the narrowest in the
platform.

**Sent:** the message text (redacted), the sender's **role**
(`student` / `instructor`), and the triage phrases that selected it.

**Never sent:** conversation history, any other message, names, emails,
phone numbers, user or message or conversation ids, profile data,
booking/lesson context, payment or wallet data, account status.

**No conversation history is a deliberate accuracy trade.** Context
would improve intent detection — "yes, that works" is meaningless alone
— and would also mean one flagged phrase drags an entire private
conversation to a third party. The prompt tells the model it is seeing
one message in isolation so it lowers confidence rather than inventing
context.

Runs record `requested_by = null` on `ai_runs`, because nobody asked.

## Storage

`message_safety_findings` — one row per message per source.

`source_type` is the point of the table: `deterministic` findings are
verifiable facts about the text; `ai_intent` and `ai_moderation`
findings are opinions with a confidence that may be wrong. They share
one table so an admin has one place to look, and are never presented as
the same kind of claim.

**No message text is copied here.** `reason` is the model's
one-sentence description, capped at 300 characters by schema.

**Findings that turn out clean are deleted.** This is the one AI record
in the platform that is deliberately removable: a suspicion raised
without anyone asking, which the analysis then cleared, should leave no
trace. A finding an administrator has **reviewed** is the opposite — a
human decision, and permanent. The model enforces both halves.

## Admin review

**No new admin screen was added.**

- Per-message evidence appears in the existing **Conversations → Message
  History** relation manager, with a findings column and an "open
  finding" filter.
- Account-level review stays in the existing **Compliance Flags** queue.

The bridge is `RepeatedConfirmedMessageRisksRule`: it counts findings an
**administrator has confirmed** — never raw model output — over a
window, and raises a normal `SuspiciousActivityFlag` through the
existing `ComplianceMonitoringService`. The rule is deterministic, its
`evidence` is counts only (respecting that pipeline's no-narrative
contract), and a model's opinion can never open a compliance case on a
real person.

## Authorization

Compliance staff only, reusing the existing suspicious-activity
permissions (`ViewAny:` / `View:` / `Resolve:SuspiciousActivityFlag`)
rather than minting a parallel set. No new permissions.

**Neither participant may see a finding, including the sender.** Showing
someone an unreviewed machine suspicion about their own words teaches
evasion, invites argument with a classifier, and for a student — often a
minor — amounts to being accused by software. What a user sees is the
pre-send warning, which is advisory, instant and never recorded.

## Configuration

| Setting | Default |
|---|---|
| `FeatureSettings::ai_enabled` | `false` |
| `AiSettings::communication_moderation_enabled` | `false` |
| `compliance_monitoring.repeated_confirmed_message_risks_enabled` | `true` |
| …`_threshold` / `_window_days` | `3` / `30` |

The escalation rule ships **enabled** because it is fully deterministic
and consumes only findings an admin confirmed by hand. With the AI flag
off, the only findings that exist are deterministic leakage matches —
exactly what should escalate when an admin keeps confirming them.

Enabling AI: configure the provider and key, turn on `ai_enabled` and
`communication_moderation_enabled`, and ensure workers run the `ai` and
`compliance` queues.

## AI limitations

- **One message, no context** — the model can misread a reply that only
  makes sense given what came before. Confidence is usually modest by
  design.
- **The triage gate is deliberately narrow**, biased toward missing
  cases rather than over-triggering. Evasive phrasing it does not know
  will pass; reporting and the deterministic layer remain the backstop.
- **Moderation only sees reported messages**, so unreported abuse is not
  classified.
- **Findings are advisory.** A confirmed finding means an administrator
  agreed it was worth noting, not that a rule was broken.

## Future improvements

- Reviewer feedback on findings (was the AI right?) to measure the
  prompt and tune the triage phrases — the same deferred item noted for
  P1–P3.
- Per-feature AI budget: `ai_runs.feature_key` already supports it, and
  this is the highest-volume AI feature.
- Broadening the triage phrase list based on what confirmed findings
  actually look like in production — data the platform does not have yet.

## Files

| Concern | Location |
|---|---|
| Domain | `app/Messaging/Safety/**` |
| Triage gate | `app/Messaging/Safety/Support/AmbiguousIntentDetector.php` |
| Model | `app/Models/MessageSafetyFinding.php` |
| Policy | `app/Policies/MessageSafetyFindingPolicy.php` |
| Listeners | `app/Listeners/Messaging/**`, `app/Listeners/Compliance/EvaluateConfirmedMessageRisksOnFindingConfirmed.php` |
| Escalation rule | `app/Compliance/Rules/RepeatedConfirmedMessageRisksRule.php` |
| Warning UI | `MessagingController::reply()` + `dashboard/messages/show.blade.php` |
| Wiring | `app/Providers/MessagingServiceProvider.php` |
| Tests | `tests/Feature/Messaging/Safety/**` |
