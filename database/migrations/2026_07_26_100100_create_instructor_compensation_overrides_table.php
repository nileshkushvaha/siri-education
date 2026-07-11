<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14.2 — optional hourly-rate overrides scoped to one agreement
 * (instructor + subject / education level / lesson duration). Overrides
 * inherit the agreement's effective window and currency, so no separate
 * effective dating can drift; `combo_key` is the NULL-safe normalized
 * dimension key that makes the uniqueness real (MySQL unique indexes
 * treat NULLs as distinct). Editable only while the agreement is
 * draft/scheduled — active financial terms stay immutable. Periodic
 * (daily/weekly/monthly) agreements never use overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_compensation_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agreement_id')->constrained('instructor_compensation_agreements');
            $table->foreignUuid('subject_id')->nullable()->constrained('subjects');
            $table->foreignUuid('academic_level_id')->nullable()->constrained('academic_levels');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('combo_key', 96);
            $table->timestamps();

            $table->unique(['agreement_id', 'combo_key'], 'ico_agreement_combo_unique');
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_compensation_overrides');
    }
};
