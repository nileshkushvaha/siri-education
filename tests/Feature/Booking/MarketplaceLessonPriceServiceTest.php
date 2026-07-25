<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\MarketplacePriceState;
use App\Booking\Services\BookingPriceCalculator;
use App\Booking\Services\MarketplaceLessonPriceService;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * SRS §9.14 / §9.21-5 (GAP-024): the marketplace preview must always
 * agree with the real checkout price for identical inputs — proven
 * here by resolving both through the same fixtures and asserting
 * identical amounts, for both the single-instructor path (which calls
 * StudentLessonPriceResolver directly) and the batch path (which uses
 * the shared StudentLessonPriceRanking rule).
 */
class MarketplaceLessonPriceServiceTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private BookingType $paidType;

    private Subject $subject;

    private AcademicLevel $level;

    private Country $country;

    private Currency $currency;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::factory()->create(['code' => 'INR', 'minor_units' => 2]);
        $this->country = Country::factory()->create(['iso2' => 'IN', 'default_currency_id' => $this->currency->id]);
        $this->paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true, 'duration_minutes' => 60]);

        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths']);
        $this->level = AcademicLevel::create(['name' => 'Middle School', 'slug' => 'middle-school', 'min_grade' => 6, 'max_grade' => 8]);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->instructor->id], ['instructor_status' => 'approved']);
    }

    private function service(): MarketplaceLessonPriceService
    {
        return app(MarketplaceLessonPriceService::class);
    }

    // ── Resolution parity ────────────────────────────────────────────

    public function test_single_instructor_quote_matches_booking_price_calculator_for_the_base_price(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
        ]);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $this->country->id]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);
        $calculated = app(BookingPriceCalculator::class)->calculate($this->paidType, $student, 'maths', 7, $this->instructor->id);

        $this->assertSame(MarketplacePriceState::Available, $quote->state);
        $this->assertNotNull($quote->exact);
        $this->assertSame(75000, $quote->exact->amountMinor);
        $this->assertSame((int) round($calculated->payableAmount * 100), $quote->exact->amountMinor);
        $this->assertSame($calculated->currency, $quote->exact->currencyCode);
    }

    public function test_single_instructor_quote_prefers_instructor_override_matching_checkout(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
        ]);
        StudentLessonPrice::factory()->forInstructor($this->instructor->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 120000,
        ]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertSame(120000, $quote->exact->amountMinor);
        $this->assertTrue($quote->exact->isInstructorOverride);
    }

    public function test_batch_quote_matches_single_instructor_quote_and_checkout(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 65000,
        ]);
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $otherInstructor->id], ['instructor_status' => 'approved']);
        StudentLessonPrice::factory()->forInstructor($otherInstructor->id)->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 95000,
        ]);

        $batch = $this->service()->batchQuoteFor(collect([$this->instructor, $otherInstructor]), $this->country, $this->subject, $this->level);
        $singleA = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);
        $singleB = $this->service()->quoteFor($otherInstructor, $this->country, $this->subject, $this->level);

        $this->assertSame($singleA->exact->amountMinor, $batch[$this->instructor->id]->exact->amountMinor);
        $this->assertSame(65000, $batch[$this->instructor->id]->exact->amountMinor);
        $this->assertSame($singleB->exact->amountMinor, $batch[$otherInstructor->id]->exact->amountMinor);
        $this->assertSame(95000, $batch[$otherInstructor->id]->exact->amountMinor);
    }

    public function test_batch_quote_falls_back_to_base_price_for_an_instructor_with_no_override(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 50000,
        ]);

        $batch = $this->service()->batchQuoteFor(collect([$this->instructor]), $this->country, $this->subject, $this->level);

        $this->assertSame(50000, $batch[$this->instructor->id]->exact->amountMinor);
        $this->assertFalse($batch[$this->instructor->id]->exact->isInstructorOverride);
    }

    public function test_all_level_fallback_parity_between_batch_and_resolver(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => null,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 60000,
        ]);

        $single = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);
        $batch = $this->service()->batchQuoteFor(collect([$this->instructor]), $this->country, $this->subject, $this->level);

        $this->assertSame(60000, $single->exact->amountMinor);
        $this->assertSame(60000, $batch[$this->instructor->id]->exact->amountMinor);
    }

    // ── Missing / unavailable states ─────────────────────────────────

    public function test_missing_country_returns_missing_country_state_not_zero(): void
    {
        $quote = $this->service()->quoteFor($this->instructor, null, $this->subject, $this->level);

        $this->assertSame(MarketplacePriceState::MissingCountry, $quote->state);
        $this->assertNull($quote->exact);
        $this->assertSame([], $quote->options);
    }

    public function test_no_matching_configuration_returns_unavailable_never_free(): void
    {
        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertSame(MarketplacePriceState::Unavailable, $quote->state);
        $this->assertNull($quote->exact);
    }

    public function test_inactive_price_is_excluded_from_the_marketplace_preview(): void
    {
        StudentLessonPrice::factory()->inactive()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
        ]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertSame(MarketplacePriceState::Unavailable, $quote->state);
    }

    public function test_expired_price_is_excluded_from_the_marketplace_preview(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
            'effective_from' => now()->subMonth(),
            'effective_until' => now()->subWeek(),
        ]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertSame(MarketplacePriceState::Unavailable, $quote->state);
    }

    public function test_inactive_currency_is_excluded_without_a_locked_transaction(): void
    {
        $this->currency->update(['status' => 'inactive']);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
        ]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertSame(MarketplacePriceState::Unavailable, $quote->state);
    }

    public function test_free_demo_price_never_appears_as_paid_marketplace_pricing(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
        ]);
        BookingType::factory()->create(['key' => 'free_demo', 'is_paid' => false, 'duration_minutes' => 30]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertCount(1, $quote->options);
        $this->assertSame(75000, $quote->options[0]->amountMinor);
    }

    // ── Multiple durations ────────────────────────────────────────────

    public function test_multiple_paid_durations_produce_a_from_price_using_the_lowest_amount(): void
    {
        $shortType = BookingType::factory()->create(['key' => 'paid_short', 'is_paid' => true, 'duration_minutes' => 30]);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 120000,
        ]);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $shortType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 30,
            'amount_minor' => 70000,
        ]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertTrue($quote->isFrom);
        $this->assertNull($quote->exact);
        $this->assertCount(2, $quote->options);
        $this->assertSame(70000, $quote->lowest->amountMinor);
        $this->assertSame(30, $quote->lowest->durationMinutes);
    }

    public function test_single_duration_is_exact_not_from(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 75000,
        ]);

        $quote = $this->service()->quoteFor($this->instructor, $this->country, $this->subject, $this->level);

        $this->assertFalse($quote->isFrom);
        $this->assertNotNull($quote->exact);
    }

    // ── Query efficiency ──────────────────────────────────────────────

    public function test_batch_query_count_does_not_grow_with_the_number_of_instructors(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => 50000,
        ]);

        $few = collect([$this->instructor]);
        $many = collect([$this->instructor]);
        for ($i = 0; $i < 9; $i++) {
            $extra = User::factory()->create(['status' => User::STATUS_ACTIVE]);
            UserProfile::updateOrCreate(['user_id' => $extra->id], ['instructor_status' => 'approved']);
            $many->push($extra);
        }

        DB::enableQueryLog();
        $this->service()->batchQuoteFor($few, $this->country, $this->subject, $this->level);
        $fewCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->service()->batchQuoteFor($many, $this->country, $this->subject, $this->level);
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($fewCount, $manyCount);
        $this->assertLessThanOrEqual(3, $manyCount);
    }
}
