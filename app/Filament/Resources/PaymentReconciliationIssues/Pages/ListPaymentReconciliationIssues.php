<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentReconciliationIssues\Pages;

use App\Filament\Resources\PaymentReconciliationIssues\PaymentReconciliationIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentReconciliationIssues extends ListRecords
{
    protected static string $resource = PaymentReconciliationIssueResource::class;
}
