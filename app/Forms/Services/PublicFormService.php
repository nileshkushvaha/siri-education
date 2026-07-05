<?php

declare(strict_types=1);

namespace App\Forms\Services;

use App\Forms\Contracts\PublicFormRepositoryInterface;
use App\Forms\Contracts\PublicFormServiceInterface;
use App\Forms\Enums\PublicFormType;
use App\Models\PublicFormSubmission;
use App\Notifications\Forms\PublicFormSubmissionNotification;
use App\Services\AuditTrailService;
use App\Services\Mail\TransactionalNotificationService;
use App\Settings\GeneralSettings;
use RuntimeException;

final class PublicFormService implements PublicFormServiceInterface
{
    public function __construct(
        private readonly PublicFormRepositoryInterface $repository,
        private readonly AuditTrailService $auditTrail,
        private readonly GeneralSettings $generalSettings,
        private readonly TransactionalNotificationService $notifications,
    ) {}

    public function submit(PublicFormType $type, array $data): PublicFormSubmission
    {
        $submission = $this->repository->create($type, $data);

        $this->auditTrail->logGuest(
            logName: 'forms',
            event: $type->logEvent(),
            description: $type->label().' submitted',
            subject: $submission,
            guestName: $data['name'] ?? '',
            guestEmail: $data['email'] ?? '',
            guestPhone: $data['phone'] ?? '',
            properties: ['type' => $type->value],
        );

        $this->notifyRecipient($type, $submission);

        return $submission;
    }

    private function notifyRecipient(PublicFormType $type, PublicFormSubmission $submission): void
    {
        $recipient = $this->generalSettings->support_email ?: config('mail.from.address');

        if (! is_string($recipient) || $recipient === '') {
            throw new RuntimeException('Support recipient email is not configured.');
        }

        $this->notifications->routeMail($recipient, new PublicFormSubmissionNotification($type, $submission));
    }
}
