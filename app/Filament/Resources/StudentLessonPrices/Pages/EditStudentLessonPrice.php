<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices\Pages;

use App\Filament\Resources\StudentLessonPrices\StudentLessonPriceResource;
use App\Models\Currency;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStudentLessonPrice extends EditRecord
{
    protected static string $resource = StudentLessonPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $currency = Currency::query()->find($data['currency_id'] ?? null);

        $data['amount'] = ($data['amount_minor'] ?? 0) / (10 ** ($currency?->minor_units ?? 2));

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currency = Currency::query()->find($data['currency_id']);

        $data['currency_code'] = $currency?->code;
        $data['amount_minor'] = (int) round(((float) $data['amount']) * (10 ** ($currency?->minor_units ?? 2)));
        unset($data['amount']);

        return $data;
    }
}
