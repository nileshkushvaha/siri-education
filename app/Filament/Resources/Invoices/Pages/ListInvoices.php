<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(BookingPaymentResource::class, 'Back to Payments'),
        ]);
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::paymentCollection();
    }
}
