<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\InstructorSubjectTopicResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstructorSubjectTopics extends ListRecords
{
    protected static string $resource = InstructorSubjectTopicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
