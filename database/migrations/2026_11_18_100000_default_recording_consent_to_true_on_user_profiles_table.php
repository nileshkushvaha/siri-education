<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product decision (2026-09-05): lessons are ALWAYS recording-eligible.
 * Recording notice is given through the Terms of Service, the booking
 * confirmation and the provider's in-meeting indicator (SRS §12.19
 * lists those channels); the per-profile opt-out that shipped OFF by
 * default is withdrawn, because with it every profile on the platform
 * had left recording off and no lesson could ever be recorded.
 *
 * The column stays: RecordingEligibilityResolver still reads it and
 * every recording still freezes both values into consent_snapshot, so
 * the audit trail is unchanged. What changes is the default (true) and
 * the existing rows (backfilled to true). The profile form no longer
 * exposes the toggle, so nothing sets it false; an administrator can
 * still do so deliberately in the database if a specific case ever
 * requires it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->boolean('consents_to_recording')->default(true)->change();
        });

        DB::table('user_profiles')->where('consents_to_recording', false)->update(['consents_to_recording' => true]);
    }

    public function down(): void
    {
        // The default reverts; rows are deliberately NOT flipped back —
        // consent recorded on existing recordings' snapshots must stay
        // consistent with what the profiles said at the time.
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->boolean('consents_to_recording')->default(false)->change();
        });
    }
};
