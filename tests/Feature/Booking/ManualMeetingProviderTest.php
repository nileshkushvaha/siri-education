<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Exceptions\BookingException;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Models\Booking;
use App\Models\BookingMeeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualMeetingProviderTest extends TestCase
{
    use RefreshDatabase;

    private ManualMeetingProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new ManualMeetingProvider;
    }

    public function test_key_is_manual(): void
    {
        $this->assertSame('manual', $this->provider->key());
    }

    public function test_is_always_configured(): void
    {
        $this->assertTrue($this->provider->isConfigured());
    }

    public function test_create_meeting_without_url_yields_pending_placeholder(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create();

        $result = $this->provider->createMeeting($booking, new MeetingCreationContext);

        $this->assertSame(MeetingStatus::Pending, $result->status);
        $this->assertNull($result->joinUrl);
    }

    public function test_create_meeting_with_url_yields_created(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create();

        $result = $this->provider->createMeeting($booking, new MeetingCreationContext(
            joinUrl: 'https://meet.example.test/abc',
            password: 'p@ss',
            providerLabel: 'zoom_manual',
        ));

        $this->assertSame(MeetingStatus::Created, $result->status);
        $this->assertSame('https://meet.example.test/abc', $result->joinUrl);
        $this->assertSame('p@ss', $result->password);
        $this->assertSame('zoom_manual', $result->metadata['manual_label']);
    }

    public function test_create_meeting_rejects_invalid_url(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create();

        $this->expectException(BookingException::class);
        $this->provider->createMeeting($booking, new MeetingCreationContext(joinUrl: 'not-a-url'));
    }

    public function test_update_meeting_replaces_join_url(): void
    {
        $meeting = BookingMeeting::factory()->created('https://meet.example.test/old')->create();

        $result = $this->provider->updateMeeting($meeting, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/new'));

        $this->assertSame('https://meet.example.test/new', $result->joinUrl);
        $this->assertSame(MeetingStatus::Created, $result->status);
    }

    public function test_update_meeting_keeps_existing_url_when_not_supplied(): void
    {
        $meeting = BookingMeeting::factory()->created('https://meet.example.test/keep')->create();

        $result = $this->provider->updateMeeting($meeting, new MeetingUpdateContext(password: 'new-pass'));

        $this->assertSame('https://meet.example.test/keep', $result->joinUrl);
        $this->assertSame('new-pass', $result->password);
    }

    public function test_cancel_meeting_returns_cancelled_status(): void
    {
        $meeting = BookingMeeting::factory()->created()->create();

        $result = $this->provider->cancelMeeting($meeting);

        $this->assertSame(MeetingStatus::Cancelled, $result->status);
    }
}
