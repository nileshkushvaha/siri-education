<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPayments\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use App\Filament\Support\RelatedResourceLinkGroups;
use Filament\Resources\Pages\ListRecords;

class ListBookingPayments extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = BookingPaymentResource::class;

    public function getSubheading(): ?string
    {
        return 'Review student lesson payments and open related invoices or reconciliation queues when needed.';
    }

    protected function getRelatedResourceLinks(): array
    {
        return RelatedResourceLinkGroups::paymentCollection();
    }
}
