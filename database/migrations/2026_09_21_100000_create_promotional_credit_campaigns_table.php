<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-041 (remaining promotional-credit portion) / SRS §13.20,
 * §16.17-§16.19. Mirrors `referral_campaigns`' shape and constraint
 * style — a fixed-amount-only campaign (no percentage/basis-point
 * reward type, per requirement #2's literal field list: "credit
 * amount and currency"). `total_budget_minor` is nullable (optional
 * cap); SRS §20.17 marks "Campaign budget cap" as a "future" setting,
 * but this phase's own explicit requirement asks for it as an
 * additive, reversible field — building it does not violate any V1
 * restriction, so it is included (see Phase 33 final report).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotional_credit_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('status')->default('draft');

            // UTC, half-open window: [starts_at, ends_at) — matches
            // referral_campaigns' own window convention.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->char('currency_code', 3);

            $table->unsignedSmallInteger('per_student_limit')->default(1);
            $table->unsignedBigInteger('total_budget_minor')->nullable();

            $table->text('terms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        DB::statement('ALTER TABLE promotional_credit_campaigns ADD CONSTRAINT chk_promo_campaigns_time_range CHECK (starts_at < ends_at)');
        DB::statement('ALTER TABLE promotional_credit_campaigns ADD CONSTRAINT chk_promo_campaigns_amount_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE promotional_credit_campaigns ADD CONSTRAINT chk_promo_campaigns_per_student_limit CHECK (per_student_limit >= 1)');
        DB::statement('ALTER TABLE promotional_credit_campaigns ADD CONSTRAINT chk_promo_campaigns_budget_positive CHECK (total_budget_minor IS NULL OR total_budget_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotional_credit_campaigns');
    }
};
