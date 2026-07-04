<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference', 20)->unique();
            $table->uuid('booking_type_id');
            $table->unsignedBigInteger('attendee_id');
            $table->unsignedBigInteger('host_id');

            $table->string('status', 50)->default('pending');
            $table->string('payment_status', 50)->default('not_required');
            $table->string('location_type', 50)->default('online');

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64)->default('UTC');

            // Payment snapshot at booking time; settlement stays in the Payment module.
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('payment_reference')->nullable();

            // Meeting provider linkage (Zoom, Meet, …) — provider key + external ref.
            $table->string('meeting_provider', 50)->nullable();
            $table->string('meeting_ref')->nullable();
            $table->string('meeting_url', 2048)->nullable();

            $table->string('cancelled_by', 50)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('booking_type_id')->references('id')->on('booking_types')->restrictOnDelete();
            $table->foreign('attendee_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('host_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['host_id', 'starts_at']);
            $table->index(['attendee_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
            $table->index('ends_at');
            $table->index('payment_status');
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT chk_bookings_time_range CHECK (starts_at < ends_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
