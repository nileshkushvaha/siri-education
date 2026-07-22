<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §6.17.5 (GAP-023): the final learning-plan progress evidence
 * domain. A review has no draft/finalized lifecycle of its own —
 * `reviewed_at` is always set at creation (see LearningPlanService::
 * createReview()) — so a persisted review with both `reviewed_at` and
 * a non-null `progress_percent` is, by construction, finalized review
 * evidence. Nullable and additive: every existing review keeps a null
 * `progress_percent` (a valid historical narrative record that simply
 * carries no structured assessment) and is never backfilled or
 * inferred from its free-text fields.
 *
 * unsignedTinyInteger matches student_learning_plans.progress_percent
 * exactly (the project's established integer-percentage type), which
 * already enforces >= 0; the CHECK constraint adds the upper bound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_plan_reviews', function (Blueprint $table): void {
            $table->unsignedTinyInteger('progress_percent')->nullable()->after('review_number');
        });

        DB::statement(
            'ALTER TABLE learning_plan_reviews ADD CONSTRAINT chk_learning_plan_reviews_progress_percent '
            .'CHECK (progress_percent IS NULL OR progress_percent <= 100)',
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE learning_plan_reviews DROP CONSTRAINT chk_learning_plan_reviews_progress_percent');

        Schema::table('learning_plan_reviews', function (Blueprint $table): void {
            $table->dropColumn('progress_percent');
        });
    }
};
