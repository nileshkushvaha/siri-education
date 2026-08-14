<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4C.2 — the immutable record of which completed lesson drew
 * which package unit.
 *
 * `used_quantity` alone is a counter: it says how many were taken, not
 * which lessons took them, and it cannot make a replayed completion
 * safe. This ledger answers both.
 *
 * `UNIQUE(lesson_id)` is the idempotency guarantee, and it is global on
 * purpose: one lesson may consume at most one package unit, ever, from
 * any entitlement. A replayed LessonCompleted, a queue retry, an admin
 * re-run, or two concurrent workers all collide here even if every
 * application-level check were somehow bypassed.
 *
 * Rows are never updated or deleted — the model enforces both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_package_entitlement_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('entitlement_id');
            // One consumption per lesson, globally and forever.
            $table->uuid('lesson_id')->unique();

            // Denormalized from the lesson so the ledger stays readable
            // and reportable on its own.
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('instructor_id');

            $table->timestamp('consumed_at');
            $table->timestamps();

            $table->foreign('entitlement_id')->references('id')->on('student_package_entitlements')->restrictOnDelete();
            $table->foreign('lesson_id')->references('id')->on('lessons')->restrictOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('instructor_id')->references('id')->on('users')->restrictOnDelete();

            $table->index('entitlement_id');
            $table->index('student_id');
            $table->index('instructor_id');
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_package_entitlement_consumptions');
    }
};
