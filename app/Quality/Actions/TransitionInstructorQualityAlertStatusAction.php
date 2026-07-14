<?php

declare(strict_types=1);

namespace App\Quality\Actions;

use App\Models\InstructorQualityAlert;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Exceptions\InvalidInstructorQualityAlertTransitionException;

/**
 * The one place an alert's status is written. Guards every change
 * through InstructorQualityAlertStatus::canTransitionTo() — mirrors
 * TransitionReviewStatusAction / TransitionReviewReportStatusAction.
 *
 * @throws InvalidInstructorQualityAlertTransitionException
 */
final class TransitionInstructorQualityAlertStatusAction
{
    /** @param array<string, mixed> $extra */
    public function execute(InstructorQualityAlert $alert, InstructorQualityAlertStatus $next, array $extra = []): InstructorQualityAlert
    {
        if (! $alert->status->canTransitionTo($next)) {
            throw InvalidInstructorQualityAlertTransitionException::between($alert->status, $next);
        }

        $alert->fill([...$extra, 'status' => $next, 'version' => $alert->version + 1])->save();

        return $alert;
    }
}
