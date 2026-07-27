<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participant-reported meeting problems. A valid in-window
 * report also flags the lesson's attendance record
 * (technical_issue_reported_at), which is what actually holds the
 * automation — this table is the review queue and audit surface.
 * Reports after the window or after finalization stay stored but
 * flagged for administrative review instead of touching the hold.
 * Descriptions are sanitized; no secrets, tokens, links, emails, phone
 * numbers, or raw provider payloads are ever stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_technical_issue_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignUuid('booking_meeting_id')->nullable()->constrained('booking_meetings')->nullOnDelete();
            $table->string('reporter', 16); // student | instructor — derived, never client-supplied
            $table->foreignId('reported_by')->constrained('users');

            $table->string('category', 32);
            $table->string('description', 1000)->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->string('status', 32)->default('pending');
            // Report arrived after the reporting window closed.
            $table->boolean('flagged_late')->default(false);
            // Report arrived after the lesson outcome was finalized.
            $table->boolean('after_finalization')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();

            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_technical_issue_reports');
    }
};
