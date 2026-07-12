<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a verified `instructor_payout_methods` row to its provider-side
 * provisioning state (RazorpayX Contact → Fund Account this phase; the
 * `provider` column exists so a future provider never has to reshape
 * this table). No bank details live here — only opaque provider
 * identifiers and our own keyed fingerprint of what was sent, used
 * solely to detect drift, never to reconstruct account numbers.
 * Financial provisioning history: soft deletes only, never hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_payout_destination_provider_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('payout_method_id');
            $table->foreignId('instructor_id');
            $table->string('provider', 32)->default('razorpayx');

            $table->string('provider_contact_id', 64)->nullable();
            $table->string('provider_contact_reference', 40);
            $table->string('provider_contact_status', 32)->nullable();

            $table->string('provider_fund_account_id', 64)->nullable();
            $table->string('provider_fund_account_status', 32)->nullable();

            $table->string('bank_details_fingerprint', 64);
            $table->string('status', 32)->default('pending');

            $table->timestamp('ip_allowlisting_confirmed_at')->nullable();
            $table->foreignId('ip_allowlisting_confirmed_by')->nullable();

            $table->timestamp('last_provisioning_attempt_at')->nullable();
            $table->string('last_provisioning_error', 500)->nullable();
            $table->unsignedInteger('provisioning_attempts')->default(0);

            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('payout_method_id', 'ipdpl_payout_method_fk')
                ->references('id')->on('instructor_payout_methods');
            $table->foreign('instructor_id', 'ipdpl_instructor_fk')
                ->references('id')->on('users');
            $table->foreign('ip_allowlisting_confirmed_by', 'ipdpl_ip_confirmed_by_fk')
                ->references('id')->on('users');
            $table->foreign('disabled_by', 'ipdpl_disabled_by_fk')
                ->references('id')->on('users');

            $table->unique(['payout_method_id', 'provider'], 'ipdpl_method_provider_unique');
            $table->index(['instructor_id', 'provider'], 'ipdpl_instructor_provider_idx');
            $table->index('provider_contact_id', 'ipdpl_contact_id_idx');
            $table->index('provider_fund_account_id', 'ipdpl_fund_account_id_idx');
            $table->index('status', 'ipdpl_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_payout_destination_provider_links');
    }
};
