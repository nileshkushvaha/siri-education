<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals\Pages;

use App\Enums\LearningGoalStatus;
use App\Filament\Resources\StudentLearningGoals\StudentLearningGoalResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\StudentLearningGoal;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListStudentLearningGoals extends ListRecords
{
    protected static string $resource = StudentLearningGoalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(StudentLearningGoal::class, LearningGoalStatus::class);
    }
}
