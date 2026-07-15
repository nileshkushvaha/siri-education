<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Filament\Resources\Bookings\RelationManagers\GuestsRelationManager;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingMeeting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 17U.2 §1 — closes the Phase 17U.1 residual: booking_guests and
 * booking_meetings were the only two FKs referencing `bookings` still
 * left CASCADE. This suite proves both are now RESTRICT at the
 * database level and hard-deletion-protected at the model level.
 */
class BookingGuestMeetingProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_guest_foreign_key_is_restrict_not_cascade(): void
    {
        $rule = DB::selectOne("
            SELECT rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = 'booking_guests'
                AND kcu.COLUMN_NAME = 'booking_id'
        ");

        $this->assertSame('RESTRICT', $rule->DELETE_RULE);
    }

    public function test_booking_meeting_foreign_key_is_restrict_not_cascade(): void
    {
        $rule = DB::selectOne("
            SELECT rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = 'booking_meetings'
                AND kcu.COLUMN_NAME = 'booking_id'
        ");

        $this->assertSame('RESTRICT', $rule->DELETE_RULE);
    }

    public function test_raw_sql_delete_of_a_booking_with_a_guest_is_rejected_by_the_database(): void
    {
        $booking = Booking::factory()->create();
        BookingGuest::factory()->for($booking)->create();

        $this->expectException(QueryException::class);

        DB::statement('DELETE FROM bookings WHERE id = ?', [$booking->id]);
    }

    public function test_raw_sql_delete_of_a_booking_with_a_meeting_is_rejected_by_the_database(): void
    {
        $booking = Booking::factory()->create();
        BookingMeeting::factory()->for($booking)->create();

        $this->expectException(QueryException::class);

        DB::statement('DELETE FROM bookings WHERE id = ?', [$booking->id]);
    }

    public function test_booking_guest_cannot_be_deleted_through_eloquent(): void
    {
        $guest = BookingGuest::factory()->create();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $guest->delete();
    }

    public function test_booking_meeting_cannot_be_deleted_through_eloquent(): void
    {
        $meeting = BookingMeeting::factory()->create();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $meeting->delete();
    }

    public function test_guests_relation_manager_no_longer_exposes_a_delete_action(): void
    {
        $file = (new \ReflectionClass(GuestsRelationManager::class))->getFileName();

        $this->assertIsString($file);
        $this->assertStringNotContainsString('DeleteAction::make(', file_get_contents($file));
    }

    public function test_guest_status_can_still_be_edited_after_the_delete_action_removal(): void
    {
        $guest = BookingGuest::factory()->create();

        $guest->update(['name' => 'Corrected Name']);

        $this->assertSame('Corrected Name', $guest->fresh()->name);
    }
}
