<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans\Pages;

use App\Filament\Resources\StudentLearningPlans\StudentLearningPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStudentLearningPlan extends CreateRecord
{
    protected static string $resource = StudentLearningPlanResource::class;
}
