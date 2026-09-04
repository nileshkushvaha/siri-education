<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Enums\PageStatus;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\Page;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(Page::class, PageStatus::class);
    }
}
