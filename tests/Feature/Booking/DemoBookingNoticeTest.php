<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Exceptions\BookingException;
use App\Booking\Validation\Rules\BookingWindowRule;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Free demos use their own, shorter lead time (Platform Foundation → Demo Minimum Notice). */
class DemoBookingNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/settings']);

        $settings = app(BookingSettings::class);
        $settings->minimum_booking_notice_minutes = 120;
        $settings->demo_minimum_booking_notice_minutes = 30;
        $settings->save();
    }

    public function test_demo_default_notice_is_thirty_minutes(): void
    {
        $this->assertSame(30, app(BookingSettings::class)->demo_minimum_booking_notice_minutes);
    }

    public function test_demo_starting_in_forty_five_minutes_is_bookable_while_a_paid_lesson_is_not(): void
    {
        $rule = app(BookingWindowRule::class);
        $start = CarbonImmutable::now()->addMinutes(45);

        $this->assertTrue($rule->isWithinWindow($start, isDemo: true));
        $this->assertFalse($rule->isWithinWindow($start, isDemo: false));
    }

    public function test_demo_inside_its_own_notice_is_refused_with_a_demo_message(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('Demo bookings require at least 30 minutes notice.');

        app(BookingWindowRule::class)->assertWithinWindow(CarbonImmutable::now()->addMinutes(10), isDemo: true);
    }

    public function test_admin_can_shorten_the_demo_notice_independently(): void
    {
        $settings = app(BookingSettings::class);
        $settings->demo_minimum_booking_notice_minutes = 0;
        $settings->save();

        $rule = app(BookingWindowRule::class);

        $this->assertTrue($rule->isWithinWindow(CarbonImmutable::now()->addMinutes(2), isDemo: true));
        $this->assertFalse($rule->isWithinWindow(CarbonImmutable::now()->addMinutes(2), isDemo: false));
    }
}
