<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google account activation: the Google "sub" (stable account id) is the
 * permanent link between a Google account and a local user. Stored on
 * `users` by decision — no separate identity table (see
 * docs/architecture/domain-registry.md, Auth and Security).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_subject', 64)->nullable()->unique()->after('must_change_password');
            $table->string('google_email')->nullable()->after('google_subject');
            $table->timestamp('google_linked_at')->nullable()->after('google_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_subject']);
            $table->dropColumn(['google_subject', 'google_email', 'google_linked_at']);
        });
    }
};
