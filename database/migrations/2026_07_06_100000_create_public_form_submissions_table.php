<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_form_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->string('name', 150);
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_form_submissions');
    }
};
