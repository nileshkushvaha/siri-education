<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §14.22: one row per numbering scope (this project scopes
 * annually — see InvoiceSettings/InvoiceNumberAllocator). next_number
 * is allocated under a row lock inside the same transaction as the
 * invoice it numbers; the unique index on invoices.invoice_number is
 * the final defense, not the only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 20)->unique();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
