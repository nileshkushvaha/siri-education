<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Google account an instructor joins Meet lessons with, so it can be
 * added as the space's co-host (docs/meetings.md §3). Nullable: when
 * empty the instructor's login email is used. Additive, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('google_meet_account', 255)->nullable()->after('instructor_teaching_language_ids');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn('google_meet_account');
        });
    }
};
