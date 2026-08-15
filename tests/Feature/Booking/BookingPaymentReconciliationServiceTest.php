<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentReconciliationServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingPaymentReconciliationIssueStatus;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\GatewayRequestException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * The collection-side mirror of the payout domain's reconciliation
 * guarantees: DB-deduplicated open issues (never a
 * duplicate for the same payment+type), idempotent finalization funneled
 * exclusively through BookingPaymentService::applyProviderStatus(), and
 * a mandatory-evidence resolve() that only ever closes the issue row.
 */
class BookingPaymentReconciliationServiceTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private StripeGatewayClient&MockInterface $stripeGateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'numeric_code' => '840', 'minor_units' => 2, 'status' => 'active']);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 49.00, 'USD');
        $this->assignBillingCountry($this->student, $priced['country']);

        $gateways = app(PaymentGatewaySettings::class);
        $gateways->stripe_enabled = true;
        $gateways->stripe_publishable_key = 'pk_test_recon123';
        $gateways->stripe_secret_key = Crypt::encryptString('sk_test_recon123');
        $gateways->stripe_webhook_secret = Crypt::encryptString('whsec_recon123');
        $gateways->save();
        app(BookingSettings::class)->payment_provider = 'stripe';
        app(BookingSettings::class)->save();

        $this->stripeGateway = Mockery::mock(StripeGatewayClient::class);
        $this->app->instance(StripeGatewayClient::class, $this->stripeGateway);
    }

    private function pendingStripePayment(): BookingPayment
    {
        $this->stripeGateway->shouldReceive('createPaymentIntent')
            ->andReturn(['id' => 'pi_recon_test', 'client_secret' => 'pi_recon_test_secret', 'amount' => 4900, 'currency' => 'usd']);

        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        app(BookingPaymentServiceInterface::class)->initiate($booking->refresh());

        return BookingPayment::query()->where('booking_id', $booking->id)->sole();
    }

    /** The provider order id now lives on the ATTEMPT, not the obligation. */
    private function attemptOrderId(BookingPayment $obligation): string
    {
        return (string) Payment::query()
            ->where('payable_id', $obligation->getKey())
            ->latest('created_at')
            ->sole()
            ->provider_order_id;
    }

    public function test_reconcile_attempt_settles_a_payment_the_local_state_did_not_yet_know_about(): void
    {
        $payment = $this->pendingStripePayment();

        // PAY-1: a real Stripe intent always reports the money it took,
        // and settlement now compares it against the booking payment
        // before capturing. A fixture without it exercises a payload
        // production never sends.
        $this->stripeGateway->shouldReceive('retrievePaymentIntent')
            ->andReturn([
                'id' => $this->attemptOrderId($payment),
                'status' => 'succeeded',
                'amount_received' => $payment->amount_minor,
                'currency' => strtolower($payment->currency_code),
            ]);

        $updated = app(BookingPaymentReconciliationServiceInterface::class)->reconcileAttempt($payment);

        $this->assertSame(BookingPaymentRecordStatus::Captured, $updated->status);
        $this->assertNotNull($updated->last_synced_at);

        $booking = Booking::query()->findOrFail($payment->booking_id);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
    }

    public function test_reconcile_due_skips_terminal_payments_and_examines_pending_ones(): void
    {
        $duePayment = $this->pendingStripePayment();

        // A second, already-settled payment must be left alone.
        $settledBooking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays(4)->setTime(10, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));
        $settledPayment = BookingPayment::factory()->create([
            'booking_id' => $settledBooking->id,
            'user_id' => $this->student->id,
            'provider' => 'stripe',
            'provider_order_id' => 'pi_already_settled',
            'amount_minor' => 4900,
            'currency_code' => 'USD',
            'status' => BookingPaymentRecordStatus::Captured,
            'idempotency_key' => 'PAY-ALREADY-SETTLED',
        ]);

        $dueOrderId = $this->attemptOrderId($duePayment);

        $this->stripeGateway->shouldReceive('retrievePaymentIntent')
            ->with(Mockery::any(), $dueOrderId)
            ->once()
            ->andReturn([
                'id' => $dueOrderId,
                'status' => 'succeeded',
                'amount_received' => $duePayment->amount_minor,
                'currency' => strtolower($duePayment->currency_code),
            ]);

        $examined = app(BookingPaymentReconciliationServiceInterface::class)->reconcileDue();

        $this->assertSame(1, $examined);
        $this->assertSame(BookingPaymentRecordStatus::Captured, $duePayment->refresh()->status);
        // Untouched — no fetchStatus() call was ever made for it (Mockery's
        // ->once() above would fail the test otherwise).
        $this->assertNull($settledPayment->refresh()->last_synced_at);
    }

    public function test_provider_unavailable_raises_a_reconciliation_issue_instead_of_throwing(): void
    {
        $payment = $this->pendingStripePayment();

        $this->stripeGateway->shouldReceive('retrievePaymentIntent')
            ->andThrow(new GatewayRequestException('Stripe timed out.'));

        app(BookingPaymentReconciliationServiceInterface::class)->reconcileAttempt($payment);

        $issue = BookingPaymentReconciliationIssue::query()->where('booking_payment_id', $payment->id)->sole();
        $this->assertSame(BookingPaymentReconciliationIssueType::ProviderUnavailable, $issue->type);
        $this->assertSame(BookingPaymentReconciliationIssueStatus::Open, $issue->status);
        $this->assertNotNull($payment->refresh()->last_synced_at);
    }

    public function test_raise_issue_deduplicates_open_issues_of_the_same_type_for_the_same_payment(): void
    {
        $payment = $this->pendingStripePayment();
        $service = app(BookingPaymentReconciliationServiceInterface::class);

        $first = $service->raiseIssue($payment, BookingPaymentReconciliationIssueType::AmountMismatch, BookingPaymentReconciliationSeverity::Warning, 'First detection.');
        $second = $service->raiseIssue($payment, BookingPaymentReconciliationIssueType::AmountMismatch, BookingPaymentReconciliationSeverity::Critical, 'Second detection — same payment, same type.');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BookingPaymentReconciliationIssue::query()->where('booking_payment_id', $payment->id)->count());
        // Escalates to the higher severity seen across detections.
        $this->assertSame(BookingPaymentReconciliationSeverity::Critical, $second->refresh()->severity);
    }

    public function test_resolve_requires_a_note_and_never_mutates_the_payment(): void
    {
        $payment = $this->pendingStripePayment();
        $service = app(BookingPaymentReconciliationServiceInterface::class);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $issue = $service->raiseIssue($payment, BookingPaymentReconciliationIssueType::UnknownPaymentOutcome, BookingPaymentReconciliationSeverity::Warning, 'Needs a human.');

        $this->expectException(BookingException::class);
        $service->resolve($issue, $actor, 'false_positive', '');
    }

    public function test_resolve_closes_the_issue_and_leaves_payment_status_untouched(): void
    {
        $payment = $this->pendingStripePayment();
        $service = app(BookingPaymentReconciliationServiceInterface::class);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $issue = $service->raiseIssue($payment, BookingPaymentReconciliationIssueType::UnknownPaymentOutcome, BookingPaymentReconciliationSeverity::Warning, 'Needs a human.');
        $resolved = $service->resolve($issue, $actor, 'confirmed_success', 'Verified directly in the Stripe dashboard.');

        $this->assertSame(BookingPaymentReconciliationIssueStatus::Resolved, $resolved->status);
        $this->assertSame($actor->id, $resolved->resolved_by);
        // Resolving the issue never itself settles the payment — only
        // applyProviderStatus() (via a real fetchStatus() or webhook) may.
        $this->assertSame(BookingPaymentRecordStatus::Pending, $payment->refresh()->status);
    }
}
