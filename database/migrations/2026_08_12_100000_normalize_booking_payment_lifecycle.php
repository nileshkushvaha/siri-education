<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16C — widens booking_payments.status for Stripe's async SCA/3DS
 * flow (processing) and reconciliation's ambiguous-outcome states
 * (unknown, resolution_required), and adds last_synced_at so a
 * reconciliation poll can record "we checked" without implying a
 * status change (mirrors instructor_payout_attempts.last_synced_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE booking_payments DROP CONSTRAINT chk_booking_payments_status');

        DB::statement(<<<'SQL'
            ALTER TABLE booking_payments
                ADD CONSTRAINT chk_booking_payments_status
                CHECK (status IN (
                    'pending', 'authorized', 'processing', 'captured', 'failed',
                    'cancelled', 'expired', 'refunded', 'unknown', 'resolution_required'
                ))
        SQL);

        Schema::table('booking_payments', function (Blueprint $table): void {
            $table->timestamp('last_synced_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_payments', function (Blueprint $table): void {
            $table->dropColumn('last_synced_at');
        });

        DB::statement('ALTER TABLE booking_payments DROP CONSTRAINT chk_booking_payments_status');

        DB::statement(<<<'SQL'
            ALTER TABLE booking_payments
                ADD CONSTRAINT chk_booking_payments_status
                CHECK (status IN ('pending', 'authorized', 'captured', 'failed', 'cancelled', 'expired', 'refunded'))
        SQL);
    }
};
