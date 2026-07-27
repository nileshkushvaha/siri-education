<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Frontend;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\Weekday;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Proves the Stripe Payment Element frontend contract at
 * the Livewire boundary (the JS itself is exercised by Stripe.js, not
 * covered here — see the guardrail against real Stripe calls in
 * automated tests): the checkout payload's client_secret/publishable_key
 * only ever travel in the transient `stripe-checkout-ready` dispatch,
 * never a public component property, and checkPaymentStatus() only ever
 * reads server state — it can never itself mark a booking paid.
 */
class StripeCheckoutFrontendTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const SECRET_KEY = 'sk_test_frontend123';

    private const PUBLISHABLE_KEY = 'pk_test_frontend123';

    private const WEBHOOK_SECRET = 'whsec_test_frontend123';

    private User $student;

    private StripeGatewayClient&MockInterface $stripeGateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840', 'minor_units' => 2, 'status' => 'active']);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 49.00, 'USD');
        $this->assignBillingCountry($this->student, $priced['country']);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = self::PUBLISHABLE_KEY;
        $gateways->stripe_secret_key = Crypt::encryptString(self::SECRET_KEY);
        $gateways->stripe_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();
        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        $this->stripeGateway = Mockery::mock(StripeGatewayClient::class);
        $this->stripeGateway->shouldReceive('createPaymentIntent')
            ->andReturn(['id' => 'pi_frontend_test', 'client_secret' => 'pi_frontend_test_secret_xyz', 'amount' => 4900, 'currency' => 'usd']);
        $this->stripeGateway->shouldReceive('retrievePaymentIntent')
            ->andReturn(['id' => 'pi_frontend_test', 'client_secret' => 'pi_frontend_test_secret_xyz', 'status' => 'succeeded']);
        $this->app->instance(StripeGatewayClient::class, $this->stripeGateway);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        $this->booking = $booking->refresh();
    }

    private Booking $booking;

    public function test_initiate_payment_dispatches_stripe_checkout_ready_with_the_client_secret_never_on_a_public_property(): void
    {
        $component = Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $this->booking->id)
            ->call('initiatePayment');

        $component->assertDispatched('stripe-checkout-ready', clientSecret: 'pi_frontend_test_secret_xyz', publishableKey: self::PUBLISHABLE_KEY);

        // Only the provider name is public state — the client_secret is
        // never stored on $paymentOrder, which Livewire would otherwise
        // serialize straight into the page's HTML.
        $this->assertSame(['provider' => 'stripe'], $component->get('paymentOrder'));
        $this->assertStringNotContainsString('pi_frontend_test_secret_xyz', (string) $component->html());
    }

    public function test_check_payment_status_never_marks_a_booking_paid_itself_only_a_webhook_can(): void
    {
        $component = Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $this->booking->id)
            ->call('initiatePayment')
            ->call('checkPaymentStatus');

        // No webhook has arrived — checkPaymentStatus() re-reads and
        // finds the booking still pending; it must never have settled it.
        $this->booking->refresh();
        $this->assertSame('pending', $this->booking->payment_status->value);
        $component->assertSet('modalBanner', '');
    }

    public function test_check_payment_status_reflects_a_webhook_settled_outcome_without_calling_it_itself(): void
    {
        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $this->booking->id)
            ->call('initiatePayment');

        // Simulate the *server-side* effect a signed Stripe webhook would
        // have — this call belongs to the webhook path, not the frontend
        // component under test; checkPaymentStatus() must only observe it.
        $reference = (string) $this->booking->refresh()->payment_reference;
        app(BookingPaymentServiceInterface::class)->markPaid($this->booking, $reference);

        $component = Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $this->booking->id)
            ->call('checkPaymentStatus');

        $component->assertSet('modalBanner', '');
        $this->assertSame('paid', $this->booking->refresh()->payment_status->value);
    }
}
