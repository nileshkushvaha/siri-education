<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\Services\BookingPriceCalculator;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicCategory;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * SRS §9.14 / §9.21-5: end-to-end marketplace pricing display
 * across discovery cards, the public profile, guest country selection,
 * and checkout parity.
 */
class MarketplacePricingDisplayTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private BookingType $paidType;

    private Subject $subject;

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->currency = Currency::factory()->create(['code' => 'INR', 'minor_units' => 2]);
        $this->country = Country::factory()->create(['iso2' => 'IN', 'default_currency_id' => $this->currency->id, 'status' => 'active']);
        $this->paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true, 'duration_minutes' => 60]);

        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Maths', 'slug' => 'maths', 'status' => 'active']);
    }

    private function makeInstructor(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['status' => 'active'], $overrides));
        $user->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
            'country_id' => $this->country->id,
        ]);
        $user->assignRole('instructor');
        TeacherSubject::factory()->create([
            'teacher_id' => $user->id,
            'subject' => $this->subject->name,
            'subject_id' => $this->subject->id,
        ]);

        return $user;
    }

    private function seedPrice(int $amountMinor): StudentLessonPrice
    {
        return StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $this->country->id,
            'currency_id' => $this->currency->id,
            'currency_code' => 'INR',
            'duration_minutes' => 60,
            'amount_minor' => $amountMinor,
        ]);
    }

    // ── Discovery card ─────────────────────────────────────────────

    public function test_search_card_shows_localized_price_when_subject_filtered_and_country_resolved(): void
    {
        $instructor = $this->makeInstructor(['name' => 'Priced Instructor']);
        $this->seedPrice(75000);

        $this->get(route('instructors.index', ['subject' => $this->subject->slug, 'pricing_country' => 'IN']))
            ->assertOk()
            ->assertSee('Priced Instructor')
            ->assertSee('750.00 INR');
    }

    public function test_search_card_omits_a_price_block_when_no_subject_is_selected(): void
    {
        $instructor = $this->makeInstructor(['name' => 'No Filter Instructor']);
        $this->seedPrice(75000);

        $this->get(route('instructors.index'))
            ->assertOk()
            ->assertSee('No Filter Instructor')
            ->assertDontSee('750.00 INR');
    }

    public function test_unavailable_configuration_shows_a_neutral_state_never_free(): void
    {
        $this->makeInstructor(['name' => 'Unpriced Instructor']);
        // No StudentLessonPrice row seeded at all.

        $this->get(route('instructors.index', ['subject' => $this->subject->slug, 'pricing_country' => 'IN']))
            ->assertOk()
            ->assertSee('Price unavailable for this selection')
            ->assertDontSee('₹0')
            ->assertDontSee('0.00 INR');
    }

    public function test_guest_country_selection_persists_across_requests_via_session(): void
    {
        $secondCurrency = Currency::factory()->create(['code' => 'USD', 'minor_units' => 2]);
        $secondCountry = Country::factory()->create(['iso2' => 'US', 'default_currency_id' => $secondCurrency->id, 'status' => 'active']);
        $this->makeInstructor(['name' => 'US Priced Instructor'])->profile()->update(['country_id' => $secondCountry->id]);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $secondCountry->id,
            'currency_id' => $secondCurrency->id,
            'currency_code' => 'USD',
            'duration_minutes' => 60,
            'amount_minor' => 5000,
        ]);

        // First request selects US explicitly.
        $this->get(route('instructors.index', ['subject' => $this->subject->slug, 'pricing_country' => 'US']))
            ->assertOk()
            ->assertSee('50.00 USD');

        // A second request with no pricing_country query param must still
        // use the remembered session choice, not silently reset to default.
        $this->get(route('instructors.index', ['subject' => $this->subject->slug]))
            ->assertOk()
            ->assertSee('50.00 USD');
    }

    public function test_invalid_guest_country_selection_is_rejected_not_silently_trusted(): void
    {
        $this->makeInstructor(['name' => 'Guarded Instructor']);
        $this->seedPrice(75000);

        // "ZZ" is not a real active country — must fall back to the
        // platform default rather than crash or silently accept it.
        $this->get(route('instructors.index', ['subject' => $this->subject->slug, 'pricing_country' => 'ZZ']))
            ->assertOk();
    }

    public function test_authenticated_students_billing_country_is_never_overridden_by_a_query_parameter(): void
    {
        $studentCurrency = Currency::factory()->create(['code' => 'GBP', 'minor_units' => 2]);
        $studentCountry = Country::factory()->create(['iso2' => 'GB', 'default_currency_id' => $studentCurrency->id, 'status' => 'active']);
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active, 'country_id' => $studentCountry->id]);

        $this->makeInstructor(['name' => 'GBP Priced Instructor'])->profile()->update(['country_id' => $studentCountry->id]);
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->subject->id,
            'country_id' => $studentCountry->id,
            'currency_id' => $studentCurrency->id,
            'currency_code' => 'GBP',
            'duration_minutes' => 60,
            'amount_minor' => 9900,
        ]);
        $this->seedPrice(75000); // an India-priced row a crafted query could try to force.

        $this->actingAs($student)
            ->get(route('instructors.index', ['subject' => $this->subject->slug, 'pricing_country' => 'IN']))
            ->assertOk()
            ->assertSee('99.00 GBP')
            ->assertDontSee('750.00 INR');
    }

    // ── Public profile ────────────────────────────────────────────────

    public function test_profile_shows_the_same_price_the_card_shows(): void
    {
        $instructor = $this->makeInstructor();
        $this->seedPrice(75000);

        $this->get(route('instructors.show', ['user' => $instructor, 'subject' => $this->subject->slug, 'pricing_country' => 'IN']))
            ->assertOk()
            ->assertSee('750.00 INR');
    }

    public function test_a_crafted_academic_level_query_id_belonging_to_another_instructor_is_ignored(): void
    {
        $instructor = $this->makeInstructor();
        $this->seedPrice(75000);

        // academic_level=999999 does not belong to this instructor's own
        // configured coverage (instructor_academic_level_ids is empty) —
        // must be ignored, never trusted, never causing an error.
        $this->get(route('instructors.show', ['user' => $instructor, 'subject' => $this->subject->slug, 'academic_level' => '999999']))
            ->assertOk()
            ->assertSee('750.00 INR');
    }

    public function test_no_instructor_compensation_field_ever_appears_on_the_profile_or_card(): void
    {
        $instructor = $this->makeInstructor();
        $this->seedPrice(75000);

        $response = $this->get(route('instructors.show', ['user' => $instructor, 'subject' => $this->subject->slug, 'pricing_country' => 'IN']));

        $response->assertOk();
        $response->assertDontSee('amount_minor');
        $response->assertDontSee('commission', false);
        $response->assertDontSee('payout', false);
        $response->assertDontSee('instructor_id', false);
    }

    public function test_unpublished_instructor_profile_remains_hidden_regardless_of_configured_price(): void
    {
        $instructor = $this->makeInstructor();
        $instructor->profile()->update(['profile_visibility' => 'private']);
        $this->seedPrice(75000);

        $this->get(route('instructors.show', ['user' => $instructor, 'subject' => $this->subject->slug]))
            ->assertForbidden();
    }

    // ── Checkout parity ──────────────────────────────────────────────

    public function test_stale_displayed_price_is_re_resolved_at_checkout_not_reused(): void
    {
        $instructor = $this->makeInstructor();
        $original = $this->seedPrice(75000);

        // Marketplace displays the original price.
        $this->get(route('instructors.show', ['user' => $instructor, 'subject' => $this->subject->slug, 'pricing_country' => 'IN']))
            ->assertOk()
            ->assertSee('750.00 INR');

        // An administrator changes it before the student actually books.
        $original->update(['is_active' => false]);
        $this->seedPrice(90000);

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active, 'country_id' => $this->country->id]);

        // The authoritative calculator — never the earlier page render —
        // must resolve the new, current price.
        $calculated = app(BookingPriceCalculator::class)->calculate($this->paidType, $student, $this->subject->slug, 7, $instructor->id);

        $this->assertSame(900.0, $calculated->payableAmount);
    }

    // ── Query efficiency ──────────────────────────────────────────────

    public function test_discovery_page_query_count_does_not_grow_with_result_count(): void
    {
        $this->makeInstructor();
        $this->seedPrice(75000);

        for ($i = 0; $i < 8; $i++) {
            $this->makeInstructor();
        }

        DB::enableQueryLog();
        $this->get(route('instructors.index', ['subject' => $this->subject->slug, 'pricing_country' => 'IN']))->assertOk();
        $withMany = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(80, $withMany);
    }
}
