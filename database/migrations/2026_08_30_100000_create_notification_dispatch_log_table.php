<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The idempotency guard for domain-event-triggered
 * notifications. Laravel's `notifications` table has no dedup key of
 * its own, and every review/quality event in this codebase is
 * designed to fire exactly once per real transition — but a replayed
 * queued listener or a duplicate event delivery must still never
 * produce a second business notification. One claimed row per
 * (notification kind, subject, recipient); the unique index is the
 * actual guarantee, not an in-memory check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatch_log', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->string('notification_class');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatch_log');
    }
};
