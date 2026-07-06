<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals\Pages;

use App\Filament\Resources\StudentLearningGoals\StudentLearningGoalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentLearningGoals extends ListRecords
{
    protected static string $resource = StudentLearningGoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
