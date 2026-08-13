<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personalized Instructor Package Proposal foundation — an
 * instructor-created, admin-approved proposal to sell an existing
 * student a personalized lesson package. Carries its own immutable
 * price snapshot (unit/calculated/override/final, all in the
 * student's resolved currency, locked at submission — never
 * admin-editable) and quantity snapshot (paid/bonus/total, copied
 * from PackageBenefitRule at submission time so a later edit to the
 * rule never rewrites a submitted proposal's numbers).
 *
 * instructor_id/student_id use restrictOnDelete (never cascade) —
 * financial-adjacent history must never silently disappear with a
 * user row. Every other master-data FK uses nullOnDelete: an
 * archived/renamed Subject/AcademicLevel/Country/Currency must never
 * block or cascade-delete a proposal; the denormalized snapshot
 * columns remain the historical record even if the id link is later
 * nulled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_package_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('instructor_id');
            $table->unsignedBigInteger('student_id');
            $table->uuid('package_benefit_rule_id')->nullable();

            $table->uuid('subject_id')->nullable();
            $table->uuid('academic_level_id')->nullable();
            $table->uuid('booking_type_id')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->char('currency_code', 3)->nullable();

            // Price snapshot — populated once price is first resolved
            // (create()), re-resolved on recalculate()/submit(), frozen
            // thereafter. final = override_price_minor ?? calculated_price_minor.
            $table->unsignedBigInteger('unit_price_minor')->nullable();
            $table->unsignedSmallInteger('paid_quantity')->nullable();
            $table->unsignedSmallInteger('bonus_quantity')->nullable();
            $table->unsignedSmallInteger('total_quantity')->nullable();
            $table->unsignedBigInteger('calculated_price_minor')->nullable();
            $table->unsignedBigInteger('override_price_minor')->nullable();
            $table->unsignedBigInteger('final_price_minor')->nullable();
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->text('override_reason')->nullable();

            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('instructor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('package_benefit_rule_id')->references('id')->on('package_benefit_rules')->nullOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();
            $table->foreign('booking_type_id')->references('id')->on('booking_types')->nullOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->nullOnDelete();
            $table->foreign('overridden_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('instructor_id');
            $table->index('student_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE instructor_package_proposals ADD CONSTRAINT chk_instructor_package_proposals_quantity CHECK (total_quantity IS NULL OR total_quantity = paid_quantity + bonus_quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_package_proposals');
    }
};
