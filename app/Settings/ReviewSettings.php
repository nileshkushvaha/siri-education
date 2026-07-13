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

    public static function group(): string
    {
        return 'reviews';
    }
}
