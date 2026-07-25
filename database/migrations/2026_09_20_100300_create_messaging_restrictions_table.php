<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §17.36 "Restrict messaging" / "Suspend messaging access" — a
 * user-level (not per-conversation) restriction. At most one ACTIVE
 * row per user is enforced by the service (checked-then-inserted
 * inside a transaction, mirroring InvoiceService's idempotency
 * pattern) rather than a partial unique index, since MySQL has no
 * native partial/filtered unique index — history of prior lifted
 * restrictions must be preserved (never hard-deleted), so a plain
 * unique(user_id) would block ever restricting the same user twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_restrictions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamp('applied_at');

            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lifted_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'lifted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_restrictions');
    }
};
