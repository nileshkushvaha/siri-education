<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLearningPlans\Pages;

use App\Enums\LearningPlanStatus;
use App\Filament\Resources\StudentLearningPlans\StudentLearningPlanResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\StudentLearningPlan;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListStudentLearningPlans extends ListRecords
{
    protected static string $resource = StudentLearningPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(StudentLearningPlan::class, LearningPlanStatus::class);
    }
}
