<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SRS §17.35-§17.36: one report per reporter per message. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();

            $table->string('reason', 40);
            $table->string('details', 1000)->nullable();

            $table->string('status', 20)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_notes', 1000)->nullable();

            $table->timestamps();

            $table->unique(['message_id', 'reporter_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reports');
    }
};
