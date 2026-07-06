# Phase 5.1 Marketplace Discovery Audit

## Executive Decision

Readiness score: **92/100**

Decision: **SAFE TO PROCEED WITH NEXT PHASE PLANNING**

Do not start availability, booking, wallet, payment, meeting, homework, public reviews, referrals, AI recommendations, packages, or subscriptions yet unless that is explicitly selected as the next phase.

Phase 5 successfully strengthens marketplace discovery while reusing the existing instructor, profile, subject, academic-level, language, country, and favorite-instructor foundations. The implementation keeps public instructor discovery read-side, preserves `Subject` as the academic source of truth, keeps legacy `TeacherSubject.subject` fallback behavior, and does not create duplicate marketplace, instructor, student, favorite, booking, payment, wallet, homework, or review structures.

## Blocking Issues

None.

## Non-Blocking Issues

1. **Favorite button is visible to authenticated non-student frontend users on directory cards.** The service still rejects non-student favorite attempts, so this is not a data or authorization issue. It is a UX polish item: directory cards should ideally show the favorite action only to guests and student users, not to authenticated instructors.
2. **Country filter options are based on public/bookable profile fields but do not additionally require the related user to be active and instructor-role scoped.** The actual listing query remains correctly scoped, so no non-instructor users are listed. The risk is only a stale/empty country option in unusual data states.
3. **No browser-level marketplace test exists.** Feature tests cover behavior well, but there is no Playwright-style visual/browser coverage for filter UI, favorite buttons, or responsive card layout.
4. **Availability remains preview/filter-only.** This is acceptable because it reuses existing availability data and does not expand the availability engine, but Phase 5 should not be mistaken for real availability-based ranking or booking conversion.

## Phase 4.3 Gate

Verified prerequisite:

- File: `docs/audits/phase-4-learning-plan-final-audit.md`
- Score: 96/100
- Decision: SAFE TO PROCEED TO PHASE 5
- Blocking issues: none

## Files Created In Phase 5

| File | Purpose | Necessary | Duplicate Risk |
|---|---|---:|---:|
| `docs/architecture/phase-5-marketplace-discovery-foundation.md` | Architecture record for marketplace discovery boundaries and reuse decisions. | Yes | None |
| `tests/Feature/Instructor/MarketplaceDiscoveryFoundationTest.php` | Feature coverage for marketplace visibility, filters, favorites, SEO safety, and duplicate prevention. | Yes | None |

## Files Modified In Phase 5

| File | Change | Why | Backward-Compatible |
|---|---|---|---:|
| `app/Services/Instructor/InstructorService.php` | Added safe filter handling for subject master data, legacy subject fallback, academic levels, teaching languages, country, timezone, public card metadata, and deterministic featured ordering. | Marketplace discovery service remains the single read-side owner. | Yes |
| `app/Models/Country.php` | Added `userProfiles()` relationship. | Enables country filter option generation. | Yes |
| `resources/views/instructors/index.blade.php` | Expanded directory filters and public SEO copy. | Allows public discovery by academic fields without booking changes. | Yes |
| `resources/views/instructors/show.blade.php` | Added public teaching approach content, canonical/SEO metadata, and guest/student favorite actions. | Improves public profile usefulness without exposing private review/KYC data. | Yes |
| `resources/views/components/instructor/card.blade.php` | Added academic-level, location/timezone, profile link, and favorite action support. | Makes listing cards useful for discovery and favorites. | Yes |

## Migrations

No Phase 5 migrations were added.

Existing tables reused:

- `users`
- `user_profiles`
- `teacher_subjects`
- `subjects`
- `academic_levels`
- `languages`
- `countries`
- `student_favorite_instructors`

## Public Discovery Audit

Confirmed:

- Public listing route remains `GET /instructors`.
- Public profile route remains `GET /instructors/{user:slug}`.
- `InstructorController` stays thin.
- `InstructorService::baseQuery()` gates listing to:
  - active users
  - instructor role
  - public profile visibility
  - `InstructorStatus::bookableValues()`
- Rejected, suspended, archived, submitted, draft, private, inactive, and vacation instructors are not promoted in listings.
- `InstructorService::publicProfile()` still blocks non-bookable/non-public profiles for ordinary viewers.
- No new marketplace controller or search engine layer was created.

## Filter Audit

Implemented and tested:

- keyword search over public-safe fields
- subject filter using `subjects.id`
- legacy fallback using `teacher_subjects.subject`
- academic-level filter using `AcademicLevel`
- teaching-language filter using `Language` master data with legacy profile-language fallback
- country filter using `user_profiles.country_id`
- timezone filter using `user_profiles.timezone`
- existing availability-preview filter remains unchanged
- sort by featured, name, or newest

Confirmed sensitive fields are not searched or displayed:

- KYC media collections
- instructor review reasons
- documents requested reasons
- admin-only verification notes

## Subject Master Audit

Confirmed:

- Marketplace subject filtering prefers `teacher_subjects.subject_id`.
- Legacy free-text subject fallback still works for unreconciled records.
- No new free-text subject input was introduced.
- No duplicate `Subject` model/table/resource was created.
- Existing subject reconciliation tests still pass through the full test suite.

## Favorite Instructor Audit

Confirmed:

- Favorite and unfavorite writes use `StudentFavoriteInstructorService`.
- Existing `student_favorite_instructors` table is reused.
- Duplicate favorites are prevented.
- Students cannot favorite themselves.
- Non-bookable instructors cannot be favorited.
- Guests posting favorite forms are redirected to login by the existing authenticated dashboard route.
- Favorite writes are audit logged through `AuditTrailService`.
- No duplicate wishlist/favorite table was created.

## SEO And Privacy Audit

Confirmed:

- Directory has public-safe meta and Open Graph metadata.
- Public instructor profiles include canonical links and public-safe structured data.
- Private KYC paths are not rendered.
- Admin review notes are not rendered.
- Non-bookable/publicly hidden instructor profiles are blocked for ordinary public users.

## Out-Of-Scope Boundary Audit

Confirmed Phase 5 did not expand:

- availability engine
- booking engine
- recurring lessons
- wallet
- payment
- meeting
- homework
- public reviews
- referrals
- AI recommendations
- packages
- subscriptions

Existing booking, homework, payment-setting, and availability code still exists from prior phases, but Phase 5 did not create new workflows or tables in those domains.

## Duplicate Prevention Check

Confirmed no duplicate tables or models were introduced for:

- `instructors`
- `instructor_profiles`
- `students`
- `student_profiles`
- duplicate subject system
- duplicate favorite/wishlist system
- marketplace-specific instructor table
- booking/payment/wallet/homework/public-review expansion

## Tests

Focused marketplace regression:

- `php artisan test tests/Feature/Instructor/MarketplaceDiscoveryFoundationTest.php tests/Feature/Instructor/InstructorListingTest.php tests/Feature/Instructor/InstructorDetailTest.php tests/Feature/Instructor/InstructorServiceTest.php tests/Feature/Student/StudentFavoriteInstructorTest.php`
- Result: passed, 50 tests, 128 assertions

Full suite:

- `php artisan test`
- Result: passed, 1813 tests, 3955 assertions

Coverage includes:

- public listing visibility
- non-bookable exclusion
- subject master filter
- legacy subject fallback
- academic-level/language/country/timezone filters
- safe keyword search
- profile SEO/privacy safety
- guest favorite redirect
- favorite/unfavorite
- duplicate favorite prevention
- self-favorite denial
- non-bookable favorite denial
- duplicate table prevention
- no booking/homework side effects

## Commands

| Command | Result |
|---|---|
| `php artisan test` | Passed: 1813 tests, 3955 assertions |
| Focused marketplace tests | Passed: 50 tests, 128 assertions |
| `php artisan migrate:status` | Passed; all migrations applied through Phase 4 batch 34 |
| `php artisan route:list` | Passed; 216 routes |
| `./vendor/bin/pint --test` | Passed |
| `composer validate` | Passed |
| `npm run build` | Not run; no JS/CSS asset files changed |

## Recommended Hardening Before Or During Next Phase

1. Hide or disable directory-card favorite buttons for authenticated non-student users.
2. Tighten country filter option generation to require active instructor users, matching the listing base query more closely.
3. Add browser-level marketplace tests once the marketplace UI becomes a conversion-critical surface.
4. Decide the next phase explicitly before adding availability ranking or booking conversion behavior.

## Final Assessment

Phase 5 is complete as a marketplace discovery foundation. It is safe to proceed with next-phase planning, with the clear boundary that booking, availability expansion, payments, wallet, meeting, homework, reviews, referrals, AI, packages, and subscriptions remain out of scope until explicitly approved.
