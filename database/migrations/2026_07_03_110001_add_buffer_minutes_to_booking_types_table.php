<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            // Gap enforced before and after each booking of this type.
            $table->unsignedSmallInteger('buffer_minutes')->default(0)->after('max_attendees');
        });
    }

    public function down(): void
    {
        Schema::table('booking_types', function (Blueprint $table): void {
            $table->dropColumn('buffer_minutes');
        });
    }
};
