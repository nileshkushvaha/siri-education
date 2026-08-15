<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Support\MoneyFormatter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starter paid-lesson pricing for the supported Grade 6-12 markets.
 *
 * Prices are intentionally base rows (no instructor override). The two broad
 * academic levels cover every selectable Class/Grade/Year 6-12 level through
 * its mapped AcademicLevel. These are development starter amounts, not a
 * production pricing decision; admins can edit them from Student Lesson Prices.
 */
final class StudentLessonPriceSeeder extends Seeder
{
    /** @var array<string, array{currency:string, amount:string}> */
    private const MARKET_PRICES = [
        'IN' => ['currency' => 'INR', 'amount' => '499.00'],
        'US' => ['currency' => 'USD', 'amount' => '15.00'],
        'GB' => ['currency' => 'GBP', 'amount' => '12.00'],
        'AU' => ['currency' => 'AUD', 'amount' => '20.00'],
        'CA' => ['currency' => 'CAD', 'amount' => '18.00'],
        'AE' => ['currency' => 'AED', 'amount' => '55.00'],
        'SG' => ['currency' => 'SGD', 'amount' => '20.00'],
        'NZ' => ['currency' => 'NZD', 'amount' => '22.00'],
        'SA' => ['currency' => 'SAR', 'amount' => '60.00'],
    ];

    public function run(): void
    {
        $type = BookingType::query()
            ->where('key', 'paid_one_to_one')
            ->where('is_paid', true)
            ->active()
            ->first();
        $subjects = Subject::query()->active()->get();
        $levels = AcademicLevel::query()
            ->active()
            ->whereIn('slug', ['middle-school', 'high-school'])
            ->get();

        if ($type === null || $subjects->isEmpty() || $levels->count() !== 2) {
            $this->command?->warn('Skipping lesson prices: seed the paid booking type and complete Grade 6-12 catalogue first.');

            return;
        }

        DB::transaction(function () use ($type, $subjects, $levels): void {
            foreach (self::MARKET_PRICES as $iso2 => $market) {
                $country = Country::query()->active()->where('iso2', $iso2)->first();
                $currency = Currency::query()->active()->where('code', $market['currency'])->first();

                if ($country === null || $currency === null) {
                    $this->command?->warn("Skipping {$iso2}: active country or {$market['currency']} currency is missing.");

                    continue;
                }

                $country->update(['default_currency_id' => $currency->id]);
                $amountMinor = MoneyFormatter::toMinor($market['amount'], $currency->minor_units);

                foreach ($subjects as $subject) {
                    foreach ($levels as $level) {
                        StudentLessonPrice::withTrashed()->updateOrCreate(
                            [
                                'booking_type_id' => $type->id,
                                'instructor_id' => null,
                                'subject_id' => $subject->id,
                                'academic_level_id' => $level->id,
                                'country_id' => $country->id,
                                'duration_minutes' => $type->duration_minutes,
                            ],
                            [
                                'currency_id' => $currency->id,
                                'currency_code' => $currency->code,
                                'amount_minor' => $amountMinor,
                                'is_active' => true,
                                'effective_from' => null,
                                'effective_until' => null,
                                'priority' => 0,
                                'deleted_at' => null,
                            ],
                        );
                    }
                }
            }
        });

        $this->command?->info('✓ Grade 6-12 student lesson prices seeded for 9 supported countries.');
    }
}
