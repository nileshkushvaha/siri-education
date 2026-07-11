<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14.4 — remove the commission-era columns. Since Phase 14.2 the
 * instructor compensation model is agreement-based and independent of
 * student pricing; these columns existed only for the removed
 * percentage-of-student-price calculation and were verified to hold
 * ZERO data in every environment. The student payment amount itself is
 * not lost — the booking/payment domain (booking_payments) legitimately
 * owns it; only its connection to instructor compensation is removed.
 *
 * Guarded: refuses to run if any row still carries legacy values, so
 * this migration can never destroy historical financial data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacyRows = DB::table('instructor_earnings')
            ->whereNotNull('student_amount_minor')
            ->orWhereNotNull('platform_margin_minor')
            ->orWhereNotNull('calculation_value')
            ->orWhereIn('calculation_type', ['percentage', 'fixed'])
            ->count();

        if ($legacyRows > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to drop legacy commission columns: %d instructor_earnings row(s) still carry legacy values. Historical financial data must never be destroyed.',
                $legacyRows,
            ));
        }

        Schema::table('instructor_earnings', function (Blueprint $table): void {
            $table->dropColumn(['student_amount_minor', 'platform_margin_minor', 'calculation_value']);
        });
    }

    public function down(): void
    {
        Schema::table('instructor_earnings', function (Blueprint $table): void {
            $table->unsignedBigInteger('student_amount_minor')->nullable()->after('currency_code');
            $table->unsignedBigInteger('platform_margin_minor')->nullable()->after('earning_amount_minor');
            $table->decimal('calculation_value', 10, 4)->nullable()->after('calculation_type');
        });
    }
};
