<?php

declare(strict_types=1);

namespace App\Quality\Contracts;

use App\Models\InstructorQualityAlert;
use App\Models\User;
use App\Quality\DTOs\InstructorQualityAlertAdminData;
use App\Quality\Enums\InstructorQualityAlertResolutionAction;

/**
 * Administrative alert review — this phase records recommendations
 * only. Nothing here ever suspends an instructor, hides a profile,
 * removes availability, changes compensation, or alters marketplace
 * ranking; those all remain separate, future, deliberate actions.
 */
interface InstructorQualityAlertServiceInterface
{
    /** Open → UnderReview. Idempotent if already UnderReview. */
    public function startReview(InstructorQualityAlert $alert, User $admin): InstructorQualityAlert;

    /** Requires a reason. `$action` is a recorded recommendation only. Idempotent if already Resolved. */
    public function resolve(InstructorQualityAlert $alert, User $admin, string $reason, InstructorQualityAlertResolutionAction $action = InstructorQualityAlertResolutionAction::NoAction): InstructorQualityAlert;

    /** Requires a reason. Idempotent if already Dismissed. */
    public function dismiss(InstructorQualityAlert $alert, User $admin, string $reason): InstructorQualityAlert;

    /** This alert duplicates another already-handled alert for the same instructor/signal. Idempotent if already Duplicate. */
    public function markDuplicate(InstructorQualityAlert $alert, User $admin, ?string $reason = null): InstructorQualityAlert;

    public function assign(InstructorQualityAlert $alert, User $admin, User $assignee): InstructorQualityAlert;

    /** Privacy-safe projection for a future admin UI. */
    public function adminProjection(InstructorQualityAlert $alert): InstructorQualityAlertAdminData;
}
