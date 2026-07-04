<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Analytics group on created_at (trend/KPIs) and starts_at
            // (time-slot popularity, utilization) without a status prefix.
            $table->index('created_at');
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['starts_at']);
        });
    }
};
