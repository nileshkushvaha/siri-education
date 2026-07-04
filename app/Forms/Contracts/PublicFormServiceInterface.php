<?php

declare(strict_types=1);

namespace App\Forms\Contracts;

use App\Forms\Enums\PublicFormType;
use App\Models\PublicFormSubmission;

interface PublicFormServiceInterface
{
    /**
     * Persist a public form submission, record it on the Activity Log
     * (which drives the existing admin in-app alert pipeline), and
     * email the configured recipient.
     *
     * @param  array{name: string, email?: ?string, phone?: ?string, subject?: ?string, message?: ?string, meta?: array<string, mixed>}  $data
     */
    public function submit(PublicFormType $type, array $data): PublicFormSubmission;
}
