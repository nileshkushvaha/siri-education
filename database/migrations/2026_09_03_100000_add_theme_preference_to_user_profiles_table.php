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
            if (! Schema::hasColumn('user_profiles', 'theme_preference')) {
                // Nullable on purpose: null means "never chosen" and resolves to
                // the product default (light) in App\Services\ThemeResolver.
                $table->string('theme_preference', 16)->nullable()->after('notification_preferences');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('user_profiles', 'theme_preference')) {
                $table->dropColumn('theme_preference');
            }
        });
    }
};
