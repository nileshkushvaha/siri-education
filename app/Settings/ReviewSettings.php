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

    /** 'pre_moderation' | 'post_moderation' | 'risk_based' — governs automatic moderation of newly submitted reviews. */
    public string $moderation_model;

    /** Master override: when off, nothing auto-publishes regardless of moderation_model. */
    public bool $auto_publish_clean_reviews;

    /** 'anonymous' | 'first_name_initial' | 'first_name_only' — how a reviewing student is labeled on a public profile. */
    public string $public_review_identity_mode;

    /** Master switch — false blocks new report submissions for every review (existing reports are unaffected). */
    public bool $review_reporting_enabled;

    /** Master switch — false blocks every quality-alert detector (conservative default: off). */
    public bool $quality_alerts_enabled;

    /** Inclusive — an overall rating at or below this on a published public review may trigger a low-rating signal. */
    public int $low_rating_threshold;

    /** Whether a single low-rated published review may create its own (low-severity) alert, independent of the repeated-count threshold. */
    public bool $single_low_rating_alert_enabled;

    /** How many low published reviews within the rolling window trigger a repeated-low-rating alert. */
    public int $repeated_low_rating_count;

    public int $repeated_low_rating_window_days;

    /** How many InstructorNoShow outcomes within the rolling window trigger a repeated-no-show alert. */
    public int $repeated_no_show_count;

    public int $repeated_no_show_window_days;

    /** How many instructor-attributed (Host) booking cancellations within the rolling window trigger a repeated-cancellation alert. */
    public int $repeated_cancellation_count;

    public int $repeated_cancellation_window_days;

    /**
     * Dashboard-only classification thresholds — deliberately distinct
     * from `low_rating_threshold` (an integer *per-review* rating
     * cutoff that drives alert detection). These are floats compared
     * against an instructor's current *aggregate average*, purely for
     * the admin dashboard's "low/highly rated instructors" lists; they
     * never feed detection and are never conflated with the alert
     * threshold above.
     */
    public float $quality_dashboard_low_rating_threshold;

    public float $quality_dashboard_high_rating_threshold;

    /** Minimum eligible published review count before an instructor's aggregate average is trusted enough to appear in either dashboard list. */
    public int $quality_dashboard_min_review_count;

    public static function group(): string
    {
        return 'reviews';
    }
}
