<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Filament\Pages\Settings\PaymentGatewayPage;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The "Validate Credentials" action must not call
 * saveGatewaySettings($this->data), which persists *every* gateway's
 * current form state, not just the one being validated — an admin
 * mid-edit on another tab (e.g. an unsaved `stripe_enabled` toggle)
 * would have that unrelated change silently committed by clicking
 * "Validate Credentials" for Razorpay. Persistence is scoped to only
 * the gateway actually being validated
 * (persistCredentialFieldsForValidation()).
 */
class PaymentGatewaySettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function callValidate(string $gateway): void
    {
        $page = app(PaymentGatewayPage::class);
        $page->mount();

        $method = new ReflectionMethod($page, 'validateAndPersistGatewayReadiness');
        $method->setAccessible(true);

        // Mutate $this->data directly on the same instance the reflection
        // call will use — mirrors a live Livewire form's in-memory state.
        $dataProperty = (new \ReflectionClass($page))->getProperty('data');
        $dataProperty->setAccessible(true);
        $data = $dataProperty->getValue($page);
        $data['razorpay_key_id'] = 'rzp_test_key_id';
        $data['stripe_enabled'] = true;
        $dataProperty->setValue($page, $data);

        $method->invoke($page, $gateway);
    }

    public function test_validating_one_gateway_does_not_persist_another_gateways_unsaved_state(): void
    {
        $this->assertFalse(app(PaymentGatewaySettings::class)->stripe_enabled);

        $this->callValidate('razorpay');

        $this->assertFalse(
            app(PaymentGatewaySettings::class)->stripe_enabled,
            'Validating Razorpay must not persist an unrelated, unsaved stripe_enabled toggle.',
        );
    }

    public function test_validating_razorpay_does_persist_its_own_credential_fields(): void
    {
        $this->callValidate('razorpay');

        $this->assertSame('rzp_test_key_id', app(PaymentGatewaySettings::class)->razorpay_key_id);
    }
}
