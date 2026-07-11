<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups released instructor earnings into one instructor + one
 * currency settlement units. No external bank transfer happens in this
 * phase — marking a batch paid is a manual admin action recording that
 * money moved outside the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_settlement_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('batch_reference', 32)->unique();
            $table->foreignId('instructor_id')->constrained('users');
            $table->string('currency_code', 3);
            $table->unsignedBigInteger('total_amount_minor');
            $table->string('status', 32)->default('draft');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_settlement_batches');
    }
};
