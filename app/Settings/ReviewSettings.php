<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ReviewSettings extends Settings
{
    /** Platform-wide switch — false blocks eligibility creation for every lesson type. */
    public bool $reviews_enabled;

    /** Paid completed lessons open public-review eligibility when this is also on. */
    public bool $paid_lesson_reviews_enabled;

    /** 'disabled' | 'private_only' | 'public' — governs completed demo lessons. */
    public string $demo_review_policy;

    /** Days after lesson completion an open eligibility window stays valid. */
    public int $review_window_days;

    /** Inclusive rating scale bounds — overall and every dimension rating must fall within [min, max]. */
    public int $rating_min;

    public int $rating_max;

    /** Written text is optional unless this is on. */
    public bool $written_review_required;

    /** Written text length bounds (characters), enforced only when text is present (or always, when required). */
    public int $review_min_length;

    public int $review_max_length;

    /** Whether the five optional per-dimension ratings may be submitted at all. */
    public bool $rating_dimensions_enabled;

    /** Maximum number of tags a single submission may select. */
    public int $review_max_tags;

    public static function group(): string
    {
        return 'reviews';
    }
}
