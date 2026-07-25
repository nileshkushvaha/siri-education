<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §25.12: one row per numbering scope (annual, mirroring
 * InvoiceNumberSequence). next_number is allocated under a row lock
 * inside the same transaction as the support case it numbers; the
 * unique index on support_cases.case_number is the final defense.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_case_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 20)->unique();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_number_sequences');
    }
};
