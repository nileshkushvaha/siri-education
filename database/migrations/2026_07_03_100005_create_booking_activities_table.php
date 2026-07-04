<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Domain lifecycle timeline per booking. The unified activity_log
        // (AuditTrailService) remains the audit trail — this table powers
        // the booking history UI and reporting without polluting it.
        Schema::create('booking_activities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('booking_id');
            $table->string('action', 50);
            $table->string('actor_type', 50);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('status_from', 50)->nullable();
            $table->string('status_to', 50)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_activities');
    }
};
