<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider payout status notifications, written by
 * InstructorPayoutExecutionService after normalizeEvent() validates
 * them — for both the fake provider (reconciliation service / tests)
 * and RazorpayX's public webhook route. `payload_hash` gives duplicate
 * detection independent of `provider_event_id` (a provider that
 * reissues the same logical event under a new ID is still caught).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_payout_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('provider_event_id', 120);
            $table->string('event_type', 60);
            $table->string('provider_payout_id', 80)->nullable();
            $table->string('payload_hash', 64);
            $table->boolean('signature_valid')->default(true);
            $table->string('processing_status', 20)->default('pending');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->foreignUuid('duplicate_of_id')->nullable()->constrained('instructor_payout_provider_events');
            $table->string('failure_reason', 500)->nullable();
            $table->longText('encrypted_payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'ippe_provider_event_unique');
            $table->index('provider_payout_id');
            $table->index('payload_hash');
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_payout_provider_events');
    }
};
