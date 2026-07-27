<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §12.19 Recording Consent: a user's own standing consent
 * to being recorded during lessons. Defaults false — recording stays
 * opt-in; a lesson is only recording-eligible when BOTH the student
 * and instructor rows have this set true (RecordingEligibilityResolver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->boolean('consents_to_recording')->default(false)->after('show_social_links');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn('consents_to_recording');
        });
    }
};
