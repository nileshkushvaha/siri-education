<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One materialized rating summary per instructor, mirroring
 * the Wallet/WalletLedgerEntry pattern: this row is the maintained
 * counter, `review_rating_contributions` is the durable per-review
 * ledger that makes every update idempotent and rebuildable. Sums and
 * counts are the only authoritative values — averages are always
 * derived fresh from them (never stored, never re-derived from an
 * already-rounded average), so no rounding error can accumulate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_rating_aggregates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('instructor_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('eligible_review_count')->default(0);
            $table->unsignedBigInteger('overall_rating_sum')->default(0);
            $table->json('rating_distribution')->nullable(); // {"1": 0, ..., "5": 0}, lazily keyed

            $table->unsignedInteger('paid_review_count')->default(0);
            $table->unsignedInteger('demo_review_count')->default(0);

            // Five optional teaching dimensions — missing ratings never
            // count toward that dimension's sum or count.
            $table->unsignedBigInteger('teaching_quality_rating_sum')->default(0);
            $table->unsignedInteger('teaching_quality_rating_count')->default(0);
            $table->unsignedBigInteger('communication_rating_sum')->default(0);
            $table->unsignedInteger('communication_rating_count')->default(0);
            $table->unsignedBigInteger('punctuality_rating_sum')->default(0);
            $table->unsignedInteger('punctuality_rating_count')->default(0);
            $table->unsignedBigInteger('preparedness_rating_sum')->default(0);
            $table->unsignedInteger('preparedness_rating_count')->default(0);
            $table->unsignedBigInteger('learning_value_rating_sum')->default(0);
            $table->unsignedInteger('learning_value_rating_count')->default(0);

            $table->timestamp('last_published_review_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('rebuilt_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_rating_aggregates');
    }
};
