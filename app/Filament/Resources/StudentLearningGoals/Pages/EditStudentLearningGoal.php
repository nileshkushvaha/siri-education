<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals\Pages;

use App\Filament\Resources\StudentLearningGoals\StudentLearningGoalResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStudentLearningGoal extends EditRecord
{
    protected static string $resource = StudentLearningGoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
