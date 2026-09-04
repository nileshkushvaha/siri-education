<?php

declare(strict_types=1);

namespace App\Filament\Resources\Navigation\Pages;

use App\Enums\Navigation\NavigationStatus;
use App\Filament\Resources\Navigation\NavigationResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\NavigationMenu;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListNavigations extends ListRecords
{
    protected static string $resource = NavigationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(NavigationMenu::class, NavigationStatus::class);
    }
}
