<?php

declare(strict_types=1);

namespace App\Filament\Resources\Navigation\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Navigation\NavigationResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreateNavigation extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = NavigationResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Navigation Menus'),
        ]);
    }
}
