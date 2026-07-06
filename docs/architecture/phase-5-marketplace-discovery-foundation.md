# Phase 5 Marketplace Discovery Foundation

## Decision

Phase 5 strengthens the public instructor discovery surface without changing booking, availability, wallet, payment, meeting, homework, public reviews, referrals, AI, packages, or subscriptions.

The prerequisite gate is `docs/audits/phase-4-learning-plan-final-audit.md`:

- Score: 96/100
- Decision: SAFE TO PROCEED TO PHASE 5
- Blocking issues: none

## Reused Architecture

The marketplace continues to use the existing public instructor routes:

- `GET /instructors`
- `GET /instructors/{user:slug}`

`InstructorController` stays thin and delegates discovery data to `InstructorService`. The service remains read-side only and owns public listing, profile, filters, card data, featured instructors, and related instructors.

No marketplace-specific instructor identity table was introduced. Public discovery still reads:

- `users`
- `user_profiles`
- `teacher_subjects`
- `subjects`
- `academic_levels`
- `languages`
- `countries`
- existing student favorite instructor records

## Public Visibility Rules

Instructor discovery continues to list only instructors who are:

- active users
- assigned the instructor role
- public profiles
- in bookable instructor statuses

Bookable statuses remain defined by `InstructorStatus::bookableValues()`. Draft, submitted, under-review, rejected, suspended, archived, vacation, and other non-bookable statuses are not promoted publicly.

The public profile page uses `InstructorService::publicProfile()` and `InstructorPolicy` visibility rules. Non-public or non-bookable profiles remain blocked for ordinary public viewers.

## Filters

The directory now supports safe public filters for:

- keyword search over name, headline, short bio, bio, public subject master names, and legacy subject text
- subject using `subjects.id` where available
- legacy subject fallback for unreconciled `teacher_subjects.subject`
- academic level using `AcademicLevel`
- teaching language using `Language` master data where available
- legacy profile language fallback
- country from `user_profiles.country_id`
- timezone from `user_profiles.timezone`
- existing availability preview filter remains unchanged
- sort by featured, name, or newest

Keyword search deliberately avoids private KYC fields, admin review notes, internal lifecycle reasons, and other sensitive verification data.

Country filter options are scoped to the same public instructor visibility boundary as the listing: active users, instructor role, public profile visibility, and bookable instructor statuses. Countries that belong only to hidden, inactive, rejected, suspended, archived, vacation, or otherwise non-bookable instructors are not shown as filter options.

## Subject Master Usage

Subject filters prefer `teacher_subjects.subject_id` and the linked `Subject` master record. When a legacy teacher subject has not been reconciled, the old `teacher_subjects.subject` value remains usable as a fallback.

New marketplace code does not introduce a free-text subject system and does not create another subject table.

## Favorite Instructors

Favorite and unfavorite behavior reuses the Phase 3 `StudentFavoriteInstructorService` and the existing `student_favorite_instructors` table.

Directory cards and profile pages provide favorite actions:

- authenticated students can favorite or unfavorite bookable instructors
- guests posting a favorite action are redirected by the authenticated dashboard route
- authenticated instructors, managers, admins, and other non-student users do not see student favorite actions in marketplace UI
- duplicate favorites remain prevented by the existing table and service
- non-bookable instructors cannot be favorited
- students cannot favorite themselves

Server-side favorite routes still call `StudentFavoriteInstructorService`, which rejects non-student users even if they manually post to the route. Admin-portal users are also kept out of the frontend favorite routes by existing portal middleware.

No duplicate wishlist or favorite table was created.

## SEO And Public Metadata

The instructor directory includes public-safe meta descriptions and Open Graph metadata. Public instructor profiles keep canonical links and avoid exposing KYC media paths, admin review notes, or private verification data.

Non-public or non-bookable profiles remain inaccessible to public users and are not promoted by the directory.

## UI Scope

The Phase 5 UI changes are intentionally light:

- expanded filter controls on the public instructor directory
- clearer instructor cards with profile, academic level, location/timezone, and favorite actions
- profile page teaching approach content sourced from public profile fields
- guest favorite actions redirect to login through existing middleware

This phase does not rewrite marketplace search, build a recommendation engine, or create booking entry flows.

## Admin Scope

No new Filament marketplace resource was created.

Marketplace discovery continues to rely on existing admin-managed records:

- users and user profiles
- subject master data
- academic levels
- languages
- countries
- instructor lifecycle/admin review surfaces

## Out Of Scope

Phase 5 intentionally does not expand:

- availability engine
- booking engine
- recurring lesson scheduling
- wallet
- payment
- meeting
- homework
- public reviews
- referrals
- AI recommendations
- packages
- subscriptions

Existing modules may still be present from prior phases, but marketplace discovery does not create records or workflows in those domains.

## Tests

Phase 5 adds focused coverage in `tests/Feature/Instructor/MarketplaceDiscoveryFoundationTest.php` for:

- listing only active public bookable instructors
- excluding non-bookable, inactive, and private instructors
- subject master filtering and legacy fallback
- academic level, language, country, timezone, and keyword filters
- scoped country filter options
- public profile SEO and sensitive data safety
- favorite actions from listing/profile and guest redirects
- favorite button visibility for guests, students, instructors, and admins
- non-student route-level favorite rejection
- duplicate, self, and non-bookable favorite protection
- no out-of-scope records or duplicate domain tables

Existing instructor listing/detail/service and student favorite instructor tests continue to cover older flows.

## Remaining Gaps

Future phases may add:

- real availability-based ranking
- booking conversion flows
- recommendation scoring
- richer public review signals
- browser-level marketplace UX tests
- stronger visual QA for responsive marketplace cards and filter density

Those are intentionally deferred until the related domain foundations are ready.
