# Admin Copy Style Guide

Rules for user-visible text in the admin panel (`app/Filament/**`): page titles, headings,
subheadings, breadcrumbs, section headings/descriptions, field labels, helper text,
placeholders, toggle labels, modal copy, and notifications. This governs *presentation*
copy only — it never changes validation, stored values, or business behavior.

## Prohibited in user-visible copy

Never appears in a label, description, helper text, placeholder, modal, or notification:

- SRS citations (`SRS §…`), ticket/phase references (`GAP-…`, `FR#…`, `Phase 17U`)
- Version/implementation-status language ("Version 1", "recommended approach", "already-implemented")
- Raw PHP class/namespace names, artisan command names, file paths (`docs/…`, `*.blade.php`)
- Raw database column/table/enum names (`password_expires_at`, `booking_meetings`, `credit_failed`)
- Dev/environment jargon ("in production", "for now", "exception path", "happy path")
- Framework/CS jargon without translation ("auth guard", "snake_case", "HTTP", "outer chrome")

These are fine in developer comments, docblocks, test descriptions, and internal docs —
just never in text an admin reads.

## Headings

- Page title: concise, domain-oriented. Don't append "Settings" if the nav context already
  makes it obvious.
- Section heading: a short domain concept ("Eligibility", "Applicability"), not "Information"
  or a repeat of the page title. Page "Create FAQ Category" + section "Category details", not
  section "Create Category Information".
- Subheading: one plain-language sentence on the page's purpose, or omit it — never filler.

## Field labels

- Describe the value entered, in plain product language — not the DB column name or an
  internal enum name.
- Sentence-style capitalization by default.
- If a friendly label for a concept already exists elsewhere (e.g. "Evidence Type" for
  `collection_name`), reuse it — don't introduce a second name for the same thing.

## Helper text

- Add only when something non-obvious needs explaining: format, unit, consequence,
  ordering, or a constraint the validation message won't reach until submit.
- Don't restate the label. Don't add filler to every field.
- Toggles: label the *enabled* state positively ("Enable X"); helper text states the
  consequence of the disabled state neutrally, not alarmingly.

## Placeholders

- Only for a genuinely useful example or format hint. Never for: selects with an existing
  prompt, toggles, date pickers with an obvious native format, numeric fields whose
  suffix/label already explains the input, or as a substitute for a required-field label.
- Use realistic fictional examples — never real personal or financial data.
- Never duplicate the helper text.

## Money and currency

- Never obscure the input unit. If a field expects the smallest currency unit, say so:
  *"Enter the amount in the smallest unit of the currency below — e.g. 10000 equals 100.00."*
- If the record's currency is fixed per-row, reference it dynamically
  (`$record->currency_code`) rather than hardcoding one currency.
- Don't claim multi-currency support that doesn't exist, and don't silently change a field's
  input unit to "fix" the wording — flag it instead if the unit itself is a poor UX choice.

## Raw enum/status values

- Never show a raw backing value (`credit_failed`, `in_progress`) to an admin — always the
  enum's `label()`. This applies to table columns, infolists, and exception messages that
  surface as notification bodies.
- Class names shown in read-only viewers (Activity Log, linked-record pickers) should go
  through `App\Support\ModelDisplayName::for()` rather than raw `class_basename()`.

## Confirmations and notifications

- Modal descriptions: a short, consequence-oriented sentence — what happens, not how it's
  implemented.
- When several distinct actions share one error-handling helper, give each a specific
  failure title ("Approval failed", "Could not resolve flag") — a single generic "Action
  failed" title is fine only when there's no ambiguity about which action was attempted.
- Don't show "success" styling for an outcome that might not actually be successful (e.g.
  branch the notification color on the resulting status, not just on "the call didn't
  throw").

## Terminology

Use consistently across the panel; don't introduce a second term for something already
named elsewhere:

| Use | Not |
|---|---|
| Instructor | Teacher (unless the model/table is literally `Teacher*`) |
| Student | Learner |
| Booking | Reservation |
| Payout | Withdrawal payment (Withdrawal = the request; Payout = the execution) |
| Wallet credit | Wallet balance top-up |
| Active / Inactive | Enabled / Disabled (for status fields specifically) |
| Internal reason / Internal note | staff-only text never shown to the student/instructor |

## Legitimate exceptions

Some technical vocabulary is the right call for its specific, specialized audience — don't
over-translate:

- The Activity Log / audit-trail viewer may show raw JSON, HTTP method, route, and other
  technical request metadata — its audience is doing technical audit work.
- A credential field named after the vendor's own term (e.g. "Service Account JSON" for
  Google) should keep that term.
- "Coming soon" / "not yet available" is acceptable, consistent roadmap language for
  disabled options tied to genuinely unbuilt features — it's not implementation-status
  leakage, it's telling the admin what they can't do yet.
