<?php

declare(strict_types=1);

namespace App\Forms\Contracts;

use App\Forms\Enums\PublicFormType;
use App\Models\PublicFormSubmission;

interface PublicFormRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(PublicFormType $type, array $data): PublicFormSubmission;
}
