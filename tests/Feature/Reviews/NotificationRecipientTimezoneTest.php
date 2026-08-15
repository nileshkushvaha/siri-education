<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Models\Country;
use App\Models\InstructorQualityAlert;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewReport;
use App\Models\User;
use App\Notifications\Quality\InstructorQualityAlertCreatedNotification;
use App\Notifications\Reviews\ReviewReportedNotification;
use App\Notifications\Reviews\ReviewRequestedNotification;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * TZ-3 / TZ-AUD-014 — the three notifications that still rendered a
 * user-facing instant in the server's timezone.
 *
 * Every test renders through the REAL `toMail()` path and reads the
 * resulting lines, rather than asserting on a helper in isolation: the
 * defect was never in the formatting, it was in which timezone reached
 * the formatting, and only the full render proves the recipient's own
 * timezone got there.
 *
 * The deadline case (ReviewRequested) is the sharp one. `expires_at` is
 * a single UTC instant, but the DATE a student reads off it depends on
 * where they are — so the old rendering told part of the audience the
 * wrong day to act by.
 */
class NotificationRecipientTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** 23:30 UTC — deliberately late enough that the local calendar date differs east and west. */
    private const string INSTANT = '2026-08-15 23:30:00';

    private function platformDefault(string $timezone): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_timezone = $timezone;
        $settings->save();
    }

    private function userIn(?string $timezone, ?string $countryTimezone = null): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $country = $countryTimezone === null
            ? null
            : Country::factory()->create(['default_timezone' => $countryTimezone]);

        $user->profile()->update(['timezone' => $timezone, 'country_id' => $country?->id]);

        return $user->fresh();
    }

    private function instant(string $value = self::INSTANT): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    /** @return list<string> the rendered mail lines */
    private function lines(MailMessage $mail): array
    {
        return array_map(strval(...), array_merge($mail->introLines, $mail->outroLines));
    }

    private function renderedFor(object $notification, User $recipient): string
    {
        return implode(' | ', $this->lines($notification->toMail($recipient)));
    }

    private function qualityAlert(): InstructorQualityAlert
    {
        return InstructorQualityAlert::factory()->create(['triggered_at' => $this->instant()]);
    }

    private function reviewReport(): ReviewReport
    {
        return ReviewReport::factory()->create(['submitted_at' => $this->instant()]);
    }

    private function eligibility(): LessonReviewEligibility
    {
        return LessonReviewEligibility::factory()->create(['expires_at' => $this->instant()]);
    }

    // ── 1. Quality alert renders in the ADMIN recipient's timezone ──────

    public function test_quality_alert_uses_the_recipient_admin_timezone(): void
    {
        $admin = $this->userIn('Asia/Kolkata');
        $rendered = $this->renderedFor(new InstructorQualityAlertCreatedNotification($this->qualityAlert()), $admin);

        // 23:30 UTC on the 15th is 05:00 on the 16th in Kolkata.
        $this->assertStringContainsString('Aug 16, 2026 5:00 AM (Asia/Kolkata)', $rendered);
        $this->assertStringNotContainsString('Aug 15, 2026 11:30 PM', $rendered);
    }

    // ── 2. Review reported renders in the moderator's timezone ──────────

    public function test_review_reported_uses_the_recipient_moderator_timezone(): void
    {
        $moderator = $this->userIn('America/Los_Angeles');
        $rendered = $this->renderedFor(new ReviewReportedNotification($this->reviewReport()), $moderator);

        // Same instant, 16:30 on the 15th in Los Angeles.
        $this->assertStringContainsString('Aug 15, 2026 4:30 PM (America/Los_Angeles)', $rendered);
    }

    // ── 3 + 4. Deadline, and the calendar date it lands on ──────────────

    public function test_review_deadline_date_follows_the_students_own_calendar(): void
    {
        $eastern = $this->userIn('Asia/Kolkata');
        $western = $this->userIn('America/Los_Angeles');
        $notification = new ReviewRequestedNotification($this->eligibility());

        // ONE instant. Two different calendar dates. This is the whole
        // point of the finding: the old code showed everyone Aug 15.
        $this->assertStringContainsString('Aug 16, 2026 (Asia/Kolkata)', $this->renderedFor($notification, $eastern));
        $this->assertStringContainsString('Aug 15, 2026 (America/Los_Angeles)', $this->renderedFor($notification, $western));
    }

    public function test_the_deadline_instant_itself_is_never_mutated(): void
    {
        $eligibility = $this->eligibility();
        $original = $eligibility->expires_at->utc()->toIso8601String();

        $this->renderedFor(new ReviewRequestedNotification($eligibility), $this->userIn('Asia/Kolkata'));

        $this->assertSame($original, $eligibility->fresh()->expires_at->utc()->toIso8601String());
        $this->assertDatabaseHas('lesson_review_eligibilities', [
            'id' => $eligibility->id,
            'expires_at' => $this->instant()->format('Y-m-d H:i:s'),
        ]);
    }

    // ── 5. One event, two recipients, two renderings ────────────────────

    public function test_the_same_alert_renders_differently_for_two_recipients(): void
    {
        $alert = $this->qualityAlert();
        $notification = new InstructorQualityAlertCreatedNotification($alert);

        $kolkata = $this->renderedFor($notification, $this->userIn('Asia/Kolkata'));
        $losAngeles = $this->renderedFor($notification, $this->userIn('America/Los_Angeles'));

        $this->assertNotSame($kolkata, $losAngeles);
        $this->assertStringContainsString('Aug 16, 2026 5:00 AM (Asia/Kolkata)', $kolkata);
        $this->assertStringContainsString('Aug 15, 2026 4:30 PM (America/Los_Angeles)', $losAngeles);
    }

    // ── 6. Country fallback, through a real notification render ─────────

    public function test_country_default_is_used_when_the_recipient_has_no_explicit_timezone(): void
    {
        $this->platformDefault('UTC');
        $recipient = $this->userIn(null, 'Australia/Sydney');

        $rendered = $this->renderedFor(new ReviewReportedNotification($this->reviewReport()), $recipient);

        // Sydney is UTC+10 in August: 09:30 on the 16th.
        $this->assertStringContainsString('Aug 16, 2026 9:30 AM (Australia/Sydney)', $rendered);
    }

    public function test_platform_default_is_used_when_neither_profile_nor_country_has_one(): void
    {
        $this->platformDefault('Europe/Lisbon');
        $recipient = $this->userIn(null, null);

        $rendered = $this->renderedFor(new ReviewReportedNotification($this->reviewReport()), $recipient);

        $this->assertStringContainsString('(Europe/Lisbon)', $rendered);
    }

    // ── 7. Malformed legacy value degrades rather than throwing ─────────

    public function test_an_invalid_stored_timezone_degrades_through_the_resolver(): void
    {
        // Before TZ-1 this reached Carbon and threw, taking the queued
        // notification job down with it.
        $this->platformDefault('Europe/Lisbon');
        $recipient = $this->userIn('Invalid/Zone', 'Australia/Sydney');

        $rendered = $this->renderedFor(new ReviewReportedNotification($this->reviewReport()), $recipient);

        $this->assertStringContainsString('(Australia/Sydney)', $rendered);
    }

    public function test_a_legacy_non_canonical_identifier_also_degrades(): void
    {
        $this->platformDefault('Europe/Lisbon');
        $recipient = $this->userIn('EST');

        $rendered = $this->renderedFor(new ReviewReportedNotification($this->reviewReport()), $recipient);

        $this->assertStringContainsString('(Europe/Lisbon)', $rendered);
        $this->assertStringNotContainsString('(EST)', $rendered);
    }

    // ── 8. DST — plain instant-to-display conversion, IANA authoritative ─

    public function test_recipient_rendering_respects_the_dst_offset_in_force(): void
    {
        $recipient = $this->userIn('Europe/London');

        // 12:00 UTC in August is 13:00 BST…
        $summer = new ReviewReportedNotification(
            ReviewReport::factory()->create(['submitted_at' => $this->instant('2026-08-15 12:00:00')]),
        );
        $this->assertStringContainsString('Aug 15, 2026 1:00 PM (Europe/London)', $this->renderedFor($summer, $recipient));

        // …and 12:00 GMT in January, an hour apart from the same UTC time.
        $winter = new ReviewReportedNotification(
            ReviewReport::factory()->create(['submitted_at' => $this->instant('2027-01-15 12:00:00')]),
        );
        $this->assertStringContainsString('Jan 15, 2027 12:00 PM (Europe/London)', $this->renderedFor($winter, $recipient));
    }

    public function test_a_deadline_falling_inside_the_fall_back_hour_still_renders_the_right_day(): void
    {
        $recipient = $this->userIn('America/New_York');

        $notification = new ReviewRequestedNotification(
            // 05:30 UTC on the fall-back Sunday — 01:30 local, the hour
            // that occurs twice. The DATE is unambiguous regardless.
            LessonReviewEligibility::factory()->create(['expires_at' => $this->instant('2026-11-01 05:30:00')]),
        );

        $this->assertStringContainsString('Nov 1, 2026 (America/New_York)', $this->renderedFor($notification, $recipient));
    }
}
