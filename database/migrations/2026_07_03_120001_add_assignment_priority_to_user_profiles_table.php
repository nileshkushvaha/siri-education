<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            // Admin-set boost (0–100) used by the teacher assignment engine.
            $table->unsignedTinyInteger('assignment_priority')->default(50)->after('instructor_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn('assignment_priority');
        });
    }
};
