# CMS

## Overview

The CMS bounded context lives in `app/Content/` with namespace `App\Content\`. It provides polymorphic content blocks shared by Pages and Posts.

## Content Blocks

`App\Content\Models\ContentBlock` — polymorphic (owned by `Page` or `Post` via `blockable` morph).

23 block types defined in `App\Enums\BlockType`:
Hero, RichText, Image, Gallery, Video, CTA, FAQ, Accordion, Tabs, Team, Features, FeaturedTeachers, Testimonials, Pricing, Newsletter, Statistics, Timeline, Button, Divider, Spacer, Map, ContactForm, ContactInfo.

Each block type has a corresponding form schema class in `app/Forms/Blocks/`.

## Rendering

```
PageRenderService::render($page)      ← extends ContentRenderer
→ ContentBlockService::getBlocksForPage()
→ BlockRenderer::render($block)       ← dispatches by BlockType
→ Blade view (resources/views/components/blocks/{type}.blade.php)
```

`ContentRenderer` is the abstract base. `PageRenderService` extends it and is registered as a singleton in `CmsServiceProvider`.

## SEO

`SeoManager` handles per-page/post meta. Priority: Page/Post fields → `SeoSettings` defaults → hardcoded defaults.

Sitemap: `/sitemap.xml` — auto-generated from published pages and posts.

## Contact form

`ContactFormController::submit()` handles frontend contact form submissions from `ContactForm` content blocks.

Activity logged via `AuditTrailService::logGuest()` — captures guest name, email, phone from submitted fields.

## Key files

| File | Purpose |
|---|---|
| `app/Content/Models/ContentBlock.php` | Block model, polymorphic |
| `app/Content/Services/ContentBlockService.php` | Block CRUD and ordering |
| `app/Content/Rendering/ContentRenderer.php` | Abstract rendering base |
| `app/Services/PageRenderService.php` | Page rendering (singleton) |
| `app/Http/Controllers/ContactFormController.php` | Contact form submission |
| `app/Enums/BlockType.php` | All 24 block types |

## Observers

`ContentBlock` has an observer registered in `CmsServiceProvider`.
`Page` and `Post` have observers in `AppServiceProvider`.
