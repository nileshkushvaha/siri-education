<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §25.19-25.20: one row per reply/internal note. `visibility`
 * (requester_visible | internal_note) is the single flag every query
 * and view must filter on before showing a reply to a student or
 * instructor — internal notes must never be exposed through the
 * public-reply channel (§25.42). Immutable at the app layer
 * (PreventsHardDeletion + PreventsUpdates on the model) — a reply is
 * never edited or deleted, only added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_case_replies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('support_case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();

            $table->string('visibility', 20);
            $table->string('body', 4000);

            $table->timestamps();

            $table->index(['support_case_id', 'created_at']);
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_replies');
    }
};
