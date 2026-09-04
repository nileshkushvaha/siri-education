<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingPaymentReconciliationIssues\Pages;

use App\Booking\Enums\BookingPaymentReconciliationIssueStatus;
use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\BookingPaymentReconciliationIssues\BookingPaymentReconciliationIssueResource;
use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\BookingPaymentReconciliationIssue;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListBookingPaymentReconciliationIssues extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = BookingPaymentReconciliationIssueResource::class;

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

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return StatusTabs::forEnum(BookingPaymentReconciliationIssue::class, BookingPaymentReconciliationIssueStatus::class);
    }
}
