<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §14.21 lists the receipt's source reference among the required
 * fields ("Booking reference, if applicable / Wallet recharge
 * reference, if applicable"). A package purchase is now a third
 * payable that produces a receipt, so it gets its own nullable
 * reference column rather than being crammed into booking_reference —
 * mirroring exactly how wallet_recharge_reference was added for the
 * second source.
 *
 * Nullable and additive: every existing invoice stays valid and
 * untouched, which §14.22's immutability requirement demands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('package_purchase_reference', 100)
                ->nullable()
                ->after('wallet_recharge_reference');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('package_purchase_reference');
        });
    }
};
