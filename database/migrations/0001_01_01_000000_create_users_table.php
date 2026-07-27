<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Merged: add_status_and_avatar_to_users_table
//         add_profile_fields_to_users_table
//         add_two_factor_to_users_table
//         widen_two_factor_columns_on_users_table
//         add_must_change_password_to_users_table (must_change_password)
//         add_lock_fields_to_users_table (locked_at, lock_reason)
//         drop_two_factor_columns_from_users_table (two_factor_* columns removed)
//         drop_avatar_from_users_table (avatar
//         moved to Spatie Media Library on UserProfile; identity table no
//         longer stores it)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->unsignedTinyInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();

            // Timestamp of when the lock was created (null = not locked or legacy lock)
            $table->timestamp('locked_at')->nullable();

            // Machine-readable reason: 'failed_attempts' | 'manual_admin' | 'manual_self'
            // Null = not locked or pre-migration legacy lock
            $table->string('lock_reason', 50)->nullable();

            $table->string('unlock_token', 64)->nullable();
            $table->timestamp('unlock_token_expires_at')->nullable();
            $table->boolean('login_alerts_enabled')->default(true);
            $table->boolean('new_device_alerts_enabled')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('last_login_user_agent')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->enum('status', ['pending_verification', 'active', 'inactive', 'blocked', 'suspended'])
                ->default('pending_verification');
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
