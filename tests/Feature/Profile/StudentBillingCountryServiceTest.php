<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Student\StudentBillingCountryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentBillingCountryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_change_country_before_billing_activity_exists(): void
    {
        [$student, $destination] = $this->studentAndDestination();

        app(StudentBillingCountryService::class)->assertChangeAllowed($student, $destination->id);

        $this->addToAssertionCount(1);
    }

    public function test_student_cannot_change_country_after_wallet_activity_exists(): void
    {
        [$student, $destination] = $this->studentAndDestination();
        Wallet::factory()->create([
            'user_id' => $student->id,
            'balance_minor' => 1000,
            'available_balance_minor' => 1000,
        ]);

        try {
            app(StudentBillingCountryService::class)->assertChangeAllowed($student, $destination->id);
            $this->fail('Expected the billing-country change to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('country_id', $exception->errors());
        }
    }

    /** @return array{User, Country} */
    private function studentAndDestination(): array
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create();
        $student->assignRole('student');
        $currency = Currency::factory()->create(['status' => 'active']);
        $current = Country::factory()->create(['default_currency_id' => $currency->id]);
        $destination = Country::factory()->create(['default_currency_id' => $currency->id]);
        $student->profile->update(['country_id' => $current->id]);

        return [$student->fresh('profile'), $destination];
    }
}
