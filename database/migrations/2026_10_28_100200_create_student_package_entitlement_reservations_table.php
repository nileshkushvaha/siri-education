<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4D — the answer to the capacity hole Phase 4C deliberately
 * left open.
 *
 * Phase 4C consumes an entitlement unit only on LessonCompleted, which
 * is correct (a lesson that never happens must not burn a lesson) but
 * means `used_quantity` — and therefore the generated
 * `remaining_quantity` — does not move at booking time. With 1 unit
 * remaining, a student could otherwise schedule three future bookings
 * against that same unit and only discover the shortfall at delivery.
 *
 * WHY A LEDGER RATHER THAN A DERIVED COUNT
 * The audit (spec §17) asked whether reserved capacity could instead be
 * derived as "remaining - count(active unconsumed package-funded
 * bookings)". It can be computed, but not safely relied upon:
 *   - correctness would depend on a status-set predicate over
 *     `bookings` staying permanently in sync with every future addition
 *     to the booking lifecycle (cancel, expire, reschedule, no-show,
 *     abort) — a silent-overbooking bug the day a status is added;
 *   - there is a real window between a lesson reaching a completed
 *     outcome and ConsumePackageEntitlementOnLessonCompleted writing the
 *     consumption row, during which a booking counts as neither
 *     reserved nor consumed (double-spend) or as both (phantom
 *     shortage), depending on how the predicate is written;
 *   - booking creation serializes on an INSTRUCTOR row lock, which
 *     happens to also serialize same-entitlement bookings today, but
 *     that is incidental — entitlement capacity must not depend on a
 *     lock taken for an unrelated reason.
 * An explicit row makes the committed unit a first-class fact with its
 * own lifecycle, checked under the entitlement's own lock.
 *
 * `booking_id` is UNIQUE: one booking can commit at most one unit, and
 * a retried creation can never double-reserve. Combined with the
 * entitlement row lock taken in PackageEntitlementService, that is what
 * makes concurrent reservation of a last remaining unit resolve to
 * exactly one winner (spec §21) — a DB constraint, not a pre-request
 * count.
 *
 * Reservations are never deleted (spec §28): a released or consumed
 * reservation stays as audit history, distinguished by `status` plus
 * its released_at/consumed_at timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_package_entitlement_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('entitlement_id');
            // One reservation per booking, forever — the DB-level guard
            // against double-reserving a single booking.
            $table->uuid('booking_id')->unique();

            // reserved | released | consumed —
            // App\Package\Enums\PackageEntitlementReservationStatus
            $table->string('status', 20)->default('reserved');

            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            // Why a reservation left Reserved — cancellation, expiry
            // sweep, non-consuming outcome. Audit only; never a rule input.
            $table->string('release_reason', 100)->nullable();

            $table->timestamps();

            // Financial-adjacent history — an entitlement or booking
            // that has committed capacity must never vanish beneath it.
            $table->foreign('entitlement_id')->references('id')->on('student_package_entitlements')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();

            // The exact index availableToBook() counts on: active
            // reservations for one entitlement.
            $table->index(['entitlement_id', 'status'], 'speres_entitlement_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_package_entitlement_reservations');
    }
};
