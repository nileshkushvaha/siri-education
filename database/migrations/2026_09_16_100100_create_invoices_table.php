<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §14.21-14.24 (GAP-007): one immutable invoice/receipt per
 * successful booking payment or wallet recharge. Every student/
 * financial/platform field is a snapshot taken at issuance — never a
 * live join — so a later profile, country, or GeneralSettings change
 * can never alter a historical invoice (§14.22 "should remain
 * immutable"). source_type is a literal FQCN string, matching the
 * established convention on wallet_ledger_entries.source_type (this
 * project's own closest analogous polymorphic-source column already
 * uses raw class names, not an enum or morph map).
 *
 * restrictOnDelete on user_id mirrors homework_assignments/
 * learning_plan_reviews' financial/educational-record retention
 * convention: an invoice must never be silently orphaned by a user
 * hard-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('invoice_number', 50)->unique();

            $table->string('source_type', 50);
            $table->string('source_id', 60);

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            // Student billing snapshot.
            $table->string('student_name', 255);
            $table->string('billing_country', 255)->nullable();

            // Financial snapshot.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->timestamp('payment_date');
            $table->string('payment_reference', 100);
            $table->string('service_description', 255);
            $table->string('booking_reference', 50)->nullable();
            $table->string('wallet_recharge_reference', 100)->nullable();

            // Platform-business snapshot (GeneralSettings at issuance).
            $table->string('organization_name', 255)->nullable();
            $table->string('organization_address', 255)->nullable();
            $table->string('organization_support_email', 255)->nullable();
            $table->string('organization_support_phone', 60)->nullable();
            $table->string('organization_website_url', 255)->nullable();

            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
