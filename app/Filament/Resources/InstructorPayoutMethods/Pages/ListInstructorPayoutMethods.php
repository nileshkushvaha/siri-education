<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutMethods\Pages;

use App\Filament\Resources\InstructorPayoutMethods\InstructorPayoutMethodResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorPayoutMethods extends ListRecords
{
    protected static string $resource = InstructorPayoutMethodResource::class;
}
