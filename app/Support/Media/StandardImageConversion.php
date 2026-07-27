<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\Image\Enums\Fit;

/**
 * The single place standard image-conversion
 * names and dimensions are defined, reused by every applicable Media
 * Library collection instead of duplicating conversion definitions
 * per model. Three sizes cover every evidence-backed use found in the
 * codebase:
 *
 *  - Thumb: fixed 150x150 square crop for avatar/card/logo icons,
 *    where visual consistency across a grid/list matters more than
 *    avoiding upscale of a small original;
 *  - Display: up to 800px, aspect-ratio preserved, never upscaled —
 *    for larger public banners/hero images (cover, instructor cover,
 *    blog featured image);
 *  - Preview: up to 400px, aspect-ratio preserved, never upscaled —
 *    for small inline previews of otherwise-downloadable private
 *    files (homework resources, message attachments).
 */
enum StandardImageConversion: string
{
    case Thumb = 'thumb';
    case Display = 'display';
    case Preview = 'preview';

    public function width(): int
    {
        return match ($this) {
            self::Thumb => 150,
            self::Display => 800,
            self::Preview => 400,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Thumb => 150,
            self::Display => 800,
            self::Preview => 400,
        };
    }

    /**
     * Thumb is a fixed-size crop (may upscale a small original to keep
     * grid/list icons visually consistent); Display and Preview
     * preserve aspect ratio and never upscale beyond the original
     * (Spatie\Image\Enums\Fit::Max = PreserveAspectRatio + DoNotUpsize).
     */
    public function fit(): Fit
    {
        return match ($this) {
            self::Thumb => Fit::Crop,
            self::Display, self::Preview => Fit::Max,
        };
    }
}
