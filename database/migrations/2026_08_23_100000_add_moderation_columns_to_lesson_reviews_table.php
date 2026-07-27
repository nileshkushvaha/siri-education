<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderation bookkeeping on the existing review row (no new
 * table: history is preserved through the existing activity log /
 * AuditTrailService, exactly like every other domain in this
 * codebase). `moderation_snapshot` captures the moderation_model /
 * auto_publish_clean_reviews settings in force when automatic
 * moderation last evaluated the review — never retroactively changed
 * by a later settings edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_reviews', function (Blueprint $table): void {
            $table->timestamp('moderated_at')->nullable()->after('version');
            $table->foreignId('moderated_by')->nullable()->after('moderated_at')->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 500)->nullable()->after('moderated_by');
            $table->json('moderation_snapshot')->nullable()->after('moderation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['moderated_at', 'moderation_reason', 'moderation_snapshot']);
        });
    }
};
