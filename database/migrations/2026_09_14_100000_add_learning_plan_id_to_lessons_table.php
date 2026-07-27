<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §6.17.5 / §6.17.10: a lesson may optionally belong to
 * the one compatible active learning plan resolved at creation time —
 * zero-or-one, never many. Nullable and additive: existing lessons are
 * left unlinked, never backfilled by this migration.
 *
 * restrictOnDelete mirrors homework_assignments.learning_plan_id
 * exactly: a lesson is an educational record and must never be
 * silently orphaned or unlinked by a plan hard-delete. Plans are
 * archived via status + soft delete in normal operation, so the
 * restrict path never fires there — it only closes the door on a
 * genuine hard delete with dependent lessons. No cascade: removing a
 * plan must never take a lesson (or its outcome history) with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedBigInteger('learning_plan_id')->nullable()->after('academic_level_id');
            $table->foreign('learning_plan_id')->references('id')->on('student_learning_plans')->restrictOnDelete();
            $table->index('learning_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropForeign(['learning_plan_id']);
            $table->dropIndex(['learning_plan_id']);
            $table->dropColumn('learning_plan_id');
        });
    }
};
