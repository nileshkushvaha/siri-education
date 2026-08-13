<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4B.1 — package VALIDITY foundation.
 *
 * Three different expiry concepts exist in this domain and must never
 * be merged onto one column:
 *
 *  A. ENTITLEMENT USAGE VALIDITY (this migration) — "once the student
 *     has paid and their lessons are active, how long may they use
 *     them?" Admin-configured on the offer, snapshotted onto the
 *     proposal, and only resolved to an absolute instant when the
 *     entitlement activates after successful payment.
 *  B. PAYMENT-ATTEMPT EXPIRY — a Razorpay order / Stripe intent
 *     lifetime. Provider-owned, unrelated, deliberately not modelled.
 *  C. OFFER/PROPOSAL ACCEPTANCE EXPIRY — "accept within 7 days".
 *     A separate policy, out of scope; do not reuse these columns.
 *
 * NULL means no expiry. Zero is NOT used as a sentinel for unlimited —
 * a CHECK forbids it, so `validity_days = 0` can never be mistaken for
 * "never expires".
 *
 * `instructor_package_proposals.validity_days` is a SNAPSHOT, matching
 * how the proposal already snapshots quantities and price: a later
 * admin edit to the offer must never change an already-submitted
 * proposal.
 *
 * `student_package_entitlements.expires_at` is schema-only in this
 * phase. Nothing computes or enforces it yet — activation-time
 * calculation lands in Phase 4B.3 and automatic expiry in 4C.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_benefit_rules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('validity_days')->nullable()->after('total_quantity');
        });

        Schema::table('instructor_package_proposals', function (Blueprint $table): void {
            $table->unsignedSmallInteger('validity_days')->nullable()->after('total_quantity');
        });

        Schema::table('student_package_entitlements', function (Blueprint $table): void {
            $table->unsignedSmallInteger('validity_days')->nullable()->after('total_quantity');
            $table->timestamp('expires_at')->nullable()->after('completed_at');
        });

        // NULL = never expires; a positive day count otherwise. 0 is rejected
        // so it can never be read as "unlimited" by accident.
        DB::statement('ALTER TABLE package_benefit_rules ADD CONSTRAINT chk_package_benefit_rules_validity_days CHECK (validity_days IS NULL OR validity_days > 0)');
        DB::statement('ALTER TABLE instructor_package_proposals ADD CONSTRAINT chk_instructor_package_proposals_validity_days CHECK (validity_days IS NULL OR validity_days > 0)');
        DB::statement('ALTER TABLE student_package_entitlements ADD CONSTRAINT chk_student_package_entitlements_validity_days CHECK (validity_days IS NULL OR validity_days > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE package_benefit_rules DROP CONSTRAINT chk_package_benefit_rules_validity_days');
        DB::statement('ALTER TABLE instructor_package_proposals DROP CONSTRAINT chk_instructor_package_proposals_validity_days');
        DB::statement('ALTER TABLE student_package_entitlements DROP CONSTRAINT chk_student_package_entitlements_validity_days');

        Schema::table('package_benefit_rules', function (Blueprint $table): void {
            $table->dropColumn('validity_days');
        });

        Schema::table('instructor_package_proposals', function (Blueprint $table): void {
            $table->dropColumn('validity_days');
        });

        Schema::table('student_package_entitlements', function (Blueprint $table): void {
            $table->dropColumn(['validity_days', 'expires_at']);
        });
    }
};
