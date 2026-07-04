<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Database\Seeders\BookingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_receives_booking_permissions_after_seeding(): void
    {
        $this->seed(BookingPermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $booking = Booking::factory()
            ->for(BookingType::factory()->create(), 'type')
            ->create();

        $this->assertTrue($manager->can('viewAny', Booking::class));
        $this->assertTrue($manager->can('create', Booking::class));
        $this->assertTrue($manager->can('confirm', $booking));
        $this->assertTrue($manager->can('cancel', $booking));
        $this->assertTrue($manager->can('reschedule', $booking));
        $this->assertTrue($manager->can('complete', $booking));
        $this->assertTrue($manager->can('viewAny', BookingType::class));

        // Force delete stays super_admin-only.
        $this->assertFalse($manager->can('forceDelete', $booking));
    }

    public function test_seeding_is_idempotent_and_plain_users_stay_denied(): void
    {
        $this->seed(BookingPermissionSeeder::class);
        $this->seed(BookingPermissionSeeder::class);

        $user = User::factory()->create();

        $this->assertFalse($user->can('viewAny', Booking::class));
    }
}
