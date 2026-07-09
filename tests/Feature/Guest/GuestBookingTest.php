<?php

declare(strict_types=1);

namespace Tests\Feature\Guest;

use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 10.2C-Fix: no guest booking — POST /api/v1/guest/bookings can
 * never create a new booking anymore (AuthenticatedAttendeeRule rejects
 * every request through this endpoint, since it never carries an
 * authenticated session by design). The manage/view/cancel/reschedule
 * endpoints stay reachable for *legacy* guest bookings (created before
 * this phase, or by direct admin action) — simulated here via a
 * directly-built fixture rather than the now-blocked create endpoint.
 */
class GuestBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private BookingType $freeDemo;

    protected function setUp(): void
    {
        parent::setUp();

        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }
        $this->teacher = $teacher;

        $this->freeDemo = BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30, 'max_attendees' => 1]);
        BookingType::factory()->paid(499.00, 'INR')->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60, 'max_attendees' => 1]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'free_demo',
            'subject' => 'maths',
            'grade' => 5,
            'starts_at' => now('UTC')->addDays(3)->setTime(10, 0)->toIso8601String(),
            'name' => 'Guest Parent',
            'email' => 'parent@example.com',
            ...$overrides,
        ];
    }

    /**
     * A pre-existing guest booking, as if created before the Phase
     * 10.2C-Fix "no guest booking" rule shipped — built directly, since
     * the create endpoint itself can no longer produce one.
     *
     * @return array{0: Booking, 1: string} booking + plain manage token
     */
    private function legacyGuestBooking(): array
    {
        $plainToken = Str::random(64);

        $booking = Booking::factory()->create([
            'booking_type_id' => $this->freeDemo->id,
            'attendee_id' => null,
            'host_id' => $this->teacher->id,
            'guest_name' => 'Guest Parent',
            'guest_email' => 'parent@example.com',
            'guest_phone' => null,
            'manage_token' => hash('sha256', $plainToken),
            'starts_at' => now('UTC')->addDays(3)->setTime(10, 0),
            'ends_at' => now('UTC')->addDays(3)->setTime(10, 30),
        ]);

        return [$booking, $plainToken];
    }

    // ── Guest booking creation is denied ──────────────────────────────

    public function test_guest_booking_creation_is_denied(): void
    {
        $response = $this->postJson('/api/v1/guest/bookings', $this->payload());

        $response->assertStatus(422);
        $this->assertDatabaseMissing('bookings', ['guest_email' => 'parent@example.com']);
    }

    public function test_guest_cannot_create_a_paid_booking(): void
    {
        $response = $this->postJson('/api/v1/guest/bookings', $this->payload(['type' => 'paid_one_to_one']));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('bookings', ['guest_email' => 'parent@example.com']);
    }

    public function test_honeypot_still_rejects_bots(): void
    {
        $this->postJson('/api/v1/guest/bookings', $this->payload(['website' => 'http://spam.example']))
            ->assertStatus(422);
    }

    public function test_captcha_enabled_still_denies_guest_booking(): void
    {
        $settings = app(BookingSettings::class);
        $settings->captcha_enabled = true;
        $settings->turnstile_site_key = 'site-key';
        $settings->turnstile_secret_key = 'secret-key';
        $settings->save();

        // No token → rejected before any booking is created (captcha still checked first).
        $this->postJson('/api/v1/guest/bookings', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('cf_turnstile_response');

        // A verified, valid captcha token no longer results in a booking —
        // the "no guest booking" rule denies it regardless.
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->postJson('/api/v1/guest/bookings', $this->payload(['cf_turnstile_response' => 'good-token']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('bookings', ['guest_email' => 'parent@example.com']);
    }

    public function test_captcha_disabled_still_denies_guest_booking(): void
    {
        $this->postJson('/api/v1/guest/bookings', $this->payload())->assertStatus(422);
        $this->assertDatabaseMissing('bookings', ['guest_email' => 'parent@example.com']);
    }

    // ── Legacy guest booking management still works ──────────────────

    public function test_legacy_guest_booking_manage_token_authorizes_view_cancel_and_reschedule(): void
    {
        [$booking, $token] = $this->legacyGuestBooking();
        $reference = $booking->reference;

        $this->getJson("/api/v1/guest/bookings/{$reference}?token={$token}")
            ->assertOk()
            ->assertJsonPath('data.reference', $reference);

        $this->getJson("/api/v1/guest/bookings/{$reference}?token=".str_repeat('x', 64))
            ->assertNotFound();

        $newStart = now('UTC')->addDays(4)->setTime(11, 0)->toIso8601String();
        $this->postJson("/api/v1/guest/bookings/{$reference}/reschedule", [
            'token' => $token,
            'starts_at' => $newStart,
        ])->assertOk()->assertJsonPath('data.starts_at', $newStart);

        $this->postJson("/api/v1/guest/bookings/{$reference}/cancel", [
            'token' => $token,
            'reason' => 'done',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_legacy_guest_manage_page_still_renders(): void
    {
        [$booking, $token] = $this->legacyGuestBooking();

        $this->get('/book/manage/'.$booking->reference.'?token='.$token)
            ->assertOk()
            ->assertSee('Manage Booking')
            ->assertSee('manageBooking', escape: false);
    }
}
