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
use Tests\TestCase;

class GuestBookingTest extends TestCase
{
    use RefreshDatabase;

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

        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30, 'max_attendees' => 1]);
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

    public function test_manage_token_is_returned_once_and_stored_hashed(): void
    {
        $response = $this->postJson('/api/v1/guest/bookings', $this->payload())->assertCreated();

        $plain = $response->json('manage_token');
        $reference = $response->json('data.reference');

        $this->assertSame(64, strlen((string) $plain));
        $this->assertStringContainsString("/book/manage/{$reference}?token={$plain}", $response->json('manage_url'));

        $stored = Booking::query()->where('reference', $reference)->value('manage_token');
        $this->assertSame(hash('sha256', $plain), $stored);
        $this->assertNotSame($plain, $stored);
    }

    public function test_plain_token_authorizes_view_cancel_and_reschedule(): void
    {
        $store = $this->postJson('/api/v1/guest/bookings', $this->payload())->assertCreated();
        $reference = $store->json('data.reference');
        $token = $store->json('manage_token');

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

    public function test_honeypot_still_rejects_bots(): void
    {
        $this->postJson('/api/v1/guest/bookings', $this->payload(['website' => 'http://spam.example']))
            ->assertStatus(422);
    }

    public function test_captcha_blocks_when_enabled_and_verifies_with_turnstile(): void
    {
        $settings = app(BookingSettings::class);
        $settings->captcha_enabled = true;
        $settings->turnstile_site_key = 'site-key';
        $settings->turnstile_secret_key = 'secret-key';
        $settings->save();

        // No token → rejected before any booking is created.
        $this->postJson('/api/v1/guest/bookings', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('cf_turnstile_response');

        // First verification fails, second succeeds.
        Http::fake([
            'challenges.cloudflare.com/*' => Http::sequence()
                ->push(['success' => false])
                ->push(['success' => true]),
        ]);

        $this->postJson('/api/v1/guest/bookings', $this->payload(['cf_turnstile_response' => 'bad-token']))
            ->assertStatus(422);

        $this->postJson('/api/v1/guest/bookings', $this->payload(['cf_turnstile_response' => 'good-token']))
            ->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'turnstile/v0/siteverify')
            && $request['secret'] === 'secret-key');
    }

    public function test_captcha_disabled_requires_no_token(): void
    {
        $this->postJson('/api/v1/guest/bookings', $this->payload())->assertCreated();
    }

    public function test_manage_page_renders_for_guests(): void
    {
        $store = $this->postJson('/api/v1/guest/bookings', $this->payload())->assertCreated();

        $this->get('/book/manage/'.$store->json('data.reference').'?token='.$store->json('manage_token'))
            ->assertOk()
            ->assertSee('Manage Booking')
            ->assertSee('manageBooking', escape: false);
    }
}
