<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_availability', function (Blueprint $table): void {
            $table->string('timezone')->nullable()->after('end_time');
            $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('teacher_unavailability', function (Blueprint $table): void {
            $table->string('timezone')->nullable()->after('ends_at');
            $table->unsignedBigInteger('created_by')->nullable()->after('reason');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        $fallbackTimezone = config('app.timezone', 'UTC');

        DB::table('teacher_availability')
            ->leftJoin('user_profiles', 'teacher_availability.teacher_id', '=', 'user_profiles.user_id')
            ->select('teacher_availability.id', 'user_profiles.timezone')
            ->orderBy('teacher_availability.id')
            ->each(function ($row) use ($fallbackTimezone): void {
                DB::table('teacher_availability')
                    ->where('id', $row->id)
                    ->update(['timezone' => $row->timezone ?: $fallbackTimezone]);
            });

        DB::table('teacher_unavailability')
            ->leftJoin('user_profiles', 'teacher_unavailability.teacher_id', '=', 'user_profiles.user_id')
            ->select('teacher_unavailability.id', 'user_profiles.timezone')
            ->orderBy('teacher_unavailability.id')
            ->each(function ($row) use ($fallbackTimezone): void {
                DB::table('teacher_unavailability')
                    ->where('id', $row->id)
                    ->update(['timezone' => $row->timezone ?: $fallbackTimezone]);
            });
    }

    public function down(): void
    {
        Schema::table('teacher_unavailability', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['timezone', 'created_by', 'updated_by']);
        });

        Schema::table('teacher_availability', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['timezone', 'created_by', 'updated_by']);
        });
    }
};
