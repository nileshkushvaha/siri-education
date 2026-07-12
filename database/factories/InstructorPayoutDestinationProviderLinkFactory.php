<?php

namespace Database\Factories;

use App\Earnings\Enums\RazorpayXProviderLinkStatus;
use App\Models\InstructorPayoutDestinationProviderLink;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorPayoutDestinationProviderLink>
 */
class InstructorPayoutDestinationProviderLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payout_method_id' => InstructorPayoutMethod::factory()->verified(),
            'instructor_id' => User::factory(),
            'provider' => 'razorpayx',
            'provider_contact_reference' => 'ins_'.$this->faker->unique()->regexify('[a-f0-9]{20}'),
            'bank_details_fingerprint' => hash('sha256', 'factory|'.$this->faker->unique()->numerify('##########')),
            'status' => RazorpayXProviderLinkStatus::Pending,
        ];
    }

    public function contactReady(): static
    {
        return $this->state(fn () => [
            'status' => RazorpayXProviderLinkStatus::ContactReady,
            'provider_contact_id' => 'cont_'.$this->faker->unique()->regexify('[A-Za-z0-9]{14}'),
            'provider_contact_status' => 'active',
        ]);
    }

    public function ready(): static
    {
        return $this->contactReady()->state(fn () => [
            'status' => RazorpayXProviderLinkStatus::Ready,
            'provider_fund_account_id' => 'fa_'.$this->faker->unique()->regexify('[A-Za-z0-9]{14}'),
            'provider_fund_account_status' => 'active',
        ]);
    }

    public function contactUnknown(): static
    {
        return $this->state(fn () => [
            'status' => RazorpayXProviderLinkStatus::ContactUnknown,
            'last_provisioning_error' => 'RazorpayX could not be reached.',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'status' => RazorpayXProviderLinkStatus::Disabled,
            'disabled_at' => now(),
        ]);
    }
}
