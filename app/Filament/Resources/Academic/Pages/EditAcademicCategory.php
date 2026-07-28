<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\AcademicCategoryResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditAcademicCategory extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = AcademicCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Academic Categories'),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ]);
    }
}
