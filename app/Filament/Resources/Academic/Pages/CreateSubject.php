<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Academic\SubjectResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Resources\Pages\CreateRecord;

class CreateSubject extends CreateRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = SubjectResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Subjects'),
        ]);
    }
}
