<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentReconciliationIssue;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Phase 4E.2 — authorization for the generic payment discrepancy queue.
 * Follows BookingPaymentReconciliationIssuePolicy's shape: finance/ops
 * only, never a student or instructor.
 *
 * Deliberately NARROWER than that precedent. It grants view and
 * resolve, and nothing else:
 *  - no `assign` — there is no triage workflow here, and inventing an
 *    assignee field nobody uses would be speculative;
 *  - no `reconcileNow` — the booking domain's equivalent re-polls the
 *    provider, but the generic path already sweeps every open attempt
 *    every five minutes, so a manual trigger would add an action with
 *    no capability behind it;
 *  - no create/update/delete, at all.
 *
 * `resolve` closes the OPERATIONAL record only. It cannot mark a
 * payment or a purchase paid, and no permission in this codebase grants
 * that — settlement is reachable exclusively through verified provider
 * evidence, which is the whole reason this queue exists rather than a
 * "fix it" button.
 */
class PaymentReconciliationIssuePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ViewAny:PaymentReconciliationIssue');
    }

    public function view(User $user, PaymentReconciliationIssue $issue): bool
    {
        return $this->hasPermission($user, 'View:PaymentReconciliationIssue');
    }

    public function resolve(User $user, PaymentReconciliationIssue $issue): bool
    {
        return $issue->isOpen() && $this->hasPermission($user, 'Resolve:PaymentReconciliationIssue');
    }

    public function create(User $user): bool
    {
        // Only PaymentReconciliationIssueService writes these, from the
        // one settlement validator. A human-created discrepancy would be
        // a fabricated financial record.
        return false;
    }

    public function update(User $user, PaymentReconciliationIssue $issue): bool
    {
        return false;
    }

    public function delete(User $user, PaymentReconciliationIssue $issue): bool
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
