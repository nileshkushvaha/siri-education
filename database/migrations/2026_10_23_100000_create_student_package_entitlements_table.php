<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4A — the consumed-value side of the package domain: what a
 * student actually owns after accepting an approved proposal.
 * Deliberately a separate table from instructor_package_proposals: a
 * proposal is a commercial negotiation (price, approval, override), an
 * entitlement is a balance that gets drawn down. Mixing them would mean
 * mutating an accepted (immutable) commercial record every time a
 * lesson is consumed.
 *
 * Quantity integrity is enforced by the DATABASE, never by the UI or
 * even the service:
 *  - `remaining_quantity` is a STORED GENERATED column — it cannot be
 *    written at all, so it can never drift from total - used. (This is
 *    deliberately stronger than the `wallets` CHECK-constraint
 *    precedent: a wallet's three balance columns are each independently
 *    meaningful, whereas `remaining_quantity` is purely derived.)
 *  - CHECK constraints additionally pin total = paid + bonus and keep
 *    used within [0, total], so over-consumption fails at the DB even
 *    under a concurrent double-spend.
 *  - `proposal_id` is UNIQUE — accepting the same proposal twice is
 *    impossible by construction, not merely guarded in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_package_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('instructor_id');
            // One entitlement per proposal, forever — the DB-level guard
            // against duplicate acceptance.
            $table->uuid('proposal_id')->unique();

            $table->uuid('subject_id')->nullable();
            $table->uuid('academic_level_id')->nullable();
            $table->uuid('booking_type_id')->nullable();

            $table->unsignedSmallInteger('paid_quantity');
            $table->unsignedSmallInteger('bonus_quantity')->default(0);
            $table->unsignedSmallInteger('total_quantity');
            $table->unsignedSmallInteger('used_quantity')->default(0);
            $table->unsignedSmallInteger('remaining_quantity')->storedAs('total_quantity - used_quantity');

            // active | completed | cancelled | expired — App\Package\Enums\PackageEntitlementStatus
            $table->string('status', 20)->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Financial-adjacent history — never cascade a user away.
            $table->foreign('student_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('instructor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('proposal_id')->references('id')->on('instructor_package_proposals')->restrictOnDelete();
            // Academic masters may be archived/renamed without blocking or
            // destroying a student's owned balance.
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->foreign('academic_level_id')->references('id')->on('academic_levels')->nullOnDelete();
            $table->foreign('booking_type_id')->references('id')->on('booking_types')->nullOnDelete();

            $table->index('student_id');
            $table->index('instructor_id');
            $table->index('status');
        });

        DB::statement('ALTER TABLE student_package_entitlements ADD CONSTRAINT chk_student_package_entitlements_quantity CHECK (total_quantity = paid_quantity + bonus_quantity)');
        DB::statement('ALTER TABLE student_package_entitlements ADD CONSTRAINT chk_student_package_entitlements_used_within_total CHECK (used_quantity >= 0 AND used_quantity <= total_quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_package_entitlements');
    }
};
