<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TZ-1 (TZ-AUD-017): removes the hardcoded `'Asia/Kolkata'` DEFAULT
 * from `user_profiles.timezone`.
 *
 * A single country's timezone has no business being the schema-level
 * default of a platform that operates across 196 countries. Any row
 * inserted without an explicit timezone — a backfill, an import, an
 * admin-created account, a factory — silently claimed the user was in
 * India, and because the value looked explicit, UserTimezoneResolver's
 * Country tier would never be consulted for them.
 *
 * With no default, such a row is NULL, which is the honest
 * representation of "this person has not told us where they are", and
 * the resolver then does its job: Country default -> platform default
 * -> UTC.
 *
 * Deliberately non-destructive:
 *   - column type, length and nullability are unchanged;
 *   - NOT ONE existing row is read or rewritten. Every account that
 *     already stores 'Asia/Kolkata' keeps it, because we cannot know
 *     which of those were the user's real choice and which were the
 *     default landing on them. Guessing would relocate real people's
 *     clocks. If a cleanup is ever wanted it belongs in its own
 *     audited task with its own product decision, not here;
 *   - the normal write path is unaffected — RegisterUserAction has
 *     always set this column explicitly at signup.
 *
 * Expressed through the schema builder rather than raw
 * `ALTER COLUMN ... DROP DEFAULT` so the migration is portable to a
 * non-MySQL test/CI connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('timezone', 80)->nullable()->default(null)->change();
        });
    }

    /**
     * Restores the previous default so the migration is reversible.
     * Rolling back re-establishes the India-specific default for FUTURE
     * inserts only; like up(), it rewrites no existing row.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->string('timezone', 80)->nullable()->default('Asia/Kolkata')->change();
        });
    }
};
