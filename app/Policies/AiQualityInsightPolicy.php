<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiQualityInsight;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Staff-only, and read/generate/review are three distinct rights.
 *
 * An instructor may never see an insight about themselves, and a
 * student may never see one at all — deliberately stricter than the
 * instructor's own Reviews & Quality dashboard, which shows
 * deterministic figures the instructor can verify. These are a model's
 * hedged, probabilistic observations awaiting human judgement; showing
 * them to their subject before an administrator has read them would
 * turn an internal prompt-for-attention into an unreviewed assessment
 * delivered to the person it is about. This matches how
 * InstructorQualityAlert visibility already works (manager-only).
 *
 * Nothing here can be created or edited by hand: insights are produced
 * by a run and read by a person. Generate is the only creation path,
 * and it is a separate permission from viewing because it spends money.
 *
 * `super_admin` bypasses via Gate::before() — never replicated here.
 */
class AiQualityInsightPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:AiQualityInsight');
    }

    public function view(User $user, AiQualityInsight $insight): bool
    {
        return $this->hasPermission($user, 'View:AiQualityInsight')
            || $this->hasPermission($user, 'ViewAny:AiQualityInsight');
    }

    /** Costs money and analyses a real person's work — its own permission, never implied by viewing. */
    public function generate(User $user): bool
    {
        return $this->hasPermission($user, 'Generate:AiQualityInsight');
    }

    /** Taking responsibility for having read it is a distinct act from being able to read it. */
    public function review(User $user, AiQualityInsight $insight): bool
    {
        return $this->hasPermission($user, 'Review:AiQualityInsight');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiQualityInsight $insight): bool
    {
        return false;
    }

    public function delete(User $user, AiQualityInsight $insight): bool
    {
        return false;
    }

    public function forceDelete(User $user, AiQualityInsight $insight): bool
    {
        return false;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
