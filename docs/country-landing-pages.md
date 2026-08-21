# Country Landing Pages

Nine public SEO landing pages, one per active country education system.

## What they are

Ordinary CMS `Page` rows. There is no country controller, no country route,
no country template and no country-specific CSS. Each page:

- resolves through the existing catch-all `GET /{slug}` → `PageController::show`
- renders through `ContentRenderer` from existing `ContentBlock` types
- gets metadata from `SeoManager` and appears in the existing `/sitemap.xml`
- is editable in the existing Filament Pages block editor

## URLs

`/online-tutoring-in-{country-name-slug}` — the slug is derived from the
`countries.name` column, so it follows a rename in the admin.

| Country | URL |
|---|---|
| India | `/online-tutoring-in-india` |
| United States | `/online-tutoring-in-united-states` |
| United Kingdom | `/online-tutoring-in-united-kingdom` |
| Australia | `/online-tutoring-in-australia` |
| Canada | `/online-tutoring-in-canada` |
| United Arab Emirates | `/online-tutoring-in-united-arab-emirates` |
| Singapore | `/online-tutoring-in-singapore` |
| New Zealand | `/online-tutoring-in-new-zealand` |
| Saudi Arabia | `/online-tutoring-in-saudi-arabia` |

## Block stack

Twelve blocks per page, all existing types: Hero → RichText (availability) →
RichText (education system + levels) → RichText (heading) → Timeline (how it
works) → Features (subjects) → Features (benefits) → RichText (heading) →
FAQ → Features (explore more) → Features (other countries) → CTA (demo).

## Terminology

"Class" / "Grade" / "Year" is never written in application or seeder code.
`CountryLandingPageContent` writes `{term}` / `{terms}` / `{levelRange}`
placeholders, and `CountryLandingPageSeeder::fill()` substitutes
`EducationSystem::levelTermSingular()` / `levelTermPlural()` and the
configured `EducationSystemLevel::display_label` values.

Two admin-managed fields feed the pages and are deliberately independent:

- `education_systems.level_term_singular/plural` — the generic word in prose
- `education_system_levels.display_label` — the name of each selectable level

Renaming one does not rewrite the other.

## Seeder

```bash
php artisan db:seed --class=CountryLandingPageSeeder
```

Not registered in `DatabaseSeeder`, matching `AboutUsPageSeeder` and
`ContactUsPageSeeder`.

**Create-only**, following `BookingTypeSeeder`'s stated strategy. An existing
page is left untouched, and blocks are seeded only when a page has none, so a
re-run on production can add a newly activated country without overwriting
editorial changes. To regenerate a page deliberately, delete it (and its
blocks) first.

The country list comes from active `CountryEducationSystem` rows intersected
with the countries that have copy in `CountryLandingPageContent`. Deactivating
a market stops the seeder producing that page; it does not unpublish a page
that already exists.

## SEO

| Element | Source |
|---|---|
| Title / description | Per-country `meta_title` / `meta_description` (fits the 70 / 160 column limits) |
| Canonical | Written explicitly per page — `SeoManager` would otherwise prefer a global `SeoSettings::canonical_url` for all nine |
| Robots | Left `null` so pages inherit the global directive; a hardcoded `index, follow` would defeat a site-wide staging noindex |
| OG / Twitter | `SeoManager` + `layouts.page`; `og:image` comes from the page's `featured-image` media, attached by the seeder when the file exists |
| Structured data | `WebPage` JSON-LD from `SeoManager::getPageStructuredData()` |
| H1 | `layouts.page` renders the page title; the hero block is configured `heading_level: h2` so there is exactly one H1 |

## Images

See `public/images/country-pages/README.md` for filenames and specification.

## Tests

`tests/Feature/Cms/CountryLandingPageTest.php` — resolution, uniqueness,
single H1, per-country terminology, canonical/robots/OG, sitemap inclusion,
internal links, cross-links, alt text, idempotency, and non-overwrite of
admin-edited content.
