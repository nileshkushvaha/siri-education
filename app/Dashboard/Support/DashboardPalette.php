<?php

declare(strict_types=1);

namespace App\Dashboard\Support;

/**
 * The single domain → colour map for the whole dashboard.
 *
 * Presentation only: it holds no metric, reads no data and makes no
 * permission decision. It exists so a domain looks identical on its KPI
 * card, its chart, its summary and its report tile — assigning colour in
 * each Blade partial independently is exactly how those drift apart.
 *
 * Colour lives here rather than on the composition DTOs so the payload
 * stays free of presentation concerns (and the cached payload does not
 * grow a field that only the view cares about).
 *
 * The returned class name resolves to an `--a`/`--b` gradient pair
 * defined once in the panel theme's dashboard layer.
 */
final class DashboardPalette
{
    private const string FALLBACK = 'dash-d-brand';

    /**
     * @param  string|null  $key  A KPI key, chart key, summary key or report key.
     */
    public static function domainClass(?string $key): string
    {
        return match ($key) {
            // Operations — bookings, lessons, meetings.
            'bookings_scheduled', 'bookings_per_day', 'lesson_outcomes',
            'operations', 'booking_lesson_meeting_operations',
            'booking_lesson_kpis', 'meeting_reliability' => 'dash-d-ops',

            // Delivered learning outcomes read as healthy, not neutral.
            'lessons_completed' => 'dash-d-healthy',

            // Students and learning share one ramp — they are one journey.
            'engaged_students', 'student_registrations', 'student_engagement',
            'learning', 'homework_activity', 'learning_progress',
            'learning_plan_report', 'homework_report' => 'dash-d-students',

            // Growth and acquisition carry the SIRI brand ramp.
            'new_students', 'demo_to_paid_conversion',
            'instructor_performance', 'executive_summary',
            'referral_activity' => 'dash-d-brand',

            // Marketplace supply/demand.
            'active_instructors', 'supply_demand', 'marketplace',
            'marketplace_supply_demand' => 'dash-d-market',

            // Quality and trust.
            'quality', 'reviews_quality_dashboard',
            'review_quality_analytics' => 'dash-d-quality',

            // Money.
            'money', 'finance_overview', 'payment_outcomes', 'wallet_activity',
            'refund_report', 'recharge_monitoring',
            'earnings_settlements' => 'dash-d-finance',

            default => self::FALLBACK,
        };
    }

    /**
     * Colour for an Administration shortcut.
     *
     * Keyed on the link's label because these are not domains — they are
     * panel destinations, and `administrationLinks()` returns no key.
     * The ramps are drawn from the same eight defined for the dashboard,
     * so the row stays cohesive with everything above it rather than
     * introducing a seventh unrelated palette.
     *
     * Administration remains deliberately secondary: the colour lives in
     * a small icon capsule, not in the card surface.
     */
    public static function administrationClass(?string $label): string
    {
        return match ($label) {
            'Create user' => 'dash-d-brand',
            'Users' => 'dash-d-students',
            'Settings' => 'dash-d-ops',
            // Security genuinely is the risk-adjacent destination here.
            'Security' => 'dash-d-critical',
            'Activity log' => 'dash-d-market',
            'Login history' => 'dash-d-quality',
            default => self::FALLBACK,
        };
    }
}
