<?php

declare(strict_types=1);

namespace App\Support\Media\Concerns;

use App\Support\Media\StandardImageConversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * GAP-037 requirement #1 — reusable conversion registration so no
 * model duplicates the fit/dimension/quality definitions in
 * StandardImageConversion. A model calls one (or more) of the
 * `add*Conversion()` helpers from its own `registerMediaConversions()`,
 * naming the collections it applies to.
 *
 * Every helper stays on whichever disk the original media is already
 * on (never forces public/private) and calls `keepOriginalImageFormat()`
 * so a transparent PNG/animated GIF logo or avatar isn't silently
 * flattened to JPEG. Quality/optimization are Spatie Media Library's
 * own conversion defaults (best-effort `spatie/image-optimizer` run,
 * silently skipped for any missing binary — never a hard failure).
 */
trait HasStandardImageConversions
{
    protected function addThumbConversion(string ...$collections): void
    {
        $this->addStandardImageConversion(StandardImageConversion::Thumb, $collections);
    }

    protected function addDisplayConversion(string ...$collections): void
    {
        $this->addStandardImageConversion(StandardImageConversion::Display, $collections);
    }

    protected function addPreviewConversion(string ...$collections): void
    {
        $this->addStandardImageConversion(StandardImageConversion::Preview, $collections);
    }

    /**
     * Requirement #3 — never attempt an image conversion against
     * non-image media (PDFs, arbitrary documents). Spatie's own
     * generator-availability check (Imagick absent in this app) would
     * currently skip PDFs anyway, but that's an environment accident,
     * not a guarantee — this explicit mime check is the real, durable
     * boundary. `$media` is null only during conversion-name
     * introspection (no concrete file yet), so nothing is skipped in
     * that case — only an actual non-image file skips.
     */
    protected function skipStandardImageConversions(?Media $media): bool
    {
        return $media !== null && ! str_starts_with((string) $media->mime_type, 'image/');
    }

    /** @param list<string> $collections */
    private function addStandardImageConversion(StandardImageConversion $conversion, array $collections): void
    {
        $this->addMediaConversion($conversion->value)
            ->performOnCollections(...$collections)
            ->fit($conversion->fit(), $conversion->width(), $conversion->height())
            ->keepOriginalImageFormat();
    }

    /**
     * Requirement #5 — the "safe original/fallback path" every updated
     * view uses: Spatie's own `getFirstMediaUrl($collection, $conversion)`
     * already falls back to the ORIGINAL's URL when the named
     * conversion hasn't been generated yet (still queued), and to the
     * collection's configured `useFallbackUrl()` placeholder (or '')
     * when no media exists at all — this helper only exists so callers
     * spell the conversion name via the shared enum instead of a raw
     * string.
     */
    public function urlForStandardConversion(string $collection, StandardImageConversion $conversion): string
    {
        return $this->getFirstMediaUrl($collection, $conversion->value);
    }
}
