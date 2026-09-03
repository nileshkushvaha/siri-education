<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `lesson_ai_summaries.lesson_id` shipped `CASCADE` and was the only
 * child of a historical parent still doing so — every other dependent of
 * `bookings`/`lessons`/`lesson_reviews`/`lesson_review_eligibilities` was
 * converted to RESTRICT by
 * 2026_08_31_100000_restrict_booking_historical_dependency_deletes. This
 * table was created after that sweep and missed the rule, so a physical
 * lesson delete could still silently take its AI summary with it.
 *
 * The baseline `create_lesson_ai_summaries_table` migration now declares
 * `restrictOnDelete()` for the benefit of fresh databases. This migration
 * exists because that is not enough on its own: any environment that
 * already ran the baseline keeps the old CASCADE rule forever, since
 * editing a migration file never retrofits a database that has already
 * applied it. Only the `ON DELETE` rule changes here — no table is
 * dropped or recreated and no data is touched.
 *
 * Guarded by hasTable() so it is a no-op on an environment where the
 * baseline has not run yet (there, the baseline already ships RESTRICT
 * and there is nothing to alter).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lesson_ai_summaries')) {
            return;
        }

        Schema::table('lesson_ai_summaries', function (Blueprint $table): void {
            $table->dropForeign(['lesson_id']);
            $table->foreign('lesson_id')->references('id')->on('lessons')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lesson_ai_summaries')) {
            return;
        }

        Schema::table('lesson_ai_summaries', function (Blueprint $table): void {
            $table->dropForeign(['lesson_id']);
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
        });
    }
};
