<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('response_time_minutes')->nullable()->after('is_instructor_verified');
            $table->boolean('offers_demo')->default(false)->after('response_time_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn(['response_time_minutes', 'offers_demo']);
        });
    }
};
