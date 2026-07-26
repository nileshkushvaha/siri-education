<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instructor payout destinations (bank transfer only today).
 * Sensitive bank details live exclusively in `encrypted_details`
 * (Laravel encrypted cast); everything else stored here is safe to
 * display. `fingerprint` is a keyed HMAC of the normalized identifying
 * fields — duplicate detection without revealing account data. Verified
 * details are immutable; a replacement method is created instead.
 * Financial history: soft deletes only, restrictive FK behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_payout_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('instructor_id')->constrained('users');
            $table->string('type', 32)->default('bank_transfer');
            $table->foreignId('country_id')->nullable()->constrained('countries');
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->string('currency_code', 3);
            $table->string('display_label');
            $table->string('masked_identifier', 64);
            $table->string('fingerprint', 64);
            $table->longText('encrypted_details');
            $table->string('status', 32)->default('draft');
            $table->boolean('is_default')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->string('rejection_reason', 1000)->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['instructor_id', 'status']);
            $table->index(['instructor_id', 'is_default']);
            $table->index(['instructor_id', 'fingerprint']);
            $table->index('status');
            $table->index('type');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE instructor_payout_methods
                ADD COLUMN active_default_owner_key BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN is_default = 1
                             AND status = 'verified'
                             AND deleted_at IS NULL
                            THEN instructor_id
                            ELSE NULL
                        END
                    ) STORED,
                ADD UNIQUE INDEX ipm_active_default_owner_unique (active_default_owner_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_payout_methods');
    }
};
