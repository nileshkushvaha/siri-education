<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalCreditIssuances\Pages;

use App\Filament\Resources\PromotionalCreditIssuances\PromotionalCreditIssuanceResource;
use Filament\Resources\Pages\ListRecords;

class ListPromotionalCreditIssuances extends ListRecords
{
    protected static string $resource = PromotionalCreditIssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
