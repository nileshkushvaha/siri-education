<?php

declare(strict_types=1);

namespace App\SupportCases\DTOs;

use App\Models\User;
use App\SupportCases\Enums\SupportCaseCategory;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseType;

final class CreateSupportCaseData
{
    public function __construct(
        public readonly User $creator,
        public readonly SupportCaseType $type,
        public readonly SupportCaseCategory $category,
        public readonly SupportCasePriority $priority,
        public readonly string $subject,
        public readonly string $description,
        public readonly ?User $student = null,
        public readonly ?User $instructor = null,
        public readonly ?string $linkedRecordType = null,
        public readonly ?string $linkedRecordId = null,
        public readonly bool $skipLinkedRecordOwnershipCheck = false,
    ) {}
}
