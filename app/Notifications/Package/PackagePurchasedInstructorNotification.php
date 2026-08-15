<?php

declare(strict_types=1);

namespace App\Notifications\Package;

use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Notifications\Package\Concerns\RoutesPackageChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * The instructor's counterpart to
 * PackagePurchasedStudentNotification: operational, not commercial.
 *
 * It tells the instructor what they need in order to teach — which
 * student, which proposal, and how many lessons that student may now
 * book — and deliberately carries NO amount, NO currency, NO payment
 * or provider reference, and NO receipt.
 *
 * As with the booking pair, this is not merely a privacy choice: what
 * the student paid is not what the instructor earns. Instructor
 * compensation is owned by the earnings/compensation architecture and
 * is untouched here, so printing the student's package price in this
 * email would read as earnings and be wrong. The constructor takes no
 * Payment at all, so no commercial value is even reachable from this
 * class.
 */
final class PackagePurchasedInstructorNotification extends PackageNotification
{
    use Queueable, RoutesPackageChannels, SerializesModels;

    public function __construct(
        public readonly StudentPackagePurchase $purchase,
        public readonly StudentPackageEntitlement $entitlement,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->recipientTimezone($notifiable);
        $proposal = $this->purchase->proposal;

        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Package activated — %s', $this->purchase->reference))
            ->greeting('A student has activated your package')
            ->line(sprintf(
                '%s has completed their purchase of your package proposal%s. They can now book lessons against it.',
                $this->purchase->student?->name ?? 'A student',
                $proposal?->subject?->name !== null ? ' for '.$proposal->subject->name : '',
            ))
            ->line(sprintf('Lessons they can book: %d (%d paid + %d bonus)',
                $this->entitlement->total_quantity,
                $this->entitlement->paid_quantity,
                $this->entitlement->bonus_quantity,
            ))
            ->line(sprintf('Package reference: %s', $this->purchase->reference));

        if ($this->entitlement->activated_at !== null) {
            $mail->line(sprintf(
                'Activated: %s',
                $this->entitlement->activated_at->timezone($timezone)->format('D, M j Y'),
            ));
        }

        if ($this->entitlement->expires_at !== null) {
            $mail->line(sprintf(
                'Valid until: %s',
                $this->entitlement->expires_at->timezone($timezone)->format('D, M j Y'),
            ));
        }

        return $mail;
    }

    protected function plainText(object $notifiable): string
    {
        return sprintf(
            '%s activated package %s — %d lessons available to book.',
            $this->purchase->student?->name ?? 'A student',
            $this->purchase->reference,
            $this->entitlement->total_quantity,
        );
    }
}
