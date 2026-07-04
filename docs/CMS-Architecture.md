# CMS Architecture

## Overview

The CMS handles three types of publishable content: **Pages**, **Posts**, and **Content Blocks**. Content Blocks are polymorphic — they can attach to either Pages or Posts.

```
Page ──── ContentBlock (morph: 'page')
Post ──── ContentBlock (morph: 'post')
Post ──── PostCategory (pivot)
Post ──── Tag (pivot)
Post ──── Post (pivot: related_posts)
```

---

## Bounded Context: `App\Content\`

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

**Why isolated?** The content block system is complex enough to warrant its own namespace. It can be developed and tested without touching `App\Models\` or `App\Services\`. The `ContentBlockObserver` (in `CmsServiceProvider`) and the `ContentBlockPolicy` (in `app/Policies/`) are the bridge points.

---

## Pages

`app/Models/Page.php` — UUID primary key, soft deletes.

Key fields:
- `status` — `PageStatus` enum: Draft, Published, Scheduled, Archived
- `visibility` — `PageVisibility` enum: Public, Private
- `template` — string key for the Blade template to use
- `published_at` — nullable; used for scheduled publishing
- `is_homepage` — bool; only one page can be homepage
- SEO fields: `meta_title`, `meta_description`, `og_image`, `canonical_url`

Relationships: `blocks()` (all), `activeBlocks()` (only published, sorted), `creator()`, `updater()`.

`PageObserver` fires on create/update to handle slug generation, published_at tracking, and homepage uniqueness enforcement.

The `PublishScheduledContent` command runs every minute and publishes Pages (and Posts) whose `published_at <= now()` and status is `scheduled`.

---

## Posts

`app/Models/Post.php` — UUID primary key, soft deletes, Spatie Media Library.

Key additions over Page:
- `author_id` → `User` (the credited author, separate from `creator_id`)
- `categories()` — BelongsToMany `PostCategory`
- `tags()` — BelongsToMany `Tag`
- `relatedPosts()` — BelongsToMany `Post` (self-referential)
- `featured_image_url` — Attribute from Media Library

`PostObserver` handles slug generation and activity logging on status changes.

---

## Content Blocks

`app/Content/Models/ContentBlock.php` — UUID primary key, morphable, soft deletes.

Key fields:
- `blockable_type` / `blockable_id` — morph to Page or Post
- `type` — `BlockType` enum (19 values)
- `content` — JSON (stored via `BlockContentConverter`, read via `BlockContentHydrator`)
- `position` — integer sort order (before/after body, or numbered)
- `is_active` — bool

### Morph Map

Registered in `CmsServiceProvider::registerMorphMap()`:

```php
Relation::morphMap([
    'page' => Page::class,
    'post' => Post::class,
    'category' => PostCategory::class,
    'tag' => Tag::class,
]);
```

This means the DB stores `'page'` not `'App\\Models\\Page'`. Always use morph map keys when querying, never raw class strings.

---

## BlockType Enum

`app/Enums/BlockType.php` — 24 block types:

| Enum case | Description |
|---|---|
| `Hero` | Full-width hero with headline, subtext, image, CTA buttons |
| `RichText` | WYSIWYG content block |
| `Image` | Single image with caption |
| `Gallery` | Multi-image grid |
| `Video` | Embed URL (YouTube, Vimeo) |
| `CTA` | Call-to-action banner |
| `FAQ` | Accordion list of Q&A pairs |
| `Accordion` | Collapsible content sections |
| `Tabs` | Tabbed content |
| `Team` | Team member cards |
| `Features` | Feature cards with optional links |
| `FeaturedCourses` | CMS-authored featured course cards |
| `FeaturedTeachers` | Featured public instructors from the instructor service |
| `Testimonials` | Testimonial cards |
| `Pricing` | Pricing plan cards |
| `Newsletter` | Newsletter signup section |
| `Statistics` | Stat counter cards |
| `Timeline` | Vertical timeline entries |
| `Button` | Standalone button |
| `Divider` | Horizontal rule with style options |
| `Spacer` | Vertical whitespace |
| `Map` | Embedded map |
| `ContactForm` | Lead capture form |
| `ContactInfo` | Address/phone/email block |

---

## Data Flow: Block Storage

When an admin saves a block in Filament:

```
Filament form → BlockFormSchemaFactory::getSchema(BlockType)
             → form-specific class in app/Forms/Blocks/
             → BlockContentConverter::convert(BlockType, $formData)  ← normalizes to JSON
             → stored in content_blocks.content (JSON column)
```

When a block is loaded for editing:

```
content_blocks.content (JSON)
    → BlockContentHydrator::hydrate(BlockType, $jsonContent)
    → form-friendly array with defaults for all fields
    → Filament form fields populated
```

`BlockContentConverter` and `BlockContentHydrator` are the single source of truth for each block's data shape. When adding a new block type: add cases to both classes and a new form class in `app/Forms/Blocks/`.

---

## Rendering Pipeline

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

---

## SEO

`SeoManager` (`app/Content/SEO/SeoManager.php`) generates:
- `<title>`, `<meta description>`, canonical
- Open Graph tags (`og:title`, `og:description`, `og:image`)
- JSON-LD structured data
- Robots meta (from `SeoSettings::robots_default` + page override)

Sitemap and robots.txt are served by `SeoController` (`routes/web.php`):
- `GET /sitemap.xml` → `SeoController::sitemap()`
- `GET /robots.txt` → `SeoController::robots()`

---

## Navigation

See the Navigation section in [Architecture.md](Architecture.md) for the full navigation system. Navigation menus are separate from CMS content — they're managed via the `Navigation` Filament resource and rendered by `NavigationRenderer`.

---

## Adding a New Block Type

1. Add case to `app/Enums/BlockType.php`
2. Create `app/Forms/Blocks/{Name}BlockForm.php` — static `schema()` method returning form fields
3. Add case to `BlockFormSchemaFactory::getSchema()` (`app/Forms/BlockFormSchemaFactory.php`)
4. Add case to `BlockContentConverter::convert()` — form array → stored JSON
5. Add case to `BlockContentHydrator::hydrate()` — stored JSON → form array (with defaults)
6. Add case to `BlockRenderer::render()` — determines which Blade component to use
7. Create `resources/views/components/blocks/{name}.blade.php` — the rendered HTML

All 7 steps are required. The type system will throw on missing match arms if you miss one.
