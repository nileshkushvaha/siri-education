<?php

declare(strict_types=1);

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\CurriculumResource;
use App\Filament\Resources\Academic\CurriculumVersionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurricula extends ListRecords
{
    protected static string $resource = CurriculumResource::class;

    public function getSubheading(): ?string
    {
        return 'Choose a curriculum to manage its versions and structured content. Draft and historical versions stay inside the curriculum workflow.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('versions')->label('All Versions')->url(CurriculumVersionResource::getUrl())->visible(CurriculumVersionResource::canViewAny()),
            CreateAction::make(),
        ];
    }
}
