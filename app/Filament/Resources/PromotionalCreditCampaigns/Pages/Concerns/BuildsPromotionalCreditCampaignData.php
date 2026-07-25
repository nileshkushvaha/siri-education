<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditCampaigns\Pages\Concerns;

use App\PromotionalCredits\DTOs\PromotionalCreditCampaignData;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * Translates the validated Filament form state into the service DTO —
 * the pages never write the model themselves, so
 * PromotionalCreditService stays the single writer and auditor of
 * campaign rules.
 */
trait BuildsPromotionalCreditCampaignData
{
    /** @param  array<string, mixed>  $data */
    private function campaignDataFrom(array $data): PromotionalCreditCampaignData
    {
        return new PromotionalCreditCampaignData(
            name: (string) $data['name'],
            description: filled($data['description'] ?? null) ? (string) $data['description'] : null,
            startsAt: DateTimeImmutable::createFromInterface(Carbon::parse($data['starts_at'], 'UTC')),
            endsAt: DateTimeImmutable::createFromInterface(Carbon::parse($data['ends_at'], 'UTC')),
            amountMinor: (int) $data['amount_minor'],
            currencyCode: (string) $data['currency_code'],
            perStudentLimit: (int) $data['per_student_limit'],
            totalBudgetMinor: filled($data['total_budget_minor'] ?? null) ? (int) $data['total_budget_minor'] : null,
            terms: filled($data['terms'] ?? null) ? (string) $data['terms'] : null,
        );
    }
}
