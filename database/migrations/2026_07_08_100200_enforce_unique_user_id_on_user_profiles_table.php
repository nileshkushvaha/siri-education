<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * create_user_profiles_table.php declares user_id as ->unique(), but on
 * environments where that column definition was edited after the table
 * had already been migrated, the live schema never picked up the
 * constraint — leaving only the plain (non-unique) foreign-key index.
 * That silently let RegisterUserAction create a second profile per user
 * on top of the one UserObserver::created() already guarantees. This
 * dedupes any such rows (keeping the earliest one) and adds the missing
 * unique index so the 1:1 guarantee (User Lifecycle Foundation
 * requirement 1) is enforced at the database level, not just by
 * convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateUserIds = DB::table('user_profiles')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $idsToDelete = DB::table('user_profiles')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            DB::table('user_profiles')->whereIn('id', $idsToDelete)->delete();
        }

        Schema::table('user_profiles', function (Blueprint $table): void {
            $table->unique('user_id');
        });
    }

    /**
     * Intentionally not reverted. MySQL/InnoDB reassigns the user_id
     * foreign key to this unique index once it exists (the column's
     * original, non-unique FK index is no longer what enforces it), so
     * dropping the index here would require dropping and recreating the
     * foreign key too. More importantly: reverting this constraint would
     * just reintroduce the duplicate-profile bug it exists to fix.
     */
    public function down(): void {}
};
