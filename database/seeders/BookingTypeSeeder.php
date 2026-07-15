<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Booking\Registry\BookingTypeRegistry;
use App\Models\BookingType;
use Illuminate\Database\Seeder;

/**
 * Syncs code drivers (BookingTypeRegistry) into booking_types rows.
 * Idempotent: existing rows keep their admin-tuned values; only
 * missing types are created with driver defaults.
 */
class BookingTypeSeeder extends Seeder
{
    public function run(BookingTypeRegistry $registry): void
    {
        foreach ($registry->all() as $driver) {
            BookingType::query()->firstOrCreate(
                ['key' => $driver->key()],
                [
                    'name' => $driver->label(),
                    'duration_minutes' => $driver->defaultDurationMinutes(),
                    'requires_approval' => $driver->requiresApproval(),
                    'is_paid' => $driver->isPaid(),
                    'is_active' => true,
                ],
            );
        }
    }
}
