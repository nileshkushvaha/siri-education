<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17M — append-only report record against one published public
 * review. Many reports may exist per review (one row per reporter per
 * reason); nothing here is ever physically deleted or edited except
 * through the guarded status transition + resolution fields. Hiding/
 * rejecting/archiving/restoring the underlying review is never done
 * by writing `lesson_reviews.status` from this table — that always
 * goes through the existing ReviewModerationService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained('lesson_reviews')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users');

            $table->string('reason', 32);
            $table->string('explanation', 1000)->nullable(); // sanitized plain text only

            $table->string('status', 32)->default('pending');
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('resolution_reason', 500)->nullable();
            $table->string('resolution_action', 32)->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->index(['review_id', 'status']);
            $table->index(['reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
    }
};
