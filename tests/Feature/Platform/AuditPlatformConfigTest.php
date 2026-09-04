<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Platform\Audit\ConfigAuditFinding;
use App\Platform\Audit\PlatformConfigAuditor;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The audit is read-only and must name, in plain words, each of the
 * configuration gaps that reached customers: a missing wallet webhook
 * secret, and a paid type with no price row for a student's country.
 */
class AuditPlatformConfigTest extends TestCase
{
    use RefreshDatabase;

    private Currency $aud;

    private Country $australia;

    private Subject $biology;

    private BookingType $paidType;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->aud = Currency::factory()->create(['code' => 'AUD', 'minor_units' => 2, 'status' => 'active']);
        $this->australia = Country::factory()->create(['name' => 'Australia', 'iso2' => 'AU', 'status' => 'active', 'default_currency_id' => $this->aud->id]);

        $category = AcademicCategory::create(['name' => 'Science', 'slug' => 'science']);
        $this->biology = Subject::create(['academic_category_id' => $category->id, 'name' => 'Biology', 'slug' => 'biology']);
        AcademicLevel::create(['name' => 'Grade 10', 'slug' => 'grade-10', 'min_grade' => 10, 'max_grade' => 10]);
        $this->paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'name' => 'Paid 1-to-1 Session', 'is_paid' => true, 'duration_minutes' => 60]);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $this->instructor->id], ['instructor_status' => 'approved', 'timezone' => 'Asia/Kolkata']);
        TeacherSubject::factory()->create(['teacher_id' => $this->instructor->id, 'subject' => 'Biology', 'subject_id' => $this->biology->id, 'grade_from' => 1, 'grade_to' => 12]);
        TeacherAvailability::factory()->state(['teacher_id' => $this->instructor->id, 'timezone' => 'Asia/Kolkata'])->create();

        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $this->australia->id, 'timezone' => 'America/New_York']);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->payments_enabled = true;
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key';
        $gateways->razorpay_key_secret = Crypt::encryptString('secret');
        $gateways->razorpay_webhook_secret = Crypt::encryptString('whsec_all');
        $gateways->razorpay_international_enabled = true;
        $gateways->razorpay_international_currencies = ['AUD'];
        $gateways->save();
    }

    private function messages(string $severity): array
    {
        return app(PlatformConfigAuditor::class)->run()
            ->where('severity', $severity)
            ->pluck('message')
            ->all();
    }

    public function test_a_paid_type_with_no_price_for_a_student_country_is_a_failure(): void
    {
        $fails = $this->messages(ConfigAuditFinding::FAIL);

        $this->assertNotEmpty(array_filter($fails, fn (string $m): bool => str_contains($m, 'No base price for "Paid 1-to-1 Session"') && str_contains($m, 'Biology') && str_contains($m, 'Australia')));
    }

    public function test_a_matching_base_price_row_clears_the_pricing_failure(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->biology->id,
            'academic_level_id' => null,
            'country_id' => $this->australia->id,
            'currency_id' => $this->aud->id,
            'currency_code' => 'AUD',
            'duration_minutes' => 60,
            'amount_minor' => 2000,
        ]);

        $fails = $this->messages(ConfigAuditFinding::FAIL);

        $this->assertEmpty(array_filter($fails, fn (string $m): bool => str_contains($m, 'No base price')));
    }

    public function test_a_price_row_with_the_wrong_duration_is_flagged_as_never_matching(): void
    {
        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->biology->id,
            'country_id' => $this->australia->id,
            'currency_id' => $this->aud->id,
            'currency_code' => 'AUD',
            'duration_minutes' => 45,
            'amount_minor' => 2000,
        ]);

        $warns = $this->messages(ConfigAuditFinding::WARN);

        $this->assertNotEmpty(array_filter($warns, fn (string $m): bool => str_contains($m, 'is for 45 min') && str_contains($m, 'can never match')));
    }

    public function test_a_missing_webhook_secret_on_an_enabled_provider_is_a_failure(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_webhook_secret = null;
        $gateways->save();

        $fails = $this->messages(ConfigAuditFinding::FAIL);

        $this->assertNotEmpty(array_filter($fails, fn (string $m): bool => str_contains($m, 'Razorpay is enabled but has NO webhook secret')));
    }

    public function test_a_secret_scoped_to_another_endpoint_leaves_the_wallet_endpoint_unprotected(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_webhook_secret = Crypt::encryptString("booking:whsec_b\npackage:whsec_p");
        $gateways->save();

        $fails = $this->messages(ConfigAuditFinding::FAIL);

        $this->assertNotEmpty(array_filter($fails, fn (string $m): bool => str_contains($m, 'wallet endpoint')));
        $this->assertEmpty(array_filter($fails, fn (string $m): bool => str_contains($m, 'booking endpoint')));
    }

    public function test_a_non_inr_country_without_international_razorpay_is_a_failure(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_international_enabled = false;
        $gateways->save();

        $fails = $this->messages(ConfigAuditFinding::FAIL);

        $this->assertNotEmpty(array_filter($fails, fn (string $m): bool => str_contains($m, 'Australia bills in AUD')));
    }

    public function test_an_invalid_stored_time_zone_is_a_failure(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $user->id], ['timezone' => 'IST']);

        $fails = $this->messages(ConfigAuditFinding::FAIL);

        $this->assertNotEmpty(array_filter($fails, fn (string $m): bool => str_contains($m, 'invalid time zone') && str_contains($m, '"IST"')));
    }

    public function test_the_command_exits_non_zero_on_failures_and_zero_when_clean(): void
    {
        $this->artisan('platform:audit-config')->assertExitCode(1);

        StudentLessonPrice::factory()->create([
            'booking_type_id' => $this->paidType->id,
            'subject_id' => $this->biology->id,
            'academic_level_id' => null,
            'country_id' => $this->australia->id,
            'currency_id' => $this->aud->id,
            'currency_code' => 'AUD',
            'duration_minutes' => 60,
            'amount_minor' => 2000,
        ]);

        $this->artisan('platform:audit-config')->assertExitCode(0);
        $this->artisan('platform:audit-config --json')->assertExitCode(0)->expectsOutputToContain('"fails": 0');
    }
}
