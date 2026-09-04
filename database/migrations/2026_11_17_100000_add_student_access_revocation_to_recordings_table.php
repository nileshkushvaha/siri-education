<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §12.20 — student playback of lesson recordings.
 *
 * Whether students may watch at all is a platform setting
 * (meeting.recording_student_playback_enabled). What this adds is the
 * per-recording exception: an administrator withholding ONE recording
 * from its student — a lesson under dispute, a safety review, a
 * request from a guardian — without deleting the object or touching
 * retention. Two columns, no status: withholding is orthogonal to the
 * ingestion lifecycle (a withheld recording is still Available to
 * administrators), so it must not be a RecordingStatus case.
 *
 * Nullable timestamp semantics, as elsewhere (confirmed_at,
 * cancelled_at): null means the student's default access applies; set
 * means withheld since that instant. Restoring clears both columns.
 * The acting admin and reason are on the audit trail, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->timestamp('student_access_revoked_at')->nullable()->after('expires_at');
            $table->foreignId('student_access_revoked_by')
                ->nullable()
                ->after('student_access_revoked_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('student_access_revoked_by');
            $table->dropColumn('student_access_revoked_at');
        });
    }
};
