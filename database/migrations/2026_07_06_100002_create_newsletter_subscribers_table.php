<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 255)->unique();
            $table->string('name', 150)->nullable();
            $table->string('status', 20)->default('subscribed');
            $table->string('unsubscribe_token', 64)->unique();
            $table->string('source', 100)->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
