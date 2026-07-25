<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS §17.31/§17.41: one immutable message per send — no edit, no
 * hard delete (enforced at the app layer via PreventsHardDeletion +
 * PreventsUpdates, matching support_case_replies' convention).
 * `read_at` is a single nullable timestamp because a conversation has
 * exactly two participants — a message's only possible reader is
 * "the participant who did not send it", so no separate per-recipient
 * read table is needed. `flagged_leakage`/`flagged_leakage_reasons`
 * are deterministic policy flags (§17.32/§17.33) set at send time —
 * never a body mutation, never AI-derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();

            $table->string('body', 2000);

            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();

            $table->boolean('flagged_leakage')->default(false);
            $table->json('flagged_leakage_reasons')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
            $table->index('sender_id');
            $table->index('flagged_leakage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
