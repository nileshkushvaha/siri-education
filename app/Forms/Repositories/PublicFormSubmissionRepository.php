<?php

declare(strict_types=1);

namespace App\Forms\Repositories;

use App\Forms\Contracts\PublicFormRepositoryInterface;
use App\Forms\Enums\PublicFormType;
use App\Models\PublicFormSubmission;

final class PublicFormSubmissionRepository implements PublicFormRepositoryInterface
{
    public function create(PublicFormType $type, array $data): PublicFormSubmission
    {
        return PublicFormSubmission::create([
            'type' => $type,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'] ?? null,
            'message' => $data['message'] ?? null,
            'meta' => $data['meta'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
        ]);
    }
}
