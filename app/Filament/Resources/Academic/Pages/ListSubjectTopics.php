<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\SubjectTopicResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubjectTopics extends ListRecords
{
    protected static string $resource = SubjectTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
