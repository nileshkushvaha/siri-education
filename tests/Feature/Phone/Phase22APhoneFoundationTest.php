<?php

declare(strict_types=1);

namespace Tests\Feature\Phone;

use App\Booking\Exceptions\BookingException;
use App\Contracts\PhoneOtpSender;
use App\Contracts\PhoneVerificationServiceInterface;
use App\Contracts\StudentFinancialVerificationGate;
use App\Livewire\Frontend\Auth\RegisterForm;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\PhoneVerificationChallenge;
use App\Models\User;
use App\Services\Phone\PhoneNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Phase22APhoneFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_service_normalizes_us_uk_and_india_numbers(): void
    {
        foreach ([['US', '(202) 555-0123', '+12025550123'], ['GB', '020 7946 0018', '+442079460018'], ['IN', '98765 43210', '+919876543210']] as [$iso2, $input, $expected]) {
            $this->country($iso2);
            $this->assertSame($expected, app(PhoneNumberService::class)->normalize($input, $iso2)?->e164);
        }
    }

    public function test_new_registration_defaults_to_us_usd_and_plus_one(): void
    {
        $us = $this->country('US', 'USD', '+1');
        $component = Livewire::test(RegisterForm::class);

        $component->assertSet('country_id', $us->id)->assertSet('phone_country_iso2', 'US');
        $component->assertSee('(202) 555-0123')->assertSee('United States — USD');
    }

    public function test_manual_phone_country_mismatch_warns_and_does_not_change_residence_country(): void
    {
        $us = $this->country('US', 'USD', '+1');
        $this->country('IN', 'INR', '+91');

        Livewire::test(RegisterForm::class)
            ->set('phone_country_iso2', 'IN')
            ->assertSet('country_id', $us->id)
            ->assertSee('different country code');
    }

    public function test_otp_is_hashed_single_use_and_verifies_exact_saved_number(): void
    {
        $this->country('US');
        $user = User::factory()->create();
        $user->profile->update(['phone_country_iso2' => 'US', 'phone_e164' => '+12025550123', 'phone_verification_status' => 'unverified']);
        $sender = new class implements PhoneOtpSender
        {
            public ?string $code = null;

            public function available(): bool
            {
                return true;
            }

            public function send(string $e164, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(PhoneOtpSender::class, $sender);
        $service = app(PhoneVerificationServiceInterface::class);

        $service->send($user->fresh('profile'), '127.0.0.1');
        $challenge = PhoneVerificationChallenge::query()->sole();
        $this->assertNotSame($sender->code, $challenge->code_hash);
        $this->assertTrue(Hash::check((string) $sender->code, $challenge->code_hash));

        $service->verify($user->fresh('profile'), (string) $sender->code, '127.0.0.1');
        $this->assertNotNull($user->profile->fresh()->phone_verified_at);
        $this->assertNotNull($challenge->fresh()->consumed_at);
    }

    public function test_financial_gate_allows_free_demo_and_requires_verified_phone_for_paid_booking(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create();
        $student->assignRole('student');
        $free = BookingType::factory()->create(['is_paid' => false]);
        $paid = BookingType::factory()->create(['is_paid' => true]);
        $gate = app(StudentFinancialVerificationGate::class);

        $gate->assertEligible($student->fresh('profile'), $free);
        $this->expectException(BookingException::class);
        $gate->assertEligible($student->fresh('profile'), $paid);
    }

    private function country(string $iso2, string $currencyCode = 'USD', string $dialCode = '+1'): Country
    {
        $currency = Currency::query()->firstOrCreate(['code' => $currencyCode], ['name' => $currencyCode, 'symbol' => '$', 'numeric_code' => (string) random_int(100, 999), 'minor_units' => 2, 'status' => 'active']);

        $names = ['US' => 'United States', 'GB' => 'United Kingdom', 'IN' => 'India'];

        return Country::factory()->create(['name' => $names[$iso2] ?? $iso2, 'iso2' => $iso2, 'phone_code' => $dialCode, 'default_currency_id' => $currency->id, 'status' => 'active']);
    }
}
