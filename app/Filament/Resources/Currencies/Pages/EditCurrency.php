<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\Currency;
use App\Services\Admin\CurrencyStatusService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCurrency extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Currencies'),
        ]);
    }

    /**
     * Routed through CurrencyStatusService
     * so a status change is serialized (via a row lock) against any
     * concurrent BookingPaymentService::initiate() call for this same
     * currency, rather than writing directly and unlocked.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Currency $record */
        return app(CurrencyStatusService::class)->update($record, $data);
    }
}
