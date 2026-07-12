<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance-sync bookkeeping for meetings:sync-attendance: a settled
 * meeting (attendance_synced_at set) is never re-fetched unless forced;
 * attempts and status drive bounded transient retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_meetings', function (Blueprint $table): void {
            $table->timestamp('attendance_synced_at')->nullable()->after('failure_reason');
            $table->unsignedTinyInteger('attendance_sync_attempts')->default(0)->after('attendance_synced_at');
            $table->string('attendance_sync_status', 32)->nullable()->after('attendance_sync_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('booking_meetings', function (Blueprint $table): void {
            $table->dropColumn(['attendance_synced_at', 'attendance_sync_attempts', 'attendance_sync_status']);
        });
    }
};
