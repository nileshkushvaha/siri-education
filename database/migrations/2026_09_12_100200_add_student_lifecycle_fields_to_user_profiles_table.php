<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS-2-20/SRS-B1-12: additive columns supporting
 * governed student_status transitions, mirroring the existing
 * instructor_reviewed_at/instructor_reviewed_by/instructor_review_reason
 * columns added for the instructor lifecycle. No default backfill here —
 * these stay null for every existing row until the new
 * StudentLifecycleService performs its first governed transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->timestamp('student_status_changed_at')->nullable()->after('student_status');
            $table->foreignId('student_status_changed_by')->nullable()->after('student_status_changed_at')->constrained('users')->nullOnDelete();
            $table->text('student_status_reason')->nullable()->after('student_status_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('student_status_changed_by');
            $table->dropColumn([
                'student_status_changed_at',
                'student_status_reason',
            ]);
        });
    }
};
