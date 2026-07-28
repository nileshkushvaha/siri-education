<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Pages'),
        ]);
    }
}
