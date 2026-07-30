# Archive

This directory holds historical documentation retained for context — not current product or engineering guidance.

Nothing here is authoritative. If a file in this archive appears to contradict active documentation under `docs/`, the active documentation wins. Do not link to anything in this archive as an instruction to follow; link to it only when explaining historical background or a past decision.

## Structure

- **`phases/`** — phase-by-phase implementation records from earlier development. Useful for understanding *why* something was built a certain way, not *how it works today*. Several describe schemas, endpoints, or behavior that has since changed or been removed — check the active docs (`docs/index.md`) for current behavior before trusting anything here.
- **`reports/`** — one-time foundation/design audits whose durable facts have since been folded into `docs/architecture/domain-registry.md` and `docs/architecture/duplicate-prevention-rules.md`.
- **`audits/`** — completed audit reports from concluded review programs. Any unresolved follow-up work from these audits lives in an active backlog document, not here.
- **`superseded/`** — earlier drafts of documents that were merged into a single canonical replacement elsewhere in `docs/`.

## Why these were archived, not deleted

Git history is preserved for every file here (moved with `git mv`, not recreated), and the files themselves are kept rather than deleted in case of future audit, compliance, or historical-context need. None of them are excluded from search — only from the active documentation index.
