<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data-provenance decision (Outcome B, §6.1): the recurring-
     * booking workflow already knows the recurrence frequency (daily/
     * weekly) at creation time (`RecurrenceData::$frequency`) but never
     * persisted it — every occurrence became an indistinguishable
     * `Booking` row. This column is set only by the recurring-booking
     * creation path going forward; it is never backfilled for existing
     * rows (a historical row that was actually part of a recurring
     * series — identifiable only via the pre-existing `meta->recurring_group`
     * JSON key — is reported as "unknown historical classification",
     * never silently counted as single). Nullable, additive, reversible;
     * booking creation behavior is otherwise unchanged.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('recurrence_frequency', 20)->nullable()->after('meta');
            $table->index(['recurrence_frequency', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['recurrence_frequency', 'created_at']);
            $table->dropColumn('recurrence_frequency');
        });
    }
};
