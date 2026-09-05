<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off data fix to match User::email() (stored trimmed + lowercase).
 * Safe under the existing case-insensitive unique index: two rows that
 * differ only by case cannot already coexist, so LOWER() cannot collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereRaw('BINARY email <> BINARY LOWER(TRIM(email))')
            ->update(['email' => DB::raw('LOWER(TRIM(email))')]);
    }

    public function down(): void
    {
        // Irreversible by design: the original casing is not retained.
    }
};
