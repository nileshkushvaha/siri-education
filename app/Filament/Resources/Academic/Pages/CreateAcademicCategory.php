<?php

namespace App\Filament\Resources\Academic\Pages;

use App\Filament\Resources\Academic\AcademicCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAcademicCategory extends CreateRecord
{
    protected static string $resource = AcademicCategoryResource::class;
}
