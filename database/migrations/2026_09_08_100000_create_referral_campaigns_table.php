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
        Schema::create('referral_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            // UTC, half-open window: [starts_at, ends_at)
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reward_type');
            // Percentage: integer basis points (500 = 5%). Fixed: integer
            // minor units in reward_currency. Never a float in either case.
            $table->unsignedInteger('reward_value');
            $table->foreignId('reward_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('reward_currency_code', 3)->nullable();
            $table->unsignedSmallInteger('min_completed_paid_lessons')->default(1);
            $table->unsignedSmallInteger('max_rewarded_classes')->default(10);
            $table->string('reward_timing')->default('immediate');
            $table->unsignedSmallInteger('hold_days')->default(0);
            $table->boolean('requires_fraud_review')->default(false);
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The resolver's query shape: active campaigns covering an
            // instant. No speculative reporting indexes.
            $table->index(['status', 'starts_at', 'ends_at']);
        });

        DB::statement('ALTER TABLE referral_campaigns ADD CONSTRAINT chk_referral_campaigns_time_range CHECK (starts_at < ends_at)');
        DB::statement('ALTER TABLE referral_campaigns ADD CONSTRAINT chk_referral_campaigns_reward_positive CHECK (reward_value > 0)');
        // Safe percentage ceiling: never more than 100% of a lesson.
        DB::statement("ALTER TABLE referral_campaigns ADD CONSTRAINT chk_referral_campaigns_percentage_max CHECK (reward_type <> 'percentage' OR reward_value <= 10000)");
        DB::statement('ALTER TABLE referral_campaigns ADD CONSTRAINT chk_referral_campaigns_min_lessons CHECK (min_completed_paid_lessons >= 1)');
        DB::statement('ALTER TABLE referral_campaigns ADD CONSTRAINT chk_referral_campaigns_max_classes CHECK (max_rewarded_classes >= 1)');
        // Fixed rewards carry a currency; percentage rewards must not
        // (they follow the eligible lesson's own currency later).
        DB::statement("ALTER TABLE referral_campaigns ADD CONSTRAINT chk_referral_campaigns_currency_pairing CHECK ((reward_type = 'fixed' AND reward_currency_code IS NOT NULL) OR (reward_type <> 'fixed' AND reward_currency_code IS NULL))");
        DB::statement("ALTER TABLE referral_campaigns ADD CONSTRAINT chk_referral_campaigns_hold_pairing CHECK ((reward_timing = 'immediate' AND hold_days = 0) OR (reward_timing <> 'immediate' AND hold_days >= 1))");

        // Country eligibility: normalized pivot on country_id (ISO2 lives
        // on the countries row — names are never business keys). No rows
        // for a campaign means "all countries".
        Schema::create('referral_campaign_countries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_campaign_id')->constrained('referral_campaigns')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['referral_campaign_id', 'country_id'], 'referral_campaign_countries_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_campaign_countries');
        Schema::dropIfExists('referral_campaigns');
    }
};
