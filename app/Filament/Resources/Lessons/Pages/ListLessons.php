<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use Filament\Resources\Pages\ListRecords;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;
}
