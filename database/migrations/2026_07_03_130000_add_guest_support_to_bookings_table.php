<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('attendee_id')->nullable()->change();
            $table->string('guest_name')->nullable()->after('attendee_id');
            $table->string('guest_email', 150)->nullable()->after('guest_name')->index();
            $table->string('guest_phone', 30)->nullable()->after('guest_email');
            // Capability token: the only credential a guest has to view,
            // cancel, or reschedule their booking. Never exposed in listings.
            $table->char('manage_token', 64)->nullable()->unique()->after('guest_phone');
        });

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT chk_bookings_attendee_or_guest CHECK (attendee_id IS NOT NULL OR guest_email IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT chk_bookings_attendee_or_guest');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone', 'manage_token']);
        });

        // MySQL cannot MODIFY a column that participates in a foreign key —
        // drop it, change nullability, then restore it.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['attendee_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedBigInteger('attendee_id')->nullable(false)->change();
            $table->foreign('attendee_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
