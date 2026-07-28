<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\StudentLearningGoals\StudentLearningGoalResource;
use App\Filament\Support\Presentation\BackAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStudentLearningGoal extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = StudentLearningGoalResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Learning Goals'),
            DeleteAction::make(),
        ]);
    }
}
