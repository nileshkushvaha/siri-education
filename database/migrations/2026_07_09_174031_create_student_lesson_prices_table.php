<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed student-facing pricing matrix (Phase 10.2D). Replaces
 * `booking_types.price`/`currency` as the authoritative source for a
 * paid lesson's price — those columns remain only as a documented
 * legacy fallback (see BookingPriceCalculator). A booking's own
 * `price`/`currency` columns still hold the point-in-time snapshot at
 * booking creation, so a later change or soft-delete of a row here
 * never rewrites the cost of a booking already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_lesson_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('booking_type_id');
            $table->uuid('subject_id');
            $table->uuid('academic_level_id')->nullable();
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('currency_id');
            $table->char('currency_code', 3);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('booking_type_id')->references('id')->on('booking_types')->restrictOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->restrictOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->restrictOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->restrictOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Resolver match order: exact academic_level first, then NULL
            // ("all levels") — both hit this index; is_active/effective_*
            // are filtered in the resolver query, not part of the index key,
            // since they're low-cardinality booleans/ranges, not lookup keys.
            $table->index(
                ['booking_type_id', 'subject_id', 'country_id', 'duration_minutes', 'academic_level_id'],
                'idx_student_lesson_prices_match',
            );
        });

        DB::statement('ALTER TABLE student_lesson_prices ADD CONSTRAINT chk_student_lesson_prices_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE student_lesson_prices ADD CONSTRAINT chk_student_lesson_prices_effective_range CHECK (effective_from IS NULL OR effective_until IS NULL OR effective_from < effective_until)');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_lesson_prices');
    }
};
