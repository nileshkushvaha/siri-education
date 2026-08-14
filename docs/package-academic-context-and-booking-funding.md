# Country-Aware Package Academic Context, Booking Funding & Entitlement Reservation

Phase 4D. Completes the operational package flow: an instructor builds a
personalized package against a student's *real* academic context, and the
student later spends it by deliberately choosing it when booking a lesson.

The companion document `generic-payable-payment-foundation.md` covers how a
package is paid for and activated. This one covers what a package *means*
academically, and how its lessons get spent.

---

## The lifecycle

```
Admin defines a PackageBenefitRule           (quantities + validity)
        ↓
Instructor creates a country-aware Proposal
        ↓
PackageAcademicContext frozen at SUBMIT       ← structured academic identity
        ↓
Admin approval  (may override price only)
        ↓
Student payment → verified settlement
        ↓
Active StudentPackageEntitlement
        ↓
Student EXPLICITLY selects the entitlement when booking
        ↓
Reservation (Reserved) + booking.package_entitlement_id
        ↓
Booking  →  Lesson   (lesson.package_entitlement_id snapshotted)
        ↓ completed
Reservation → Consumed  +  consumption ledger row  +  used_quantity +1
```

Every arrow after "Active entitlement" requires a deliberate student action.
Nothing in this system ever infers that a package should fund a lesson because
one happens to match.

---

## The three quantities

These are three different numbers and must not be conflated:

| Value | Meaning | Where it lives |
|---|---|---|
| `remaining_quantity` | purchased units **not yet consumed** | stored generated column, `total - used` |
| `reserved_quantity` | units **committed to future bookings** | `COUNT(reservations WHERE status = reserved)` |
| `available_to_book` | `remaining - reserved` | computed by `PackageEntitlementService::availableToBook()` |

Worked example:

```
Total lessons:       15
Used:                 5
Remaining:           10     ← unchanged by scheduling
Already scheduled:    3
Available to book:    7     ← what the student may still book
```

`remaining_quantity` keeps its Phase 4A meaning and is **never** redefined to
mean available capacity. It is a generated column precisely so it cannot drift,
and an architecture test asserts its definition never mentions reservations.

---

## Why an explicit reservation ledger

Phase 4C consumes a unit only on `LessonCompleted`, which is correct — a lesson
that never happens must not burn a lesson. But it means `used_quantity` does not
move at booking time, so with one unit left a student could otherwise schedule
three bookings against it and only discover the shortfall at delivery.

The alternative considered (spec §17) was deriving reserved capacity as
`remaining − count(active unconsumed package-funded bookings)`. That was
rejected:

- correctness would depend on a status-set predicate over `bookings` staying
  permanently in sync with every future addition to the booking lifecycle — a
  silent-overbooking bug the day a status is added;
- there is a real window between a lesson reaching a completed outcome and the
  consumption listener writing its ledger row, during which a booking counts as
  neither reserved nor consumed (double-spend) or as both (phantom shortage);
- booking creation serializes on an **instructor** row lock, which happens to
  also serialize same-entitlement bookings today — but that is incidental, and
  entitlement capacity must not depend on a lock taken for an unrelated reason.

So `student_package_entitlement_reservations` makes the committed unit a
first-class fact with its own lifecycle.

### State machine

```
Reserved ──► Consumed    lesson delivered; ledger row written
Reserved ──► Released    cancelled, non-consuming outcome, or entitlement expired
```

Both exits are terminal. A reservation never returns to `Reserved` — re-booking
after a release creates a **new** reservation, so the ledger reads as a history
of decisions rather than a mutable counter. Reservations are never deleted.

### Concurrency

`reserveForBooking()` locks the entitlement row `FOR UPDATE`, then recomputes
availability *under that lock*. Two racing requests for a single last unit
serialize: the first commits, the second is refused. `UNIQUE(booking_id)` is the
independent schema-level backstop. Because the reservation is created inside
`BookingService`'s creation transaction, the loser's entire booking rolls back
rather than existing without capacity behind it.

### Lifecycle hooks

| Event | Listener | Effect |
|---|---|---|
| `BookingCancelled` | `ReleasePackageReservationOnBookingCancelled` | Reserved → Released |
| `LessonOutcomeFinalized` (non-Completed) | `ReleasePackageReservationOnNonConsumingOutcome` | Reserved → Released |
| `LessonCompleted` | `ConsumePackageEntitlementOnLessonCompleted` | Reserved → Consumed, atomically with the ledger row and `used_quantity +1` |

`Completed` is filtered out of the outcome listener so it can never race the
consumption path. The two are mutually exclusive by construction: releasing
requires a still-`Reserved` reservation, and consumption claims it under the
entitlement's lock.

Cancellation and no-show **commercial policy is unchanged** from Phase 4C — this
phase only makes the reservation agree with the decision Phase 4C already made.

**Rescheduling** needs no special handling: `RescheduleBookingAction` mutates the
same booking row rather than creating a replacement, so the reservation
(keyed on `booking_id`) is preserved automatically. There is no window in which
the unit is briefly free for another booking to steal.

**Expiry** (§28): `expireIfNeeded()` releases every outstanding reservation with
reason `entitlement_expired`. Under the approved "delivered before expiry"
policy those units can never legitimately be consumed, so `Released` is the
honest state. The affected *bookings* are not cancelled — package balance and
booking lifecycle are separate concerns, and a lesson may still be delivered as
goodwill; it simply will not consume a unit.

---

## Package academic identity

Before this phase a proposal carried only `subject_id` + `academic_level_id`,
while country-aware booking had grown a full chain. That left a package meaning
*"Math + AcademicLevel 10"* facing a booking meaning
*"India / CBSE / Class 10 / Mathematics / Curriculum X v3"* — two academic
truths that cannot be matched deterministically.

`package_academic_contexts` gives the package the same structured identity, so
entitlement↔booking eligibility is decided on **stable ids**, never display
labels.

### Ownership — one truth per package

```
InstructorPackageProposal
        ↓ 1:1
PackageAcademicContext          ← the authoritative academic truth
        ↑
Purchase / Entitlement reach it THROUGH the proposal
```

Purchases and entitlements deliberately do **not** copy the snapshot; four
copies would be four things that can disagree. `StudentPackageEntitlement::academicContext()`
is a `HasOneThrough` back to the proposal's row.

The pre-existing `subject_id` / `academic_level_id` columns on proposals and
entitlements are kept for compatibility and cheap querying, and are written
*from* this snapshot at submission, so they can only ever agree with it.

It is a **separate table** from `booking_academic_contexts`, not a polymorphic
reuse of it (§1). The two have different lifecycle ownership: a booking snapshot
is frozen at booking creation and belongs to one historical lesson; a package
snapshot is frozen at proposal **submission** and governs many future bookings.
The resolution *algorithm* is shared instead — see below — which is where
duplication would actually have hurt.

### Historical immutability

`PackageAcademicContext` uses `PreventsUpdates` + `PreventsHardDeletion`. A later
rename of an Education System, a relabelled level, or a newly published
`CurriculumVersion` never rewrites what an already-submitted package
represented:

```
Package purchased under CBSE / Class 10 / Mathematics / Curriculum v2
        ↓  admin later publishes v3
Existing entitlement still matches as v2
A NEW proposal resolves v3
```

A package-funded **Booking** therefore derives its own `BookingAcademicContext`
from the *package's* frozen snapshot, not from a fresh resolve (§38). Current
ability to *deliver* the lesson is validated separately and never merged with
historical package context.

---

## The shared resolver

Phase 3 built `DemoAcademicContextResolver` as the only country-aware academic
composition layer. Phase 4D needed the same chain for packages and package-funded
paid booking, so the algorithm moved to `BookingAcademicContextResolver` and the
demo class became a thin wrapper over it.

The two demo-only assumptions were lifted out and passed in instead:

- **which feature gates the flow** (`CountryFeature`), and
- **the student-facing wording** (`AcademicFlowCopy`).

The demo flow's behavior, gating feature and exact messages are unchanged; every
Phase 3 call site and test keeps working; and there is still exactly one
implementation of the chain.

`resolve()` gained an `autoResolveCurriculum` mode for the package flow: when the
context determines exactly one curriculum it is selected automatically rather
than becoming another dropdown (§11). Zero or more than one is an explicit
failure — never a silent pick.

---

## Feature gating — a separate flag, deliberately

Packages are gated by **`CountryFeature::CountryAcademicPackages`**
(`features.country_academic_packages_enabled`), *not* the demo flow's
`CountryAcademicBooking`.

The audit found `CountryAcademicBooking` declares `DemoLessons` as a dependency,
and `CountryFeatureResolver::isEnabled()` recurses through dependencies. Reusing
it would have meant **an admin switching off free demos silently made every paid
package unbookable** — an unacceptable coupling for a paid product. Its settings
docblock also scopes it explicitly to "academic **Demo** booking".

`CountryAcademicPackages` therefore declares **no** dependency. `PaidBookings`
was the tempting one, but it maps to the payment-gateway switch: an
already-settled entitlement must remain spendable while new collection is
paused, and coupling them would recreate exactly the trap being avoided.
Purchase-time collection is gated by the payment domain's own checks.

Both flags default to **off**. Neither is exposed in the Filament settings page
(following the Phase 3 precedent for its sibling).

Semantics when on: structured academic context is **mandatory** for new proposals
and for package-funded booking — never a fuzzy fallback. When off: the legacy
Subject + optional AcademicLevel proposal shape is preserved, and such a
context-free entitlement is **ineligible** for the structured booking path.
Fail closed.

---

## Funding a booking

### Selection is always explicit

`PackageBookingEntitlementResolver::eligibleFor()` returns **every** qualifying
entitlement in a stable display order. There is no FIFO, FEFO, oldest-purchase,
largest-balance or nearest-expiry preference anywhere in it. Multiple compatible
packages are all shown; the student's choice is what resolves the ambiguity, and
that is what makes `bookings.package_entitlement_id` a truthful record of intent
rather than a guess.

An entitlement qualifies only if **all** hold: belongs to the authenticated
student; belongs to the selected instructor; status Active; not expired; backed
by a Paid purchase; `available_to_book >= 1`; and matches the frozen academic
context on `education_system_id`, `education_system_level_id`, `subject_id`,
`curriculum_id` **and** `curriculum_version_id`.

### Expiry vs. slot

A lesson must **finish** inside the validity window, because Phase 4C's policy is
"delivered before expiry". A 23:30 start with a 60-minute duration against a
00:00 expiry is not offered. Compared as absolute **UTC instants**; timezone is a
display concern only. A null expiry imposes no restriction.

### Package funding requires a chosen instructor

An explicit Version 1 rule:

```
Package-funded booking  →  requires a selected/locked instructor
Auto-assigned booking   →  ordinary paid booking only
```

This is a product boundary rather than a technical limitation. A personalized
package is a contract with **one** instructor, so "which of my packages can fund
this lesson?" is unanswerable until that instructor is known. The two ways to
"fix" it are both wrong: searching every instructor's packages leaks other
contracts into the decision, and letting entitlement availability pick the
instructor would mean the *package* chooses who teaches rather than the student.

`WizardBookingService::requireEligibleEntitlement()` therefore refuses a
package-funded request with no `teacherId`, and the wizard's funding step is
simply not offered for an auto-assigned booking — it proceeds as an ordinary paid
booking.

If packages ever become transferable across instructors, that is a separate
commercial feature and should be designed on its own terms, not retrofitted here.

### No payment side effects

A package-funded booking takes `BookingPaymentStatus::PackageFunded` and
`reserved_until = NULL`. It creates **no** `payments` row, **no** `booking_payments`
row, and **no** wallet debit.

`PackageFunded` is deliberately its own case rather than a reuse of
`NotRequired` or a zero-value `Paid`:

- `NotRequired` means "this booking type costs nothing" and reads as **free**
  everywhere it is displayed — a package lesson is *prepaid*, not free (§30);
- a fake zero-price captured payment would corrupt revenue reporting, which
  reads captured amounts as real collection.

The booking keeps its **real** `price`/`currency` so reporting still sees the
lesson's commercial value; only the collection expectation differs. It is not
payable, so no charge can be initiated against it, and it is excluded from
`booking:release-expired` twice over (by payment status *and* by the null hold).

UI says **"Covered by package"**, never "Free" and never "£0".

### The normal paid path is untouched

Owning a compatible package never forces its use. "Pay normally" is always
offered and nothing is preselected. A paid booking that sends no structured
academic selections resolves no context and behaves exactly as before — the
country-aware extension to `paid_one_to_one` is purely additive.

---

## Authorization

- A student may select only **their own** active entitlement.
- Server-side validation proves `booking.student_id == entitlement.student_id`
  **and** `booking.instructor_id == entitlement.instructor_id`, plus academic
  compatibility. A posted entitlement UUID is never trusted — checked in
  `WizardBookingService::requireEligibleEntitlement()` and again in
  `BookingService::requirePackageEntitlement()` immediately before persistence.
- An instructor never chooses a funding source on a student's behalf.
- No role receives generic create/update/delete permissions on reservations
  (§41). They are lifecycle records written only by `PackageEntitlementService`.
  `PreventsHardDeletion` blocks removal outright; updates are permitted only for
  the two state transitions, and only through that service.
- The instructor's own country is never chosen by them either — the *student's*
  country is server-resolved via `CountryResolver::forStudent()` and displayed
  locked.

---

## Instructor compensation is unaffected

Package pricing and instructor compensation remain entirely independent.
`InstructorCompensationResolver` references no package pricing service; earnings
are resolved from the completed lesson through the ordinary
`CreateEarningOnLessonCompleted` path, which is a *sibling* of the consumption
listener, never a replacement. Neither the package's final price nor an admin
price override changes what an instructor earns, and a bonus-funded lesson earns
exactly like a paid-funded one.

---

## Tests

- `tests/Feature/Package/PackageEntitlementReservationTest.php` — 28 tests over
  reserve/release/consume, the three-quantity distinction, oversubscription
  refusal, the schema-level unique guard, cancellation and re-booking, expiry
  releasing outstanding reservations, the intact Phase 4C completion-time expiry
  guard, deletion safety, and all three lifecycle listeners.
- `tests/Feature/Package/PackageFundedBookingTest.php` — 14 tests over payment
  isolation (`PackageFunded` settled-but-not-paid, never payable, never labelled
  free) and the Part 50 architecture guards: reservation and package-context
  tables and their unique indexes, `booking_academic_contexts` **not** becoming
  polymorphic, the proposal's structured selection columns, no mutating
  reservation permissions, and — importantly — that `remaining_quantity`'s
  generated definition was never rewritten to mean available capacity.

Architecture guards follow the principle adopted after Phase 4B.1: assert that an
operation leaves shared financial tables **untouched**, rather than that those
tables must not exist. `payments`, `booking_payments` and the wallet ledger are
legitimate shared infrastructure — the invariant is about side effects.
