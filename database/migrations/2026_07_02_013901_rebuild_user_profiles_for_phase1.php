<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catch-up migration for databases that already ran an earlier base
 * user_profiles migration. Fresh installs never see this —
 * create_user_profiles_table.php already has the final schema.
 * Every step is guarded so this is safe to run regardless of which columns
 * already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_profiles', 'headline')) {
                $table->string('headline')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('user_profiles', 'designation')) {
                $table->string('designation')->nullable()->after('headline');
            }
            if (! Schema::hasColumn('user_profiles', 'short_bio')) {
                $table->string('short_bio', 160)->nullable()->after('designation');
            }
            if (! Schema::hasColumn('user_profiles', 'bio')) {
                $table->text('bio')->nullable()->after('short_bio');
            }
            if (! Schema::hasColumn('user_profiles', 'state_id')) {
                $table->unsignedBigInteger('state_id')->nullable()->after('country_id');
            }
            if (! Schema::hasColumn('user_profiles', 'website')) {
                $table->string('website')->nullable();
            }
            if (! Schema::hasColumn('user_profiles', 'facebook')) {
                $table->string('facebook')->nullable();
            }
            if (! Schema::hasColumn('user_profiles', 'twitter')) {
                $table->string('twitter')->nullable();
            }
            if (! Schema::hasColumn('user_profiles', 'linkedin')) {
                $table->string('linkedin')->nullable();
            }
            if (! Schema::hasColumn('user_profiles', 'github')) {
                $table->string('github')->nullable();
            }
            if (! Schema::hasColumn('user_profiles', 'instagram')) {
                $table->string('instagram')->nullable();
            }
            if (! Schema::hasColumn('user_profiles', 'youtube')) {
                $table->string('youtube')->nullable();
            }
            if (! Schema::hasColumn('user_profiles', 'profile_visibility')) {
                $table->enum('profile_visibility', ['public', 'private', 'members_only'])->default('public');
            }
            if (! Schema::hasColumn('user_profiles', 'show_email')) {
                $table->boolean('show_email')->default(false);
            }
            if (! Schema::hasColumn('user_profiles', 'show_phone')) {
                $table->boolean('show_phone')->default(false);
            }
            if (! Schema::hasColumn('user_profiles', 'show_social_links')) {
                $table->boolean('show_social_links')->default(true);
            }
            if (! Schema::hasColumn('user_profiles', 'profile_completion')) {
                $table->unsignedTinyInteger('profile_completion')->default(0);
            }
            if (! Schema::hasColumn('user_profiles', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_profiles', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('user_profiles', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('user_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('user_profiles', 'avatar')) {
                $table->dropColumn('avatar');
            }
            if (Schema::hasColumn('user_profiles', 'date_format')) {
                $table->dropColumn('date_format');
            }
            if (Schema::hasColumn('user_profiles', 'time_format')) {
                $table->dropColumn('time_format');
            }
            if (Schema::hasColumn('user_profiles', 'theme')) {
                $table->dropColumn('theme');
            }
            if (Schema::hasColumn('user_profiles', 'state')) {
                $table->dropColumn('state');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
        });

        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('avatar')->nullable();
            $table->string('date_format', 20)->nullable()->default('Y-m-d');
            $table->string('time_format', 5)->nullable()->default('H:i');
            $table->enum('theme', ['light', 'dark', 'system'])->nullable()->default('dark');
            $table->string('state', 100)->nullable();

            $table->dropColumn([
                'headline', 'designation', 'short_bio', 'bio', 'state_id',
                'website', 'facebook', 'twitter', 'linkedin', 'github', 'instagram', 'youtube',
                'profile_visibility', 'show_email', 'show_phone', 'show_social_links',
                'profile_completion', 'created_by', 'updated_by',
            ]);
            $table->dropSoftDeletes();
        });
    }
};
