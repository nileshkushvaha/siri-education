<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribution_id')->constrained('referral_attributions')->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('referral_campaigns')->restrictOnDelete();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_student_id')->constrained('users')->restrictOnDelete();
            // One reward per lesson, ever — the duplicate-delivery and
            // concurrent-evaluation guard of last resort.
            $table->uuid('lesson_id')->unique();
            $table->uuid('booking_id');
            $table->unsignedSmallInteger('class_sequence');
            // Full calculation snapshot: inputs (lesson amount/currency,
            // campaign type/value at evaluation time) AND the computed
            // integer result — never only a percentage.
            $table->unsignedInteger('lesson_amount_minor');
            $table->string('lesson_currency_code', 3);
            $table->string('reward_type');
            $table->unsignedInteger('reward_value');
            $table->unsignedInteger('reward_amount_minor');
            $table->string('reward_currency_code', 3);
            $table->string('status')->default('eligible');
            $table->string('hold_reason')->nullable();
            $table->string('decision_reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('eligible_at');
            $table->timestamp('credit_ready_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('wallet_ledger_entry_id')->nullable();
            $table->uuid('reversal_ledger_entry_id')->nullable();
            $table->timestamps();

            $table->foreign('lesson_id')->references('id')->on('lessons')->restrictOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->foreign('wallet_ledger_entry_id')->references('id')->on('wallet_ledger_entries')->restrictOnDelete();
            $table->foreign('reversal_ledger_entry_id')->references('id')->on('wallet_ledger_entries')->restrictOnDelete();

            // Belt-and-braces on the class cap: a sequence number can
            // never repeat within one attribution.
            $table->unique(['attribution_id', 'class_sequence'], 'referral_rewards_attribution_sequence_unique');
            // The sweep's query shape.
            $table->index(['status', 'credit_ready_at']);
            // The student page's query shape.
            $table->index(['referrer_id', 'status']);
        });

        DB::statement('ALTER TABLE referral_rewards ADD CONSTRAINT chk_referral_rewards_no_self CHECK (referrer_id <> referred_student_id)');
        DB::statement("ALTER TABLE referral_rewards ADD CONSTRAINT chk_referral_rewards_credited_ledger CHECK (status <> 'credited' OR wallet_ledger_entry_id IS NOT NULL)");
        DB::statement("ALTER TABLE referral_rewards ADD CONSTRAINT chk_referral_rewards_reversed_ledger CHECK (status <> 'reversed' OR (wallet_ledger_entry_id IS NOT NULL AND reversal_ledger_entry_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
    }
};
