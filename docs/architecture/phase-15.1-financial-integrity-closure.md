# Phase 15.1 — Financial Integrity Closure Audit

> **⚠️ Superseded (Phase 14.4).** Content remains accurate; consolidated into the canonical document. The canonical, current
> reference is [docs/financial-domain-architecture.md](../financial-domain-architecture.md);
> this file remains as a historical phase record only.

Hardening pass over Phase 14 settlement + Phase 15 withdrawals. No new
features, no payout execution, no external provider — the goal was to
*prove*, with real multi-process MySQL races, that the same instructor
earning can never be consumed by both financial paths, and to close the
gaps that proof surfaced.

## What the audit found and fixed

1. **Settlement race (critical, fixed).** Phase 14's
   `createSettlementBatch()` made its eligibility decision on an
   *unlocked* `settleable()` read, then assigned earnings inside the
   transaction without revalidation. A withdrawal reserving those
   earnings between read and write would not have stopped the batch.
   Now: the whole operation is one transaction that locks the
   instructor's user row first, re-derives the settleable set with
   `FOR UPDATE`, validates on the locked rows, and asserts the guarded
   `UPDATE` affected exactly the locked set.
2. **Default-switch race (fixed).** `setDefault()` locked only method
   rows; two concurrent switches on *different* methods could miss each
   other. Now it takes the owner lock first — plus a database backstop
   (below).
3. **Disable race (fixed).** `disable()`'s active-withdrawal check now
   runs under the owner lock, so it serializes with withdrawal creation.
4. **Idempotency payload binding (fixed).** Replaying a key with a
   different amount or payout method now raises a conflict instead of
   silently returning the old request.
5. **Approval integrity (extended).** Approval now re-verifies, on
   locked rows: positive amount, fee/net reconciliation, exact reserved
   sum, every backing earning (ownership, currency, still releasable,
   not settlement-assigned/disputed/reversed), the destination still
   active, and the snapshot present *and decryptable*. Any breach aborts
   atomically; nothing is repaired silently.
6. **Hardcoded two-decimal money (removed).** All display formatting in
   the earnings/payout domain now flows through
   `App\Support\MoneyFormatter` (canonical `currencies.minor_units`);
   the Phase 14 percentage calculation was moved to integer
   basis-points math so no float ever touches a canonical amount.

## Canonical lock order

```text
1. users row of the instructor (the financial owner lock)
2. withdrawal request row
3. payout method row
4. instructor earnings, deterministic FIFO (released_at, created_at, id)
5. withdrawal allocations
6. settlement batch aggregate
```

### Locking matrix

| Operation | Locks (in order) | Revalidation under lock | Deadlock stance |
|---|---|---|---|
| `requestWithdrawal` | users → method → earnings FIFO | idempotency, active count, method ownership/status, full balance recalc | serialized by owner lock |
| `createSettlementBatch` | users → earnings FIFO (settleable, FOR UPDATE) | emptiness, total, minimum, guarded-update row count | serialized by owner lock |
| `approve` | withdrawal → allocations → earnings | full `assertApprovalIntegrity()` | no owner lock needed: the withdrawal row lock serializes all transitions of one request; earnings are locked after, same relative order as creation |
| `reject` / `cancelBy*` | withdrawal → allocations | transition matrix; release in same txn | same as approve |
| `setDefault` | users → target method → other default methods | verified status | serialized by owner lock |
| `disable` | users → method | active-withdrawal existence, transition | serialized by owner lock |

Every writer that can touch an instructor's earnings pool takes the
**owner lock first**, which makes the cross-service orders acyclic:
no service ever holds an earnings lock while waiting for the owner
lock. Transition operations (approve/reject/cancel) lock the
withdrawal row before earnings — the same relative order creation uses
(owner → … → earnings) — and never take the owner lock, so no cycle
exists between creation and transitions either. Because ordering makes
deadlock between these paths structurally impossible, no
deadlock-retry wrapper was added (none exists in the project; adding
one would risk retrying domain failures). If InnoDB ever reports 1213
here, treat it as a lock-order regression, not something to retry.

## Race proof — real MySQL multi-process tests

`tests/Feature/Earnings/Concurrency/` + `tests/Concurrency/run-op.php`:
each scenario launches two independent PHP processes that boot the app
against `enterprise_app_testing`, spin on a shared time barrier, and
call the real services simultaneously on separate MySQL connections.
Fixtures are committed (`$connectionsToTransact = []`);
`tearDownAfterClass` re-freshes the database.

| Scenario | Outcome |
|---|---|
| Two withdrawals race for one 30 000 balance | exactly one succeeds; loser gets `WithdrawalException`; reserved = 30 000 ≤ earning; balance 0, never negative |
| Two replays of one idempotency key | both get the *same* request id; 1 row, 1 reservation |
| Withdrawal vs settlement on the same earning | exactly one path wins (XOR-asserted); loser leaves zero partial rows and zero notifications |
| Cancel (release) vs settlement retry loop | settlement succeeds only after the release commits; final state: allocations released, earning batch-assigned, never both reserved+assigned |
| Two default switches on different methods | exactly one default remains |
| DB backstop bypassing the service | second active verified default rejected by unique index |

**Negative validation executed:** with the owner-row and earnings
`FOR UPDATE` locks temporarily removed, the withdrawal race test failed
exactly as it must — both requests succeeded (over-reservation) and the
key replay degraded to a raw unique-constraint violation. Locks
restored; tests green again.

**Documented limitation:** the time barrier makes overlap
overwhelmingly likely but cannot force a specific statement
interleaving. The scenarios are therefore written so that *every*
interleaving of a correct implementation passes, while the lock-removal
run proves an incorrect one fails. These are true parallel processes on
separate connections — not sequential tests described as concurrency.

## Database enforcement added

Migration `2026_07_25_100000_add_financial_integrity_constraints_to_payout_tables`:

- `chk_iwa_amount_positive` — enforced CHECK, `amount_minor > 0` on
  allocations (MySQL 9.x; enforced since 8.0.16).
- `active_default_owner_key` — STORED generated column on
  `instructor_payout_methods`:
  `CASE WHEN is_default=1 AND status='verified' AND deleted_at IS NULL
  THEN instructor_id ELSE NULL END`, with unique index
  `ipm_active_default_owner_unique`. MySQL allows unlimited NULLs under
  a unique index, so this emulates a partial unique index and hard-caps
  active verified defaults at one per instructor. Compatible with the
  project's bigint user keys, string enum storage, and soft deletes;
  it is a backstop behind the service's owner-lock, not a replacement.

## Payout-method disable policy (final)

A method referenced by any withdrawal in `submitted`, `under_review`,
`approved`, or `processing` **cannot be disabled** — the withdrawal
must be rejected or cancelled first. Rationale: a method may be
disabled because the destination is invalid, compromised, or disputed;
an immutable snapshot pointing at a compromised account is not a reason
to proceed. Methods whose entire withdrawal history is
rejected/cancelled disable normally; disabling clears `is_default` and
the single-default invariant holds (test-covered per state in
`PayoutMethodDisableRulesTest`). The block message carries no bank
information. When a future execution phase needs to replace an
approved request's destination administratively, that becomes an
explicit new workflow — not a silent disable.

## Currency-aware money handling

`App\Support\MoneyFormatter`:

- `format(minor, code)` — string/integer arithmetic only (exact above
  2^53, test-proven), exponent from `currencies.minor_units`
  (JPY 0 · USD/INR 2 · KWD 3), exponent range validated (0–6),
  conservative 2 for unknown codes.
- `toMinor(string, units)` — rejects malformed input and *excess
  precision* (never truncates money), no floats.

Replaced hardcoded `/100` + `number_format(...,2)` in: earnings /
settlement-batch / withdrawal Filament tables, the withdrawal
notification, and six spots in the withdrawals Livewire view; the
Livewire amount parser now validates precision per currency. The
Phase 14 percentage calculation now uses integer basis points. The
`currencies.minor_units` column already existed as the canonical
exponent — no schema change was needed. (The Phase 9 wallet's
`WalletMoneyFormatter` already reads `minor_units`; its float division
is display-only, outside this phase's scope, and noted for a future
sweep.)

## Encryption operations (APP_KEY)

Applies to `instructor_payout_methods.encrypted_details` and
`instructor_withdrawal_requests.encrypted_payout_method_snapshot`
(Laravel `encrypted:array` casts, AES-256-CBC under `APP_KEY`):

- **Back up `APP_KEY` separately and securely** (secret manager, not
  the repo, not alongside DB backups). Database backups alone cannot
  restore payout destinations — ciphertext without the key is loss.
- **Losing the key makes every historical payout destination
  unreadable permanently.**
- **Key rotation requires an explicit re-encryption process** (decrypt
  with the old key, re-encrypt with the new) for both columns before
  the old key is retired. Laravel's `APP_PREVIOUS_KEYS` provides
  multi-key *decryption* support during a rotation window; this project
  has no additional envelope-encryption layer, so that native mechanism
  is the one to use — do not build a parallel one.
- **Decrypted details must never be copied into logs or error
  trackers** — the service layer catches `DecryptException` and throws
  a domain message containing neither ciphertext nor MAC internals;
  failed decryption writes no "sensitive details viewed" audit entry
  (`PayoutEncryptionFailureTest`).

No key was rotated or exposed during this audit.

## Idempotency lifecycle (verified)

Server-minted UUID v4 per form open (not guessable/sequential), bound
to the authenticated instructor (same key, different instructors →
independent requests), payload-bound (altered amount or method →
conflict), replay returns the original request with exactly one
notification, hidden from serialization and absent from audit metadata
(test-proven), rolled-back attempts persist nothing so the key stays
usable, and the DB unique `(instructor_id, idempotency_key)` is the
last line of defense.

## Query & index review (EXPLAIN, MySQL 9.7)

All primary queries are index-supported: balance/FIFO/settleable hit
`instructor_id+status` / `currency_code+status` /
`settlement_batch_id` indexes; reserved-sum joins use
`iwa_earning_status_index`; allocation-by-request uses the
`iwa_request_earning_unique` prefix; instructor history uses the
`instructor_id` prefixes; admin listing sorts on indexed
`requested_at`. Active-withdrawal-by-method uses the single-column
`payout_method_id` index — a compound `(payout_method_id, status)` was
considered and *not* added: the per-method row count is tiny by
construction (bounded by the active-request limit), so there is no
evidence it would pay for itself. Watch-list as volume grows:
`scopeSettleable()`'s NOT-EXISTS anti-join and the admin withdrawal
listing with its relationship joins.

## Notifications & after-commit (verified)

All lifecycle audit entries (and the events/notifications hanging off
them) are written after the financial transaction commits; rolled-back
requests produce zero audit entries and zero notifications (asserted
in-process and under real cross-process races); idempotent replays
notify exactly once; queued payloads carry no account data and no
internal review notes; the queued listener is read-only against
financial tables, so a queue retry re-sends but cannot mutate
financial state (test simulates a retry and diffs the rows).

## Test inventory added by 15.1

`WithdrawalReservationConcurrencyTest` (2), `WithdrawalSettlementConcurrencyTest` (2),
`PayoutMethodDefaultConcurrencyTest` (2), `PayoutMethodDisableRulesTest` (8),
`CurrencyMinorUnitFormattingTest` (10), `WithdrawalAllocationIntegrityTest` (7),
`WithdrawalIdempotencyTest` (6), `WithdrawalApprovalIntegrityTest` (10),
`PayoutEncryptionFailureTest` (3), `PayoutNotificationsAfterCommitTest` (5).

Still true after this phase: withdrawals disabled by default, no payout
execution, no provider integration, no route capable of moving money.
