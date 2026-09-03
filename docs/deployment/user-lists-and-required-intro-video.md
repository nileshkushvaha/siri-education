# Deploy runbook — role-scoped user lists, required introduction video, test/schema fixes

Covers the change set on `fix/failing-tests-and-role-scoped-user-lists`
plus the admin-list work already on `master`. Read the ordering section
before starting: two steps are order-dependent and one of them 500s the
applicant wizard if done early.

## What ships

| Area | Change |
|---|---|
| Admin | People → All Users / Students / Instructors; search-bar section replaces the filter panel; Posts column dropped, Mobile + Country added |
| Instructor onboarding | `introduction_video` becomes a **required** document; its upload moves from the Profile step to Documents |
| Schema | `lesson_ai_summaries.lesson_id` CASCADE → RESTRICT (new alter migration) |
| Bug fixes | Operational-alerts 500, HeroCarousel default content, contact-form block 500 outside a request, settings `migrate:reset` failure |

## Not verified before deploy

- **The full test suite has not been run against this change set.** 709
  tests pass (every file touched, plus `tests/Unit` and
  `tests/Feature/Architecture` in full); roughly 7,000 others have not
  re-run. `scratchpad/chunks.sh` runs `tests/Feature` in four logged
  chunks if you want the sweep before shipping.
- **The admin UI has not been looked at.** The search bar and reworked
  columns are asserted in tests only.

## Ordering that matters

1. **Code before the flag.** The old Blade had no `introduction_video`
   arm in its collection→property `match`. Flipping the requirement
   before the code is live throws `UnhandledMatchError` on the
   applicant's Documents step.
2. **`optimize:clear` before anything else cached.** `/admin/students`
   and `/admin/instructors` are new routes; a stale route/config cache
   hides them.
3. **`npm run build` is mandatory, not optional.** The search bar uses
   new Tailwind classes and a new `theme.css` rule. Without a rebuild the
   lists render unstyled.

## Steps

```bash
git merge fix/failing-tests-and-role-scoped-user-lists
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force          # one new migration (the FK rule)

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components   # only if cached in prod
php artisan queue:restart
```

No `shield:generate` needed: `config/filament-shield.php` uses
`'subject' => 'model'`, and both new resources target `User`, so the
existing `View:User` etc. and `UserPolicy` govern them. No new permission
rows, no role re-grants.

## The one manual step

`InstructorDocumentRequirementSeeder` uses `firstOrCreate` **by design**
— these rows are admin-editable and re-seeding must not stomp
configuration. So on any database where it has already run, re-seeding
will **not** flip `introduction_video`:

- **Preferred:** People → Instructors → Document Requirements →
  Introduction Video → tick Required. This is the admin-owned path the
  seeder is built to respect.
- **Or:** `UPDATE instructor_document_requirements SET required = 1 WHERE
  collection_name = 'introduction_video';`

A fresh environment that has never run the seeder needs nothing — all six
rows seed as required.

## Post-deploy checks

1. `/admin/operational-alerts` loads (was a 500 — the missing `label()` arm).
2. People → All Users / Students / Instructors each load; typing a name,
   a phone number, or `pending` narrows the list.
3. Instructor wizard Documents step shows six cards, the video card
   accepts MP4 and shows video help text.
4. A page containing a Contact Form block renders in preview.

## Tell the instructors

Applications can no longer be submitted without an introduction video.
Anyone in **Draft** or **DocumentsPending** gains a new blocking
requirement, and every instructor's completion percentage shifts
(denominator 14 → 15). Already-**Submitted** applications are unaffected —
`missingRequiredItems()` is only enforced at submit.

## Rollback

The FK migration reverses cleanly (`migrate:rollback` restores CASCADE).
Everything else is code. Untick Required on the video requirement to
unblock submissions without a code rollback.
