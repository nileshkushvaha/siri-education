<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->text('instructor_teaching_experience_summary')->nullable()->after('bio');
            $table->text('instructor_teaching_philosophy')->nullable()->after('instructor_teaching_experience_summary');
            $table->json('instructor_academic_level_ids')->nullable()->after('instructor_status');
            $table->json('instructor_skill_level_ids')->nullable()->after('instructor_academic_level_ids');
            $table->json('instructor_teaching_language_ids')->nullable()->after('instructor_skill_level_ids');
            $table->timestamp('instructor_application_started_at')->nullable()->after('instructor_teaching_language_ids');
            $table->timestamp('instructor_application_submitted_at')->nullable()->after('instructor_application_started_at');
            $table->timestamp('instructor_reviewed_at')->nullable()->after('instructor_application_submitted_at');
            $table->foreignId('instructor_reviewed_by')->nullable()->after('instructor_reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('instructor_review_reason')->nullable()->after('instructor_reviewed_by');
            $table->text('instructor_documents_requested_reason')->nullable()->after('instructor_review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('instructor_reviewed_by');
            $table->dropColumn([
                'instructor_teaching_experience_summary',
                'instructor_teaching_philosophy',
                'instructor_academic_level_ids',
                'instructor_skill_level_ids',
                'instructor_teaching_language_ids',
                'instructor_application_started_at',
                'instructor_application_submitted_at',
                'instructor_reviewed_at',
                'instructor_review_reason',
                'instructor_documents_requested_reason',
            ]);
        });
    }
};
