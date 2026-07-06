<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans\Pages;

use App\Filament\Resources\StudentLearningPlans\StudentLearningPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentLearningPlans extends ListRecords
{
    protected static string $resource = StudentLearningPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
