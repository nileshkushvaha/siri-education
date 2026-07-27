<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration — kept permanently, never deleted. Guarantees every user
 * has exactly one profile row, including users created before the profile
 * system existed (the
 * UserObserver that auto-creates a profile on user creation only covers
 * users created after this point). Uses the query builder rather than the
 * Eloquent models so this migration stays correct even if those models
 * change shape later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $userIdsWithoutProfile = DB::table('users')
            ->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
            ->whereNull('user_profiles.id')
            ->pluck('users.id');

        if ($userIdsWithoutProfile->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $userIdsWithoutProfile->map(fn (int $userId): array => [
            'user_id' => $userId,
            'profile_visibility' => 'public',
            'show_email' => false,
            'show_phone' => false,
            'show_social_links' => true,
            'profile_completion' => 0,
            'timezone' => 'Asia/Kolkata',
            'language' => 'en',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('user_profiles')->insert($chunk);
        }
    }

    /**
     * Not reversible — deleting backfilled rows would also delete any real
     * data users have since added to those rows.
     */
    public function down(): void {}
};
