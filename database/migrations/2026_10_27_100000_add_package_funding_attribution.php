<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4C.1 — deterministic package-funding attribution.
 *
 * The Phase 4C audit found that nothing recorded WHICH lesson was meant
 * to draw on a package. Without that, the only way to consume would be
 * "on completion, look for any matching entitlement the student happens
 * to own" — which would burn package lessons on lessons the student
 * paid for normally, and would silently pick between two matching
 * entitlements. Both are unacceptable, so intent is now explicit.
 *
 *   bookings.package_entitlement_id   set when the student deliberately
 *                                     schedules from a package
 *   lessons.package_entitlement_id    snapshotted at lesson creation
 *
 * NULL is the overwhelmingly common case and means "ordinary lesson,
 * no package involved" — never "search for one".
 *
 * The lesson keeps its own copy rather than reading through the
 * booking: a lesson is the unit that gets consumed, its funding must
 * remain legible if the booking is later amended, and consumption must
 * never depend on a join that could change underneath it.
 *
 * restrictOnDelete on both: an entitlement that has funded scheduled
 * lessons is financial history and must not vanish beneath them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->uuid('package_entitlement_id')->nullable()->after('payment_reference');
            $table->foreign('package_entitlement_id')->references('id')->on('student_package_entitlements')->restrictOnDelete();
            $table->index('package_entitlement_id');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->uuid('package_entitlement_id')->nullable()->after('learning_plan_id');
            $table->foreign('package_entitlement_id')->references('id')->on('student_package_entitlements')->restrictOnDelete();
            $table->index('package_entitlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['package_entitlement_id']);
            $table->dropColumn('package_entitlement_id');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropForeign(['package_entitlement_id']);
            $table->dropColumn('package_entitlement_id');
        });
    }
};
