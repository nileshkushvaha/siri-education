<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralCodes\Pages;

use App\Filament\Resources\ReferralCodes\ReferralCodeResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\ReferralCode;
use App\Referral\Enums\ReferralCodeStatus;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListReferralCodes extends ListRecords
{
    protected static string $resource = ReferralCodeResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(ReferralCode::class, ReferralCodeStatus::class);
    }
}
