<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentPackagePurchases\Pages;

use App\Filament\Resources\StudentPackagePurchases\StudentPackagePurchaseResource;
use Filament\Resources\Pages\ListRecords;

class ListStudentPackagePurchases extends ListRecords
{
    protected static string $resource = StudentPackagePurchaseResource::class;
}
