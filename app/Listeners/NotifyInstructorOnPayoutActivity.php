<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Events\ActivityCreated;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Notifications\Instructor\InstructorPayoutMethodStatusNotification;
use App\Notifications\Instructor\InstructorWithdrawalStatusNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Notification bridge for the payout domain (log name
 * `instructor_payouts`): audit entries written by the payout/withdrawal
 * services fan out to instructor notifications here, keeping services
 * free of direct notification calls per the Activity Log pipeline
 * convention. Runs after commit by construction — the audit entry only
 * exists once the financial transaction committed. Payloads carry safe
 * fields only.
 */
final class NotifyInstructorOnPayoutActivity implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handle(ActivityCreated $event): void
    {
        $activity = $event->activity;

        if ($activity->log_name !== 'instructor_payouts') {
            return;
        }

        $subject = $activity->subject;

        if ($subject instanceof InstructorPayoutMethod) {
            $this->notifyForPayoutMethod($activity->event, $subject);

            return;
        }

        if ($subject instanceof InstructorWithdrawalRequest) {
            $this->notifyForWithdrawal($activity->event, $subject);
        }
    }

    private function notifyForPayoutMethod(?string $event, InstructorPayoutMethod $method): void
    {
        $status = match ($event) {
            'payout_method_verified' => PayoutMethodStatus::Verified,
            'payout_method_rejected' => PayoutMethodStatus::Rejected,
            'payout_method_disabled' => PayoutMethodStatus::Disabled,
            default => null,
        };

        if ($status === null || $method->instructor === null) {
            return;
        }

        $method->instructor->notify(new InstructorPayoutMethodStatusNotification(
            $status,
            $method->display_label,
            $status === PayoutMethodStatus::Rejected ? $method->rejection_reason : null,
        ));
    }

    private function notifyForWithdrawal(?string $event, InstructorWithdrawalRequest $withdrawal): void
    {
        $status = match ($event) {
            'withdrawal_requested' => InstructorWithdrawalStatus::Submitted,
            'withdrawal_review_started' => InstructorWithdrawalStatus::UnderReview,
            'withdrawal_approved' => InstructorWithdrawalStatus::Approved,
            'withdrawal_rejected' => InstructorWithdrawalStatus::Rejected,
            'withdrawal_cancelled' => InstructorWithdrawalStatus::Cancelled,
            default => null,
        };

        if ($status === null || $withdrawal->instructor === null) {
            return;
        }

        $withdrawal->instructor->notify(new InstructorWithdrawalStatusNotification(
            $status,
            $withdrawal->reference,
            $withdrawal->amount_minor,
            $withdrawal->currency_code,
            $withdrawal->payout_method_label,
            $status === InstructorWithdrawalStatus::Rejected ? $withdrawal->rejection_reason : null,
        ));
    }
}
