<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §6.19/§10.28: one row per waitlist join. The
 * canonical, unique target is (student, instructor) — "Waitlists are
 * instructor-specific" (§6.19) — everything else the SRS says the
 * waitlist "may collect" (subject, preferred days/time, duration,
 * demo/paid, recurring) is optional descriptive metadata, never part
 * of the uniqueness key.
 *
 * MySQL has no partial/filtered unique index, so "at most one ACTIVE
 * entry per (student, instructor), but unlimited historical entries"
 * is expressed with an active-key column: `active_key` is
 * `{student_user_id}-{instructor_user_id}` while status = Waiting, and
 * NULL for every terminal status. A unique index on a nullable column
 * allows unlimited NULLs (MySQL never treats NULL = NULL), so only the
 * one currently-active row per pair is constrained — terminal rows
 * accumulate freely as permanent history.
 *
 * cascadeOnDelete on both user FKs mirrors
 * student_favorite_instructors' identical (student, instructor)
 * relationship shape exactly (the closest existing structural analog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_waitlist_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instructor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('active_key', 40)->nullable()->unique();

            // Optional descriptive metadata (SRS §10.28 "the waitlist
            // may collect") — never part of the uniqueness key, never
            // used to gate eligibility.
            $table->uuid('subject_id')->nullable();
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->json('preferred_days')->nullable();
            $table->time('preferred_time_start')->nullable();
            $table->time('preferred_time_end')->nullable();
            $table->unsignedSmallInteger('lesson_duration_minutes')->nullable();
            $table->string('booking_type_preference', 20)->nullable();
            $table->boolean('recurring_preferred')->nullable();

            $table->timestamp('joined_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('ineligible_at')->nullable();

            $table->uuid('fulfilled_booking_id')->nullable();
            $table->foreign('fulfilled_booking_id')->references('id')->on('bookings')->nullOnDelete();

            $table->timestamps();

            $table->index(['instructor_user_id', 'status', 'joined_at'], 'waitlist_instructor_status_joined_idx');
            $table->index(['student_user_id', 'status'], 'waitlist_student_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_waitlist_entries');
    }
};
