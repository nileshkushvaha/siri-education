<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningGoals\Pages;

use App\Filament\Resources\StudentLearningGoals\StudentLearningGoalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStudentLearningGoal extends CreateRecord
{
    protected static string $resource = StudentLearningGoalResource::class;
}
