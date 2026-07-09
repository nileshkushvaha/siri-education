<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentLessonPrices\Pages;

use App\Filament\Resources\StudentLessonPrices\StudentLessonPriceResource;
use App\Models\Currency;
use Filament\Resources\Pages\CreateRecord;

class CreateStudentLessonPrice extends CreateRecord
{
    protected static string $resource = StudentLessonPriceResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $currency = Currency::query()->find($data['currency_id']);

        $data['currency_code'] = $currency?->code;
        $data['amount_minor'] = (int) round(((float) $data['amount']) * (10 ** ($currency?->minor_units ?? 2)));
        unset($data['amount']);

        return $data;
    }
}
