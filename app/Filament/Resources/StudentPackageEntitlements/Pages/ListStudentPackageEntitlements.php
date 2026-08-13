<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentPackageEntitlements\Pages;

use App\Filament\Resources\StudentPackageEntitlements\StudentPackageEntitlementResource;
use Filament\Resources\Pages\ListRecords;

class ListStudentPackageEntitlements extends ListRecords
{
    protected static string $resource = StudentPackageEntitlementResource::class;
}
