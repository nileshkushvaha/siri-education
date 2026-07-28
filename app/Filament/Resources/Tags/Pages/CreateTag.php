<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Tags'),
        ]);
    }
}
