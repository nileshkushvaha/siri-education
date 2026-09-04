<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentReconciliationIssues\Pages;

use App\Filament\Concerns\HasRelatedResourceLinks;
use App\Filament\Resources\BookingPayments\BookingPaymentResource;
use App\Filament\Resources\PaymentReconciliationIssues\PaymentReconciliationIssueResource;
use App\Filament\Support\Presentation\BackAction;
use App\Filament\Support\RelatedResourceLinkGroups;
use App\Filament\Support\Tables\StatusTabs;
use App\Models\PaymentReconciliationIssue;
use App\Payments\Enums\PaymentReconciliationIssueStatus;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPaymentReconciliationIssues extends ListRecords
{
    use HasRelatedResourceLinks;

    protected static string $resource = PaymentReconciliationIssueResource::class;

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
        return StatusTabs::forEnum(PaymentReconciliationIssue::class, PaymentReconciliationIssueStatus::class);
    }
}
