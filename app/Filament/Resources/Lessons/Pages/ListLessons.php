<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Support\Tables\StatusTabs;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(Lesson::class, LessonStatus::class);
    }
}
