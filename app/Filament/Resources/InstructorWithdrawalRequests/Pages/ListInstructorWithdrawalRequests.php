<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorWithdrawalRequests\Pages;

use App\Filament\Resources\InstructorWithdrawalRequests\InstructorWithdrawalRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListInstructorWithdrawalRequests extends ListRecords
{
    protected static string $resource = InstructorWithdrawalRequestResource::class;
}
