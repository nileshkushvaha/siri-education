<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23G cleanup — dev mode, no legacy data to preserve.
 *
 * `user_profiles.designation` audited (grep across app/ + resources/views)
 * and confirmed write-only: captured by the admin form and the generic
 * /profile self-edit form, but never read by any public view, card,
 * search result, or SEO output. `headline` is the sole actively-displayed
 * marketplace title field. Not to be confused with the unrelated, actively
 * used `user_experiences.designation` (a work-history job title) — this
 * migration only touches user_profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropColumn('designation');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('designation')->nullable()->after('headline');
        });
    }
};
