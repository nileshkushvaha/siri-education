<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPaymentReconciliationIssues\Pages;

use App\Filament\Resources\BookingPaymentReconciliationIssues\BookingPaymentReconciliationIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListBookingPaymentReconciliationIssues extends ListRecords
{
    protected static string $resource = BookingPaymentReconciliationIssueResource::class;
}
