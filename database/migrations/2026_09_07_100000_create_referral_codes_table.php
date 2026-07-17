<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table): void {
            $table->id();
            // restrictOnDelete: a referral code is a historical business
            // record — deleting the owning user must be blocked while a
            // code (and therefore possibly attributions) reference it,
            // matching the wallet/rating-aggregate delete restrictions.
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            // Stored uppercase-normalized; utf8mb4_unicode_ci makes the
            // unique index case-insensitive, so a case-variant duplicate
            // is rejected by the database itself.
            $table->string('code', 20)->unique();
            $table->string('status')->default('active');
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disable_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
