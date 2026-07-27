<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Merged: make_profile_preference_columns_nullable
//         (timezone/language → nullable with updated defaults)
// Merged: add_profile_details_columns
//         (headline, designation, short_bio, bio, website, social links,
//         visibility, profile_completion, created_by, updated_by, softDeletes)
//         replace_state_string_with_state_id (state master-table FK)
//         drop_avatar_and_dead_preference_columns
//         (avatar moved to Spatie Media Library; date_format/time_format/theme
//         were dead columns since the frontend Preferences tab was removed)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();

            // Public profile
            $table->string('headline')->nullable();
            $table->string('designation')->nullable();
            $table->string('short_bio', 160)->nullable();
            $table->text('bio')->nullable();

            // Personal info
            $table->string('phone', 20)->nullable();
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable();
            $table->date('date_of_birth')->nullable();

            // Location — integrates with master tables, no free-text country/state
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();

            // state_id's FK constraint is added by add_state_foreign_key_to_user_profiles_table
            // (dated after create_states_table) — the states table doesn't exist yet at this
            // point in migration history, so this column starts unconstrained.
            $table->unsignedBigInteger('state_id')->nullable();

            $table->string('postal_code', 20)->nullable();

            // Localisation preferences (nullable with sensible defaults)
            $table->string('timezone', 80)->nullable()->default('Asia/Kolkata');
            $table->string('language', 10)->nullable()->default('en');

            // Social links — kept inline on the profile for now; field names are
            // deliberately flat (not a nested array/table) so a future migration
            // to a dedicated social_links table only needs to move columns, not
            // redesign the data shape.
            $table->string('website')->nullable();
            $table->string('facebook')->nullable();
            $table->string('twitter')->nullable();
            $table->string('linkedin')->nullable();
            $table->string('github')->nullable();
            $table->string('instagram')->nullable();
            $table->string('youtube')->nullable();

            // Visibility
            $table->enum('profile_visibility', ['public', 'private', 'members_only'])->default('public');
            $table->boolean('show_email')->default(false);
            $table->boolean('show_phone')->default(false);
            $table->boolean('show_social_links')->default(true);

            // Completion — persisted, recalculated by ProfileCompletionService
            // whenever the profile changes (not computed on every read).
            $table->unsignedTinyInteger('profile_completion')->default(0);

            // Notification preferences
            $table->json('notification_preferences')->nullable();

            // Audit — who created/last touched this profile (self or an admin)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
