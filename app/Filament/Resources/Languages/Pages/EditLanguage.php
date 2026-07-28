<?php

namespace App\Filament\Resources\Languages\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Languages\LanguageResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\EditRecord;

class EditLanguage extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = LanguageResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Languages'),
        ]);
    }
}
