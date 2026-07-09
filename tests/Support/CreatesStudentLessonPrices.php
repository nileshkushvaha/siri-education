<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AcademicCategory;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;

/**
 * Phase 10.2D-Cleanup-Fix: `BookingType::factory()->paid()` no longer
 * carries a price (see BookingTypeFactory — `booking_types.price`/
 * `currency` were removed in Phase 10.2D-Cleanup). Any test that books
 * a paid lesson through `BookingService`/`StudentBookingService` needs
 * an active `StudentLessonPrice` row matching the booking's subject,
 * academic level (or none — "all levels"), billing country, currency,
 * and duration — these helpers build that fixture without every test
 * file duplicating the same five-model setup.
 */
trait CreatesStudentLessonPrices
{
    protected function seedLessonSubject(string $slug = 'maths', string $name = 'Maths'): Subject
    {
        $existing = Subject::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return $existing;
        }

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);

        return Subject::create(['academic_category_id' => $category->id, 'name' => $name, 'slug' => $slug, 'status' => 'active']);
    }

    /** The core primitive — an active, currently-effective price for one exact match key. */
    protected function seedStudentLessonPrice(
        BookingType $type,
        Country $country,
        Currency $currency,
        float $amount,
        string $subjectSlug = 'maths',
        int $durationMinutes = 60,
        ?string $academicLevelId = null,
    ): StudentLessonPrice {
        return StudentLessonPrice::factory()->create([
            'booking_type_id' => $type->id,
            'subject_id' => $this->seedLessonSubject($subjectSlug)->id,
            'academic_level_id' => $academicLevelId,
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'currency_code' => $currency->code,
            'duration_minutes' => $durationMinutes,
            'amount_minor' => (int) round($amount * 100),
        ]);
    }

    /**
     * Convenience for the common case: one new paid booking type, one
     * billing country/currency, one matching "all levels" price —
     * covers every single-price `reserveStudent()`/`paidBooking()`
     * style helper (Razorpay/Stripe/PaymentWorkflow/PaymentTerminalState/
     * RazorpayCheckoutLivewire/StudentCheckoutFrontend test files).
     *
     * @return array{type: BookingType, country: Country, currency: Currency}
     */
    protected function createPaidBookingTypeWithPrice(
        string $key,
        float $amount,
        string $currencyCode,
        ?string $countryIso2 = null,
        int $durationMinutes = 60,
        string $subjectSlug = 'maths',
    ): array {
        $currency = Currency::query()->firstOrCreate(
            ['code' => $currencyCode],
            ['name' => $currencyCode, 'symbol' => $currencyCode, 'numeric_code' => '000', 'minor_units' => 2, 'status' => 'active'],
        );

        $country = Country::factory()->create(array_filter([
            'iso2' => $countryIso2,
            'default_currency_id' => $currency->id,
        ]));

        $type = BookingType::factory()->paid()->create([
            'key' => $key,
            'duration_minutes' => $durationMinutes,
            'max_attendees' => 1,
        ]);

        $this->seedStudentLessonPrice($type, $country, $currency, $amount, $subjectSlug, $durationMinutes);

        return ['type' => $type, 'country' => $country, 'currency' => $currency];
    }

    /** Points a student's billing profile at the country a price was seeded for. */
    protected function assignBillingCountry(User $student, Country $country): void
    {
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);
    }

    /** subject/grade pair shared by every StudentBookingData/CreateBookingData call that needs a resolvable price. */
    protected function lessonSubjectAndGrade(string $subjectSlug = 'maths', int $grade = 7): array
    {
        return ['subject' => $subjectSlug, 'grade' => $grade];
    }
}
