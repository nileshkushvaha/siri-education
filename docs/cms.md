# CMS

## Overview

The CMS handles three types of publishable content: **Pages**, **Posts**, and **Content Blocks**. Content Blocks are polymorphic — they attach to either a Page or a Post.

```
Page ──── ContentBlock (morph: 'page')
Post ──── ContentBlock (morph: 'post')
Post ──── PostCategory (pivot)
Post ──── Tag (pivot)
Post ──── Post (pivot: related_posts)
```

## Bounded context: `App\Content\`

Content block logic lives in a dedicated namespace, separate from `App\Models\`:

```
app/Content/
├── Contracts/
│   └── HasContentBlocks.php      — interface for Page and Post models
├── Models/
│   └── ContentBlock.php          — namespace: App\Content\Models
├── Rendering/
│   └── ContentRenderer.php       — abstract: renderBlock(BlockType, array): string
├── SEO/
│   └── SeoManager.php            — meta tags, OG, JSON-LD, sitemap entries
└── Services/
    └── ContentBlockService.php   — CRUD for blocks, reorder, duplicate
```

**Why isolated?** The content block system is complex enough to warrant its own namespace. It can be developed and tested without touching `App\Models\` or `App\Services\`. The `ContentBlockObserver` (registered in `CmsServiceProvider`) and the `ContentBlockPolicy` (in `app/Policies/`) are the bridge points.

## Content Blocks

`App\Content\Models\ContentBlock` — polymorphic, owned by a `Page` or `Post` via the `blockable` morph. Key fields:

- `blockable_type` / `blockable_id` — morph to Page or Post
- `type` — `BlockType` enum
- `content` — JSON, stored via `BlockContentConverter`, read via `BlockContentHydrator`
- `position` — integer sort order (before/after body, or numbered)
- `is_active` — bool

### Morph map

Registered in `CmsServiceProvider::registerMorphMap()`:

```php
Relation::morphMap([
    'page' => Page::class,
    'post' => Post::class,
    'category' => PostCategory::class,
    'tag' => Tag::class,
]);
```

The DB stores `'page'`, not `'App\\Models\\Page'`. Always use morph map keys when querying, never raw class strings.

### Block types

23 block types defined in `App\Enums\BlockType`: Hero, RichText, Image, Gallery, Video, CTA, FAQ, Accordion, Tabs, Team, Features, FeaturedTeachers, Testimonials, Pricing, Newsletter, Statistics, Timeline, Button, Divider, Spacer, Map, ContactForm, ContactInfo. Each has a corresponding form schema class in `app/Forms/Blocks/`.

## Pages and Posts

`app/Models/Page.php` (UUID PK, soft deletes) — `status` (`PageStatus`: Draft/Published/Scheduled/Archived), `visibility` (`PageVisibility`: Public/Private), `template`, `published_at`, `is_homepage` (only one page can be homepage), SEO fields (`meta_title`, `meta_description`, `og_image`, `canonical_url`). `PageObserver` handles slug generation, `published_at` tracking, and homepage-uniqueness enforcement.

`app/Models/Post.php` (UUID PK, soft deletes, Spatie Media Library) adds: `author_id` (credited author, separate from `creator_id`), `categories()`/`tags()` (BelongsToMany), `relatedPosts()` (self-referential BelongsToMany), `featured_image_url` (Media Library attribute). `PostObserver` handles slug generation and activity logging on status changes.

The `PublishScheduledContent` command runs every minute and publishes any Page or Post whose `published_at <= now()` and status is `scheduled`.

## Data flow: block storage

Saving a block in Filament:

```
Filament form → BlockFormSchemaFactory::getSchema(BlockType)
             → form-specific class in app/Forms/Blocks/
             → BlockContentConverter::convert(BlockType, $formData)  ← normalizes to JSON
             → stored in content_blocks.content (JSON column)
```

Loading a block for editing:

```
content_blocks.content (JSON)
    → BlockContentHydrator::hydrate(BlockType, $jsonContent)
    → form-friendly array with defaults for all fields
    → Filament form fields populated
```

`BlockContentConverter` and `BlockContentHydrator` are the single source of truth for each block's data shape. When adding a new block type, both need a new case.

## Rendering pipeline

```
PageController / PostController
    → PageRenderService::render($page)       ← extends ContentRenderer
    → ContentBlockService::getBlocksForPage($page)
    → for each active block:
        BlockRenderer::render($block)
            → loads Blade component: resources/views/components/blocks/{type}.blade.php
            → passes $content (hydrated array) as view data
    → wraps in page template layout
```

`PageRenderService` is bound as a singleton in `CmsServiceProvider`:

```php
$this->app->singleton(PageRenderService::class);
$this->app->bind(ContentRenderer::class, PageRenderService::class);
```

## SEO

`SeoManager` (`app/Content/SEO/SeoManager.php`) generates `<title>`/meta description/canonical, Open Graph tags, JSON-LD structured data, and robots meta (`SeoSettings::robots_default` + page override). Priority: Page/Post fields → `SeoSettings` defaults → hardcoded defaults.

Sitemap and robots.txt are served by `SeoController` (`routes/web.php`): `GET /sitemap.xml`, `GET /robots.txt` — auto-generated from published pages and posts.

## Policy pages

Four legal pages required for payment-gateway merchant review, seeded by
`PolicyPagesSeeder` (`php artisan db:seed --class=PolicyPagesSeeder`):

| Page | URL |
|---|---|
| Terms and Conditions | `/terms-and-conditions` |
| Privacy Policy | `/privacy-policy` |
| Cancellation and Refund Policy | `/cancellation-and-refund-policy` |
| Shipping and Exchange Policy | `/shipping-and-exchange-policy` |

Ordinary CMS pages carrying prose in `content` (the About Us shape), not a
block stack. Create-only on re-run, like `CountryLandingPageSeeder`.

Deliberate constraints:

- **No `data-cms-structured-page` marker.** It makes
  `StructuredPageContentService` revert any update that drops it, which would
  stop counsel replacing a legal document wholesale from the admin.
- **No hardcoded cancellation window or refund timeline.**
  `BookingSettings::cancellation_window_hours` is admin-editable, and refund
  arrival time belongs to the bank. Tests assert both stay out of the copy.
- **`[REPLACE: ...]` placeholders** mark every value that must match identity
  documents. Search for them before going live.
- **The operator is described as an individual sole proprietor**, not a
  registered company, because that is the current position. Gateways verify
  the merchant name against the ID and bank account behind the payout. If a
  company is later incorporated, the entity sentences in `terms()` and
  `privacy()` are the only places to change — a test asserts the current
  wording so the change cannot be forgotten.

`/terms-of-service` 301s to `/terms-and-conditions` via a managed `Redirect`
row; it is inert wherever a real page still occupies that slug, since
redirects resolve from the 404 handler.

Shared styling is one rule set (`.cms-content .policy-document`) in
`resources/css/app.css` — the pages hold semantic HTML only, because Tailwind
does not scan DB content for utility classes.

## Contact form

`ContactFormController::submit()` handles frontend contact-form submissions from `ContactForm` content blocks. Activity is logged via `AuditTrailService::logGuest()` — captures guest name, email, and phone from the submitted fields.

## Navigation

Navigation menus are separate from CMS content — managed via the `Navigation` Filament resource and rendered by `NavigationRenderer`. See `navigation.md`.

## Adding a new block type

1. Add a case to `app/Enums/BlockType.php`
2. Create `app/Forms/Blocks/{Name}BlockForm.php` — static `schema()` method returning form fields
3. Add a case to `BlockFormSchemaFactory::getSchema()` (`app/Forms/BlockFormSchemaFactory.php`)
4. Add a case to `BlockContentConverter::convert()` — form array → stored JSON
5. Add a case to `BlockContentHydrator::hydrate()` — stored JSON → form array (with defaults)
6. Add a case to `BlockRenderer::render()` — determines which Blade component to use
7. Create `resources/views/components/blocks/{name}.blade.php` — the rendered HTML

All 7 steps are required — the type system throws on a missing match arm if any is skipped.

## Key files

| File | Purpose |
|---|---|
| `app/Content/Models/ContentBlock.php` | Block model, polymorphic |
| `app/Content/Services/ContentBlockService.php` | Block CRUD and ordering |
| `app/Content/Rendering/ContentRenderer.php` | Abstract rendering base |
| `app/Services/PageRenderService.php` | Page rendering (singleton) |
| `app/Http/Controllers/ContactFormController.php` | Contact form submission |
| `app/Enums/BlockType.php` | All 23 block types |

## Observers

`ContentBlock` has an observer registered in `CmsServiceProvider`. `Page` and `Post` have observers in `AppServiceProvider`.
