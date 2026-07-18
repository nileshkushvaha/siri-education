<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verification_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('phone_fingerprint', 64);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts_remaining')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'phone_fingerprint', 'created_at'], 'phone_challenge_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verification_challenges');
    }
};
