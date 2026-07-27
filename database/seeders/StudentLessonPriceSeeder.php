<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Minimal starter pricing so `paid_one_to_one` is actually bookable in
 * a fresh dev environment — not a production price list. Deliberately
 * one "all levels" row (academic_level_id null) rather than one row
 * per academic level, per the "do not invent a huge
 * price list" instruction. Skips silently if any referenced seed row
 * (subject/country/currency/booking type) is missing, since this
 * seeder must run after SubjectSeeder/CountrySeeder/CurrencySeeder/
 * BookingTypeSeeder but shouldn't hard-fail a partial seed run.
 */
class StudentLessonPriceSeeder extends Seeder
{
    public function run(): void
    {
        $type = BookingType::query()->where('key', 'paid_one_to_one')->first();
        $subject = Subject::query()->where('slug', 'algebra')->first();
        $country = Country::query()->where('iso2', 'IN')->first();
        $currency = Currency::query()->where('code', 'INR')->first();

        if ($type === null || $subject === null || $country === null || $currency === null) {
            return;
        }

        StudentLessonPrice::query()->firstOrCreate(
            [
                'booking_type_id' => $type->id,
                'subject_id' => $subject->id,
                'academic_level_id' => null,
                'country_id' => $country->id,
                'duration_minutes' => $type->duration_minutes,
            ],
            [
                'currency_id' => $currency->id,
                'currency_code' => $currency->code,
                'amount_minor' => 49900, // ₹499.00 — a placeholder starter price, not a pricing decision.
                'is_active' => true,
            ],
        );
    }
}
