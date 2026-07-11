<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorPayoutAttempts\Pages;

use App\Filament\Resources\InstructorPayoutAttempts\InstructorPayoutAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorPayoutAttempts extends ListRecords
{
    protected static string $resource = InstructorPayoutAttemptResource::class;
}
