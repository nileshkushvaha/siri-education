<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24K.1 — GAP-020 partial-channel idempotency: one durable row
 * per (homework_due_reminder, channel). This is what makes a retry of
 * a partially-successful multi-channel send safe — Laravel's own
 * NotificationSender::sendNow() restarts the WHOLE channel list on
 * every call and assigns a fresh random notification UUID each time,
 * so without this table a retry after a mid-list channel failure would
 * re-insert the database notification and re-attempt already-succeeded
 * channels. cascadeOnDelete: these rows have no independent meaning
 * once their parent reminder is gone (the parent itself is never
 * deleted in normal operation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_reminder_channel_deliveries', function (Blueprint $table): void {
            $table->id();
            // Explicit short FK name: the auto-generated name for this
            // column/table pair exceeds MySQL's 64-char identifier limit.
            $table->foreignId('homework_due_reminder_id')
                ->constrained('homework_due_reminders', indexName: 'hw_reminder_channel_deliveries_reminder_fk')
                ->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_category', 60)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['homework_due_reminder_id', 'channel'], 'homework_reminder_channel_deliveries_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_reminder_channel_deliveries');
    }
};
