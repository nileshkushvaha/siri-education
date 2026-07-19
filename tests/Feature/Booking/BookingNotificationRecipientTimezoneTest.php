<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\DTOs\CancellationRefundDecision;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\User;
use App\Notifications\Booking\BookingCancelledNotification;
use App\Notifications\Booking\BookingConfirmedNotification;
use App\Notifications\Booking\BookingRescheduledNotification;
use App\Notifications\Booking\MeetingCreatedNotification;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24L — GAP-030 (SRS-21-6, SRS §21.13/§21.16): every booking/
 * meeting notification must display the scheduled time in the ACTUAL
 * recipient's own timezone, resolved at render time via
 * RecipientTimezoneResolver — never the booking's captured (student's)
 * timezone reused for the instructor's copy.
 */
final class BookingNotificationRecipientTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['timezone' => 'Asia/Kolkata']); // +5:30

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher->profile()->update(['timezone' => 'America/New_York']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-10 08:00:00', 'UTC'));
    }

    // ── 1-4: independence, same instant, calendar-date rollover ──────

    public function test_student_booking_notification_uses_student_timezone(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00'); // 08:30 IST same day

        $mail = (new BookingConfirmedNotification($booking))->toMail($this->student);

        $this->assertStringContainsString('8:30', implode(' ', $mail->introLines));
        $this->assertStringContainsString('Asia/Kolkata', implode(' ', $mail->introLines));
    }

    public function test_instructor_booking_notification_uses_instructor_timezone(): void
    {
        // 2026-01-15 03:00 UTC = Jan 14, 10:00 PM EST (America/New_York, UTC-5 in January).
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');

        $mail = (new BookingConfirmedNotification($booking))->toMail($this->teacher);

        $this->assertStringContainsString('22:00', implode(' ', $mail->introLines));
        $this->assertStringContainsString('America/New_York', implode(' ', $mail->introLines));
    }

    public function test_same_utc_instant_renders_differently_for_both_recipients(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $notification = new BookingConfirmedNotification($booking);

        $studentText = implode(' ', $notification->toMail($this->student)->introLines);
        $teacherText = implode(' ', $notification->toMail($this->teacher)->introLines);

        $this->assertNotSame($studentText, $teacherText);
    }

    public function test_calendar_date_rollover_is_correct_across_recipients(): void
    {
        // A UTC lesson that falls on a different calendar date for each
        // recipient — student (IST, UTC+5:30) sees it the NEXT day.
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-14 19:30:00'); // Jan 15, 01:00 IST / Jan 14, 14:30 EST
        $notification = new BookingConfirmedNotification($booking);

        $studentText = implode(' ', $notification->toMail($this->student)->introLines);
        $teacherText = implode(' ', $notification->toMail($this->teacher)->introLines);

        $this->assertStringContainsString('Jan 15', $studentText);
        $this->assertStringContainsString('Jan 14', $teacherText);
    }

    // ── 5-6: half-hour / quarter-hour offsets ────────────────────────

    public function test_half_hour_timezone_offset_is_correct(): void
    {
        $this->student->profile()->update(['timezone' => 'Asia/Kolkata']); // UTC+5:30
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 04:15:00');

        $mail = (new BookingConfirmedNotification($booking))->toMail($this->student);

        $this->assertStringContainsString('9:45', implode(' ', $mail->introLines));
    }

    public function test_quarter_hour_timezone_offset_is_correct(): void
    {
        $this->student->profile()->update(['timezone' => 'Asia/Kathmandu']); // UTC+5:45
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 04:15:00');

        $mail = (new BookingConfirmedNotification($booking))->toMail($this->student);

        $this->assertStringContainsString('10:00', implode(' ', $mail->introLines));
    }

    // ── 7-9: missing/invalid timezone fallback ───────────────────────

    public function test_null_profile_timezone_uses_platform_default_fallback(): void
    {
        app(GeneralSettings::class)->default_timezone = 'Europe/London';
        app(GeneralSettings::class)->save();
        $this->student->profile()->update(['timezone' => null]);

        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 12:00:00');
        $mail = (new BookingConfirmedNotification($booking))->toMail($this->student);

        $this->assertStringContainsString('Europe/London', implode(' ', $mail->introLines));
    }

    public function test_invalid_profile_timezone_falls_back_safely_without_exception(): void
    {
        $this->student->profile()->update(['timezone' => 'Not/A_Real_Zone']);
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 12:00:00');

        $mail = (new BookingConfirmedNotification($booking))->toMail($this->student);

        $this->assertNotNull($mail); // no exception — rendering succeeds
        $this->assertStringNotContainsString('Not/A_Real_Zone', implode(' ', $mail->introLines));
    }

    public function test_invalid_default_timezone_falls_back_to_utc(): void
    {
        app(GeneralSettings::class)->default_timezone = 'Also/Invalid';
        app(GeneralSettings::class)->save();
        $this->student->profile()->update(['timezone' => null]);

        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 12:00:00');
        $mail = (new BookingConfirmedNotification($booking))->toMail($this->student);

        $this->assertStringContainsString('UTC', implode(' ', $mail->introLines));
    }

    // ── 10-11: channel content consistency ───────────────────────────

    public function test_database_and_mail_payloads_agree(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $notification = new BookingConfirmedNotification($booking);

        $mailText = implode(' ', $notification->toMail($this->teacher)->introLines);
        $databasePayload = $notification->toDatabase($this->teacher);

        $this->assertStringContainsString('22:00', $mailText);
        $this->assertStringContainsString('22:00', $databasePayload['message']);
        $this->assertStringContainsString('America/New_York', $databasePayload['message']);
    }

    public function test_sms_and_whatsapp_payloads_agree_with_recipient_timezone(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $notification = new BookingConfirmedNotification($booking);

        $whatsapp = $notification->toWhatsApp($this->teacher);
        $sms = $notification->toSms($this->teacher);

        $this->assertStringContainsString('22:00', $whatsapp);
        $this->assertStringContainsString('22:00', $sms);
        $this->assertStringContainsString('America/New_York', $whatsapp);
    }

    // ── 12-13: reschedule, DST-spanning old/new ──────────────────────

    public function test_reschedule_old_and_new_times_use_recipient_timezone(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-20 03:00:00'); // new time
        $previousStartsAt = CarbonImmutable::parse('2026-01-15 03:00:00', 'UTC');

        $notification = new BookingRescheduledNotification($booking, $previousStartsAt);
        $mail = $notification->toMail($this->teacher);
        $text = implode(' ', $mail->introLines);

        $this->assertStringContainsString('22:00', $text); // both old and new render at 22:00 EST
        $this->assertStringContainsString('America/New_York', $text);
    }

    public function test_reschedule_old_and_new_dates_spanning_dst_use_correct_respective_offsets(): void
    {
        // America/New_York: DST starts 2026-03-08 02:00 local (spring forward).
        // Old time is EST (UTC-5); new time is EDT (UTC-4).
        $previousStartsAt = CarbonImmutable::parse('2026-03-01 15:00:00', 'UTC'); // 10:00 EST
        $booking = $this->confirmedBooking(startsAtUtc: '2026-03-15 14:00:00'); // 10:00 EDT

        $notification = new BookingRescheduledNotification($booking, $previousStartsAt);
        $mail = $notification->toMail($this->teacher);
        $text = implode(' ', $mail->introLines);

        // Both render as "10:00" local despite the underlying UTC offset
        // changing between the two dates — each independently converted.
        $this->assertSame(2, substr_count($text, '10:00'));
    }

    // ── 14-15: cancellation ───────────────────────────────────────────

    public function test_cancellation_time_uses_recipient_timezone(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $booking->forceFill(['cancellation_reason' => 'Schedule conflict'])->save();

        $notification = new BookingCancelledNotification($booking->fresh());
        $mail = $notification->toMail($this->teacher);
        $text = implode(' ', $mail->introLines);

        $this->assertStringContainsString('22:00', $text);
        $this->assertStringContainsString('America/New_York', $text);
    }

    public function test_frozen_refund_decision_text_is_not_recomputed_by_timezone_changes(): void
    {
        // BookingCancelledNotification's refund line states the FROZEN
        // Phase 24C outcome only (eligible/not) — no raw cutoff
        // timestamp is displayed, so there is nothing for a timezone
        // conversion to alter; this proves the refund line is untouched
        // regardless of which recipient's timezone is used to render it.
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $decision = new CancellationRefundDecision(
            eligible: true,
            policyCode: 'standard',
            cutoffAt: CarbonImmutable::parse('2026-01-14 03:00:00', 'UTC'),
            windowHours: 24,
            cancelledAt: CarbonImmutable::parse('2026-01-13 00:00:00', 'UTC'),
            startsAt: $booking->starts_at,
        );

        $notification = new BookingCancelledNotification($booking, $decision);
        $studentText = implode(' ', $notification->toMail($this->student)->introLines);
        $teacherText = implode(' ', $notification->toMail($this->teacher)->introLines);

        $this->assertStringContainsString('credited to your wallet', $studentText);
        $this->assertStringContainsString('credited to your wallet', $teacherText);
    }

    // ── 16-17: meeting notifications ──────────────────────────────────

    public function test_meeting_created_student_and_instructor_copies_use_independent_timezones(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $meeting = BookingMeeting::query()->create([
            'booking_id' => $booking->id,
            'provider' => 'manual',
            'status' => 'created',
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'timezone' => $booking->timezone,
            'join_url' => 'https://meet.example.test/abc',
        ]);

        $notification = new MeetingCreatedNotification($booking, $meeting);

        $studentText = implode(' ', $notification->toMail($this->student)->introLines);
        $teacherText = implode(' ', $notification->toMail($this->teacher)->introLines);

        $this->assertStringContainsString('8:30', $studentText);
        $this->assertStringContainsString('Asia/Kolkata', $studentText);
        $this->assertStringContainsString('22:00', $teacherText);
        $this->assertStringContainsString('America/New_York', $teacherText);
    }

    public function test_url_withholding_is_unaffected_by_timezone_resolution(): void
    {
        // Phase 24H.2B: includeJoinUrl=false must still omit the URL
        // regardless of which recipient's timezone the schedule renders in.
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $meeting = BookingMeeting::query()->create([
            'booking_id' => $booking->id,
            'provider' => 'manual',
            'status' => 'created',
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'timezone' => $booking->timezone,
            'join_url' => 'https://meet.example.test/secret',
        ]);

        $notification = new MeetingCreatedNotification($booking, $meeting, includeJoinUrl: false);
        $mail = $notification->toMail($this->student);

        $this->assertStringNotContainsString('https://meet.example.test/secret', json_encode([$mail->introLines, $mail->actionUrl]));
    }

    // ── 18: recurring occurrences across DST ─────────────────────────

    public function test_recurring_occurrences_across_dst_are_formatted_independently(): void
    {
        // Two weekly occurrences straddling the March 2026 US DST
        // transition — each must be converted from its OWN UTC instant,
        // never a reused formatted string from the first occurrence.
        $beforeDst = $this->confirmedBooking(startsAtUtc: '2026-03-01 15:00:00'); // 10:00 EST
        $afterDst = $this->confirmedBooking(startsAtUtc: '2026-03-15 14:00:00'); // 10:00 EDT (one hour less UTC offset)

        $beforeText = implode(' ', (new BookingConfirmedNotification($beforeDst))->toMail($this->teacher)->introLines);
        $afterText = implode(' ', (new BookingConfirmedNotification($afterDst))->toMail($this->teacher)->introLines);

        $this->assertStringContainsString('10:00', $beforeText);
        $this->assertStringContainsString('10:00', $afterText);
        $this->assertNotSame($beforeText, $afterText); // different dates, independently rendered
    }

    // ── 19-20: queue timing, instance reuse ──────────────────────────

    public function test_timezone_changed_after_construction_but_before_render_uses_the_new_timezone(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $notification = new BookingConfirmedNotification($booking); // "event" constructed

        // Recipient changes their timezone before the queued job renders.
        $this->teacher->profile()->update(['timezone' => 'Asia/Tokyo']);

        $mail = $notification->toMail($this->teacher->fresh());
        $text = implode(' ', $mail->introLines);

        $this->assertStringContainsString('Asia/Tokyo', $text);
        $this->assertStringNotContainsString('America/New_York', $text);
    }

    public function test_reused_notification_instance_does_not_leak_timezone_between_recipients(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $notification = new BookingConfirmedNotification($booking);

        // Render for the teacher first, then the student, on the SAME
        // instance — order must not leave stale state affecting the
        // second render.
        $teacherText = implode(' ', $notification->toMail($this->teacher)->introLines);
        $studentText = implode(' ', $notification->toMail($this->student)->introLines);
        $teacherTextAgain = implode(' ', $notification->toMail($this->teacher)->introLines);

        $this->assertStringContainsString('America/New_York', $teacherText);
        $this->assertStringContainsString('Asia/Kolkata', $studentText);
        $this->assertSame($teacherText, $teacherTextAgain);
    }

    // ── 21: missing profile ───────────────────────────────────────────

    public function test_missing_profile_does_not_crash_the_notification(): void
    {
        app(GeneralSettings::class)->default_timezone = 'Europe/London';
        app(GeneralSettings::class)->save();

        $orphan = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $orphan->profile()->forceDelete(); // genuinely no profile row, not just soft-deleted

        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $notification = new BookingConfirmedNotification($booking);

        $mail = $notification->toMail($orphan->fresh());

        $this->assertNotNull($mail);
        // No profile row → falls back to the platform default, never crashes.
        $this->assertStringContainsString('Europe/London', implode(' ', $mail->introLines));
    }

    // ── 22-24: domain-data safety, provider calls, channel selection ──

    public function test_no_database_timestamp_is_mutated_by_notification_rendering(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $originalStartsAt = $booking->starts_at->toIso8601String();
        $originalTimezone = $booking->timezone;

        (new BookingConfirmedNotification($booking))->toMail($this->teacher);
        (new BookingConfirmedNotification($booking))->toMail($this->student);

        $fresh = $booking->fresh();
        $this->assertSame($originalStartsAt, $fresh->starts_at->toIso8601String());
        $this->assertSame($originalTimezone, $fresh->timezone);
    }

    public function test_no_external_provider_call_occurs_while_rendering(): void
    {
        Http::fake();
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');

        (new BookingConfirmedNotification($booking))->toMail($this->teacher);
        (new BookingConfirmedNotification($booking))->toMail($this->student);

        Http::assertNothingSent();
    }

    public function test_existing_channel_selection_is_unaffected_by_timezone_changes(): void
    {
        $booking = $this->confirmedBooking(startsAtUtc: '2026-01-15 03:00:00');
        $notification = new BookingConfirmedNotification($booking);

        $channels = $notification->via($this->teacher);

        $this->assertContains('database', $channels);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function confirmedBooking(string $startsAtUtc): Booking
    {
        $startsAt = CarbonImmutable::parse($startsAtUtc, 'UTC');

        return Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->teacher->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(60),
            // The historically-buggy "student timezone captured at
            // booking time" value — proves notifications no longer
            // read this for the instructor's copy.
            'timezone' => 'Asia/Kolkata',
        ]);
    }
}
