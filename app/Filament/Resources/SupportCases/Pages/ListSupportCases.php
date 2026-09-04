<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Pages;

use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\SupportCase;
use App\SupportCases\Enums\SupportCaseStatus;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListSupportCases extends ListRecords
{
    protected static string $resource = SupportCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New Admin Case'),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(SupportCase::class, SupportCaseStatus::class);
    }
}
