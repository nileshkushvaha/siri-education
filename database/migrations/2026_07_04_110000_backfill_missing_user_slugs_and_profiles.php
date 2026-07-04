<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data migration — kept permanently, never deleted (same rationale as
 * backfill_missing_user_profiles). UserObserver auto-generates a slug and
 * auto-creates a profile on user creation, but any seeder using
 * WithoutModelEvents (e.g. DefaultRolesAndUsersSeeder) bypasses observers
 * entirely — so seeded users can slip through with a NULL slug and no
 * profile. NULL slug breaks route-model binding wherever a User is the
 * route parameter (User::getRouteKeyName() === 'slug'), including
 * Filament's own Users resource (view/edit) and any widget linking to it.
 *
 * Uses the query builder, not Eloquent, so this stays correct even if
 * these models change shape later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->backfillSlugs();
        $this->backfillProfiles();
    }

    private function backfillSlugs(): void
    {
        $users = DB::table('users')
            ->where(fn ($q) => $q->whereNull('slug')->orWhere('slug', ''))
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($users as $user) {
            $base = Str::slug($user->name) ?: 'user';
            $slug = $base;
            $i = 1;

            while (DB::table('users')->where('slug', $slug)->where('id', '!=', $user->id)->exists()) {
                $slug = $base.'_'.$i++;
            }

            DB::table('users')->where('id', $user->id)->update(['slug' => $slug]);
        }
    }

    private function backfillProfiles(): void
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
     * Not reversible — deleting backfilled data would destroy anything
     * real users have since added to those rows.
     */
    public function down(): void {}
};
