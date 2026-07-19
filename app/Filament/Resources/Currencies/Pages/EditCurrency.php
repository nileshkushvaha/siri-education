<?php

namespace App\Filament\Resources\Currencies\Pages;

use App\Filament\Resources\Currencies\CurrencyResource;
use App\Models\Currency;
use App\Services\Admin\CurrencyStatusService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCurrency extends EditRecord
{
    protected static string $resource = CurrencyResource::class;

    /**
     * Phase 24M — GAP-031 Step 9: routed through CurrencyStatusService
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
