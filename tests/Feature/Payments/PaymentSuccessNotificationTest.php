<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\Weekday;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Country;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentLessonPrice;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\Booking\BookingPaidLessonConfirmedNotification;
use App\Notifications\Booking\BookingPaymentSucceededNotification;
use App\Notifications\Package\PackagePurchasedInstructorNotification;
use App\Notifications\Package\PackagePurchasedStudentNotification;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Package\Services\PackagePurchaseSettlementService;
use App\Payments\DTOs\VerifiedPaymentEvent;
use App\Payments\Enums\PaymentEventType;
use App\Payments\Services\PaymentService;
use App\Settings\GeneralSettings;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * Task 2 — successful-payment communications.
 *
 * The rules these tests defend, none of which the code can be trusted
 * to keep on its own:
 *
 *  - The student (payer) gets mail, an in-app notification, and the
 *    receipt. The instructor gets DIFFERENT mail and an in-app
 *    notification, and NO receipt. Admins get nothing at all.
 *  - Money on the receipt comes from the settled payment record, so a
 *    later price/country/settings change cannot rewrite history.
 *  - A replayed webhook communicates exactly once.
 *  - Pending and failed payments communicate nothing.
 */
class PaymentSuccessNotificationTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', 'instructor', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->teacher->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('super_admin');
    }

    // ── Booking fixtures ──────────────────────────────────────────────────

    /**
     * @return array{Booking, string} booking + the ATTEMPT reference
     */
    private function reserve(float $price = 49.99, string $currency = 'USD'): array
    {
        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', $price, $currency);
        $this->assignBillingCountry($this->student, $priced['country']);

        $booking = app(StudentBookingServiceInterface::class)->book(
            new StudentBookingData(
                typeKey: 'paid_one_to_one',
                studentId: $this->student->id,
                teacherId: $this->teacher->id,
                startsAt: now('UTC')->addDays(3)->setTime(10, 0)->toImmutable(),
                subject: 'maths',
                grade: 7,
            ),
        );

        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $attempt = Payment::query()
            ->where('payable_type', BookingPayment::PAYABLE_TYPE)
            ->latest('created_at')
            ->firstOrFail();

        return [$booking->refresh(), (string) $attempt->idempotency_key];
    }

    private function webhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/bookings/payments/fake', [], [], [], [
            'HTTP_X_BOOKING_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, (string) config('app.key')),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    // ── Booking: who is told what ─────────────────────────────────────────

    public function test_a_settled_booking_payment_notifies_the_student_by_mail_and_in_app(): void
    {
        Notification::fake();
        [, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        Notification::assertSentTo(
            $this->student,
            BookingPaymentSucceededNotification::class,
            function (BookingPaymentSucceededNotification $notification, array $channels): bool {
                $this->assertContains('mail', $channels);
                $this->assertContains('database', $channels);

                return true;
            },
        );
    }

    public function test_a_settled_booking_payment_notifies_the_instructor_with_different_content(): void
    {
        Notification::fake();
        [, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        Notification::assertSentTo($this->teacher, BookingPaidLessonConfirmedNotification::class);

        // The instructor must never receive the student's payment
        // notification — that is the class that carries the receipt.
        Notification::assertNotSentTo($this->teacher, BookingPaymentSucceededNotification::class);
        Notification::assertNotSentTo($this->student, BookingPaidLessonConfirmedNotification::class);
    }

    public function test_the_instructor_booking_mail_carries_no_receipt_amount_or_payment_reference(): void
    {
        [$booking, $reference] = $this->reserve();
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $receipt = Invoice::query()->where('source_id', (string) $payment->id)->firstOrFail();

        $mail = (new BookingPaidLessonConfirmedNotification($booking->refresh()))->toMail($this->teacher);
        $rendered = json_encode($mail->toArray());

        $this->assertStringNotContainsString($receipt->invoice_number, $rendered);
        $this->assertStringNotContainsString('eceipt', $rendered);
        $this->assertStringNotContainsString((string) $payment->idempotency_key, $rendered);
        // The student's price must never appear as instructor earnings.
        $this->assertStringNotContainsString('49.99', $rendered);
        $this->assertStringNotContainsString('earn', strtolower($rendered));
    }

    public function test_no_admin_receives_payment_success_mail_or_notification(): void
    {
        Notification::fake();
        [, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        Notification::assertNotSentTo($this->admin, BookingPaymentSucceededNotification::class);
        Notification::assertNotSentTo($this->admin, BookingPaidLessonConfirmedNotification::class);

        // And nothing was blasted at an arbitrary address either.
        Notification::assertNotSentTo(new AnonymousNotifiable, BookingPaymentSucceededNotification::class);
    }

    // ── Booking: the receipt ──────────────────────────────────────────────

    public function test_a_settled_booking_payment_generates_exactly_one_receipt_owned_by_the_student(): void
    {
        [$booking, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $receipts = Invoice::query()->where('source_id', (string) $payment->id)->get();

        $this->assertCount(1, $receipts);
        // Ownership is what InvoicePolicy::view() gates the download on.
        $this->assertSame($this->student->id, $receipts->first()->user_id);
        $this->assertNotSame($this->teacher->id, $receipts->first()->user_id);
    }

    public function test_only_the_purchasing_student_may_download_the_receipt(): void
    {
        [$booking, $reference] = $this->reserve();
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $receipt = Invoice::query()->where('source_id', (string) $payment->id)->firstOrFail();

        $this->actingAs($this->teacher)
            ->get(route('dashboard.invoices.download', $receipt))
            ->assertForbidden();

        $this->actingAs($this->student)
            ->get(route('dashboard.invoices.download', $receipt))
            ->assertOk();
    }

    // ── Booking: currency comes from the payment, not from anywhere else ──

    public function test_the_student_booking_mail_states_the_settled_payments_own_currency(): void
    {
        [$booking, $reference] = $this->reserve(price: 1250.00, currency: 'INR');
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $receipt = Invoice::query()->where('source_id', (string) $payment->id)->firstOrFail();

        $rendered = json_encode(
            (new BookingPaymentSucceededNotification($booking->refresh(), $payment, $receipt))
                ->toMail($this->student)
                ->toArray(),
        );

        $this->assertStringContainsString('1,250.00 INR', $rendered);
    }

    public function test_a_non_inr_booking_payment_never_renders_a_rupee_sign(): void
    {
        [$booking, $reference] = $this->reserve(price: 49.99, currency: 'USD');
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();

        $rendered = json_encode(
            (new BookingPaymentSucceededNotification($booking->refresh(), $payment))
                ->toMail($this->student)
                ->toArray(),
        );

        $this->assertStringContainsString('49.99 USD', $rendered);
        $this->assertStringNotContainsString('₹', $rendered);
        $this->assertStringNotContainsString('INR', $rendered);
    }

    /**
     * The historical-trustworthiness rule: a receipt is a record of
     * what happened, not a re-quote at today's prices.
     */
    public function test_changing_price_country_or_platform_defaults_never_alters_a_settled_receipt(): void
    {
        [$booking, $reference] = $this->reserve(price: 1250.00, currency: 'INR');
        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $receipt = Invoice::query()->where('source_id', (string) $payment->id)->firstOrFail();

        $originalAmount = (int) $receipt->amount_minor;
        $originalCurrency = (string) $receipt->currency_code;

        // The world moves on after the student has paid: the price
        // matrix is re-cut upward, the student relocates to a different
        // billing country, and the platform's own organization details
        // change. None of it may reach backwards into a settled receipt.
        StudentLessonPrice::query()->update(['amount_minor' => 999900]);

        $newCountry = Country::factory()->create();
        $this->assignBillingCountry($this->student->refresh(), $newCountry);

        $general = app(GeneralSettings::class);
        $general->organization_name = 'Renamed Entity Ltd';
        $general->save();

        $receipt->refresh();

        $this->assertSame($originalAmount, (int) $receipt->amount_minor);
        $this->assertSame($originalCurrency, (string) $receipt->currency_code);
        $this->assertSame(125000, (int) $receipt->amount_minor);
        $this->assertSame('INR', (string) $receipt->currency_code);

        // And the email still renders the historical figure.
        $rendered = json_encode(
            (new BookingPaymentSucceededNotification($booking->refresh(), $payment->refresh(), $receipt))
                ->toMail($this->student)
                ->toArray(),
        );
        $this->assertStringContainsString('1,250.00 INR', $rendered);
        $this->assertStringNotContainsString('9,999.00', $rendered);
    }

    // ── Booking: pending, failed, and replayed ────────────────────────────

    public function test_an_unsettled_booking_payment_communicates_nothing(): void
    {
        Notification::fake();
        $this->reserve();

        // Reserved and awaiting payment — no webhook has arrived.
        Notification::assertNotSentTo($this->student, BookingPaymentSucceededNotification::class);
        Notification::assertNotSentTo($this->teacher, BookingPaidLessonConfirmedNotification::class);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_failed_booking_payment_communicates_no_success(): void
    {
        Notification::fake();
        [, $reference] = $this->reserve();

        $this->webhook(['event' => 'failed', 'reference' => $reference])->assertOk();

        Notification::assertNotSentTo($this->student, BookingPaymentSucceededNotification::class);
        Notification::assertNotSentTo($this->teacher, BookingPaidLessonConfirmedNotification::class);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_replayed_booking_webhook_communicates_exactly_once(): void
    {
        Notification::fake();
        [$booking, $reference] = $this->reserve();

        $this->webhook(['event' => 'succeeded', 'reference' => $reference])->assertOk();
        $this->webhook(['event' => 'succeeded', 'reference' => $reference]);
        $this->webhook(['event' => 'succeeded', 'reference' => $reference]);

        Notification::assertSentToTimes($this->student, BookingPaymentSucceededNotification::class, 1);
        Notification::assertSentToTimes($this->teacher, BookingPaidLessonConfirmedNotification::class, 1);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertCount(1, Invoice::query()->where('source_id', (string) $payment->id)->get());
    }

    // ── Package fixtures ──────────────────────────────────────────────────

    /**
     * Mirrors PackagePurchaseSettlementTest's own fixture: a 20-paid +
     * 5-bonus rule priced in GBP, proposed to this test's student by
     * this test's instructor, approved and accepted so a purchase with
     * an open payment attempt exists.
     *
     * @return array{StudentPackagePurchase, Payment}
     */
    private function acceptedPackageWithOpenAttempt(): array
    {
        $this->seed(PackagePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        $fixture = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 20.00, 'GBP');
        $this->assignBillingCountry($this->student, $fixture['country']);

        // The proposal rules require an existing delivered relationship.
        Booking::factory()->confirmed()->paid()->create([
            'booking_type_id' => $fixture['type']->id,
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $rule = app(PackageBenefitRuleService::class)->create($manager, [
            'name' => '20 paid + 5 bonus',
            'paid_quantity' => 20,
            'bonus_quantity' => 5,
            'total_quantity' => 25,
            'validity_days' => 90,
        ]);

        $proposals = app(InstructorPackageProposalService::class);
        $proposal = $proposals->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: $this->teacher->id,
            studentId: $this->student->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $this->seedLessonSubject()->id,
            academicLevelId: null,
        ));

        $accepted = $proposals->acceptProposal(
            $proposals->approve($proposal, $manager, null, null),
            $this->student,
        );

        $purchase = StudentPackagePurchase::query()->where('proposal_id', $accepted->id)->firstOrFail();

        $payments = app(PaymentService::class);
        $payment = $payments->startAttempt($purchase, 'fake', 'PAY-'.strtoupper(bin2hex(random_bytes(6))));
        $payments->recordProviderOrder($payment, 'fake_order_1');

        return [$purchase->refresh(), $payment->refresh()];
    }

    private function settlePackage(Payment $payment): void
    {
        app(PackagePurchaseSettlementService::class)->settle(
            $payment,
            new VerifiedPaymentEvent(
                provider: (string) $payment->provider,
                type: PaymentEventType::Succeeded,
                reference: $payment->idempotency_key,
                providerOrderId: $payment->provider_order_id,
                providerPaymentId: 'pay_settled_1',
                amountMinor: (int) $payment->amount_minor,
                currencyCode: (string) $payment->currency_code,
            ),
        );
    }

    // ── Package: who is told what ─────────────────────────────────────────

    public function test_a_settled_package_purchase_notifies_student_and_instructor_differently(): void
    {
        Notification::fake();
        [, $payment] = $this->acceptedPackageWithOpenAttempt();

        $this->settlePackage($payment);

        Notification::assertSentTo($this->student, PackagePurchasedStudentNotification::class);
        Notification::assertSentTo($this->teacher, PackagePurchasedInstructorNotification::class);

        Notification::assertNotSentTo($this->teacher, PackagePurchasedStudentNotification::class);
        Notification::assertNotSentTo($this->student, PackagePurchasedInstructorNotification::class);
        Notification::assertNotSentTo($this->admin, PackagePurchasedStudentNotification::class);
        Notification::assertNotSentTo($this->admin, PackagePurchasedInstructorNotification::class);
    }

    public function test_the_student_package_mail_states_paid_bonus_and_total_quantities(): void
    {
        [$purchase, $payment] = $this->acceptedPackageWithOpenAttempt();
        $this->settlePackage($payment);

        $entitlement = StudentPackageEntitlement::query()
            ->where('proposal_id', $purchase->proposal_id)
            ->firstOrFail();

        $rendered = json_encode(
            (new PackagePurchasedStudentNotification($purchase->refresh(), $payment->refresh(), $entitlement))
                ->toMail($this->student)
                ->toArray(),
        );

        // The fixture's benefit rule is 20 paid + 5 bonus = 25 total.
        $this->assertStringContainsString('Paid lessons: 20', $rendered);
        $this->assertStringContainsString('Bonus lessons included: 5', $rendered);
        $this->assertStringContainsString('Total lessons available: 25', $rendered);

        // Bonus lessons are an entitlement benefit, never a refund or discount.
        $this->assertStringNotContainsString('refund', strtolower($rendered));
        $this->assertStringNotContainsString('discount', strtolower($rendered));
    }

    public function test_the_student_package_mail_uses_the_purchases_own_currency(): void
    {
        [$purchase, $payment] = $this->acceptedPackageWithOpenAttempt();
        $this->settlePackage($payment);

        $entitlement = StudentPackageEntitlement::query()
            ->where('proposal_id', $purchase->proposal_id)
            ->firstOrFail();

        $rendered = json_encode(
            (new PackagePurchasedStudentNotification($purchase->refresh(), $payment->refresh(), $entitlement))
                ->toMail($this->student)
                ->toArray(),
        );

        // The package fixture prices in GBP — never the platform default,
        // never the rupee sign.
        $this->assertStringContainsString('GBP', $rendered);
        $this->assertStringNotContainsString('₹', $rendered);
        $this->assertStringNotContainsString('INR', $rendered);
    }

    public function test_the_instructor_package_mail_carries_no_amount_or_receipt(): void
    {
        [$purchase, $payment] = $this->acceptedPackageWithOpenAttempt();
        $this->settlePackage($payment);

        $entitlement = StudentPackageEntitlement::query()
            ->where('proposal_id', $purchase->proposal_id)
            ->firstOrFail();
        $receipt = Invoice::query()->where('source_id', (string) $payment->id)->firstOrFail();

        $rendered = json_encode(
            (new PackagePurchasedInstructorNotification($purchase->refresh(), $entitlement))
                ->toMail($this->teacher)
                ->toArray(),
        );

        $this->assertStringNotContainsString($receipt->invoice_number, $rendered);
        $this->assertStringNotContainsString('eceipt', $rendered);
        $this->assertStringNotContainsString('GBP', $rendered);
        $this->assertStringNotContainsString('earn', strtolower($rendered));

        // It still carries what the instructor actually needs.
        $this->assertStringContainsString('25', $rendered);
    }

    // ── Package: receipt, replay, and entitlement ─────────────────────────

    public function test_a_settled_package_purchase_generates_one_receipt_owned_by_the_student(): void
    {
        [, $payment] = $this->acceptedPackageWithOpenAttempt();
        $this->settlePackage($payment);

        $receipts = Invoice::query()->where('source_id', (string) $payment->id)->get();

        $this->assertCount(1, $receipts);
        $this->assertSame($this->student->id, $receipts->first()->user_id);
        $this->assertSame('GBP', (string) $receipts->first()->currency_code);
    }

    public function test_a_replayed_package_settlement_communicates_once_and_entitles_once(): void
    {
        Notification::fake();
        [$purchase, $payment] = $this->acceptedPackageWithOpenAttempt();

        $this->settlePackage($payment);
        $this->settlePackage($payment->refresh());
        $this->settlePackage($payment->refresh());

        Notification::assertSentToTimes($this->student, PackagePurchasedStudentNotification::class, 1);
        Notification::assertSentToTimes($this->teacher, PackagePurchasedInstructorNotification::class, 1);

        $this->assertSame(1, StudentPackageEntitlement::query()
            ->where('proposal_id', $purchase->proposal_id)->count());
        $this->assertCount(1, Invoice::query()->where('source_id', (string) $payment->id)->get());
    }

    public function test_an_unsettled_package_purchase_communicates_nothing(): void
    {
        Notification::fake();
        $this->acceptedPackageWithOpenAttempt();

        Notification::assertNotSentTo($this->student, PackagePurchasedStudentNotification::class);
        Notification::assertNotSentTo($this->teacher, PackagePurchasedInstructorNotification::class);
        $this->assertDatabaseCount('invoices', 0);
    }
}
