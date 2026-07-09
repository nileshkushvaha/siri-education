<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices\Pages;

use App\Filament\Resources\StudentLessonPrices\StudentLessonPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentLessonPrices extends ListRecords
{
    protected static string $resource = StudentLessonPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
