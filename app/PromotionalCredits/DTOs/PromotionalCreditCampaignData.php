<?php

declare(strict_types=1);

namespace App\PromotionalCredits\DTOs;

use DateTimeImmutable;

/** Validated campaign configuration handed to PromotionalCreditService. */
final readonly class PromotionalCreditCampaignData
{
    public function __construct(
        public string $name,
        public ?string $description,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public int $amountMinor,
        public string $currencyCode,
        public int $perStudentLimit,
        public ?int $totalBudgetMinor,
        public ?string $terms,
    ) {}
}
