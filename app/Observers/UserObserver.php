<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guarantees every user owns exactly one profile from the moment they're
 * created — the rest of the app can then assume $user->profile always
 * exists instead of null-checking it everywhere. Users that predate this
 * observer are covered by the backfill_missing_user_profiles migration.
 *
 * Also auto-generates a URL-safe slug from the user's name on creation and
 * when the name changes (as long as the slug still matches the auto-derived
 * form, allowing admins to override it manually).
 */
class UserObserver
{
    public function creating(User $user): void
    {
        if (empty($user->slug)) {
            $user->slug = $this->uniqueSlug($user->name, null);
        }
    }

    public function created(User $user): void
    {
        // created_by is set by UserProfile's own creating() hook.
        $user->profile()->create();
    }

    public function deleted(User $user): void
    {
        // The users.id FK cascade no longer fires on a soft delete, so the
        // profile has to follow the user explicitly.
        $user->profile()?->withoutTrashed()->delete();
    }

    public function restored(User $user): void
    {
        $user->profile()?->onlyTrashed()->restore();
    }

    public function updating(User $user): void
    {
        if (! $user->isDirty('name')) {
            return;
        }

        // Only regenerate if the current slug still matches what we'd derive
        // from the old name — meaning it was auto-generated, not manually set.
        $oldAutoSlug = $this->baseSlug($user->getOriginal('name'));
        $currentBase = $this->baseSlug($user->slug ?? '');

        if ($currentBase === $oldAutoSlug) {
            $user->slug = $this->uniqueSlug($user->name, $user->id);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function uniqueSlug(string $name, ?int $excludeId): string
    {
        $base = $this->baseSlug($name);
        $slug = $base;
        $i = 1;

        while (
            // Includes soft-deleted rows on purpose: a trashed user still
            // owns its slug, and restoring must not collide.
            DB::table('users')
                ->where('slug', $slug)
                ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $base.'_'.$i++;
        }

        return $slug;
    }

    private function baseSlug(string $value): string
    {
        return Str::slug($value);
    }
}
