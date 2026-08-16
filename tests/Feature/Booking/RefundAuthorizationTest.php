<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Filament\Resources\BookingPayments\Pages\ListBookingPayments;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Payments\Enums\PaymentStatus;
use App\Settings\BookingSettings;
use App\Settings\PaymentGatewaySettings;
use Database\Seeders\BookingPaymentPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The refund authorization matrix (SRS 11.25: "Admin override is
 * permitted only with audit reason").
 *
 * A refund moves real money, so the UI is explicitly NOT treated as the
 * security boundary here: every denial case calls the underlying policy
 * AND drives the Filament action through Livewire as the unprivileged
 * user, exactly as a crafted browser request would. The gateway client
 * is a strict mock with no expectations — any provider call from a
 * denied actor fails the test rather than silently refunding money.
 *
 * Wallet-side refunds (the SRS-default destination) are deliberately
 * NOT student-triggerable at all: there is no student-facing refund
 * entry point to test, only the cancellation pipeline, which decides
 * refunds itself. This file guards the one staff-facing mutation.
 */
class RefundAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const string PERMISSION = 'RefundViaProvider:BookingPayment';

    private Booking $booking;

    private BookingPayment $obligation;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->seed(BookingPaymentPermissionSeeder::class);

        foreach (['student', 'instructor', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->seedCapturedBooking();

        // Strict: no expectations set, so ANY gateway call is a failure.
        // A denied actor must never reach the provider.
        $this->instance(RazorpayGatewayClient::class, Mockery::mock(RazorpayGatewayClient::class));
    }

    // ── Allowed ───────────────────────────────────────────────────────────

    public function test_super_admin_is_allowed_to_refund(): void
    {
        $user = $this->staff();
        $user->assignRole('super_admin');

        // Gate::before() bypass — never a replicated role check in the policy.
        $this->assertTrue($user->can('refundViaProvider', $this->obligation));
    }

    public function test_manager_with_the_refund_permission_is_allowed(): void
    {
        // The seeder grants it to `manager`, but the permission — not the
        // role name — is what the policy consults.
        $this->assertTrue($this->staff('manager')->can('refundViaProvider', $this->obligation));
    }

    public function test_any_role_granted_the_permission_is_allowed(): void
    {
        // Proves the permission is genuinely role-agnostic: an
        // organization can mint a "finance" role and assign refund
        // authority without a code change.
        $finance = Role::findOrCreate('finance_manager', 'web');
        $finance->givePermissionTo(self::PERMISSION);

        $this->assertTrue($this->staff('finance_manager')->can('refundViaProvider', $this->obligation));
    }

    public function test_a_user_granted_the_permission_directly_is_allowed(): void
    {
        $user = $this->staff();
        $user->givePermissionTo(self::PERMISSION);

        $this->assertTrue($user->can('refundViaProvider', $this->obligation));
    }

    /**
     * The control that makes every denial above meaningful.
     *
     * Same component, same raw Livewire messages, same record — only the
     * actor's permission differs, and this time the gateway IS called
     * and the refund IS recorded. Without this, the denial tests could
     * pass simply because the crafted request never worked for anyone.
     */
    public function test_the_same_crafted_request_succeeds_for_a_permitted_actor(): void
    {
        $this->configureRazorpay();

        $providerPaymentId = Payment::query()->sole()->provider_payment_id;

        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('refundPayment')
            ->once()
            ->withArgs(fn (string $keyId, string $keySecret, string $paymentId, array $params): bool => $paymentId === $providerPaymentId
                && $params['amount'] === 49900)
            ->andReturn(['id' => 'rfnd_authorized']);
        $this->instance(RazorpayGatewayClient::class, $gateway);

        $this->actingAs($this->staff('manager'));

        Livewire::test(ListBookingPayments::class)
            ->call('mountAction', 'refund_via_provider', [], [
                'table' => true,
                'recordKey' => $this->obligation->getKey(),
            ])
            ->set('mountedActions.0.data.reason', 'Duplicate charge — finance correction.')
            ->call('callMountedAction');

        $this->assertSame(BookingPaymentRecordStatus::Refunded, $this->obligation->refresh()->status);
        $this->assertSame(BookingPaymentStatus::Refunded, $this->booking->refresh()->payment_status);
        $this->assertSame('provider_refunded', $this->obligation->metadata['refund_resolution']);
        $this->assertSame('Duplicate charge — finance correction.', $this->obligation->metadata['refund_reason']);
    }

    // ── Denied ────────────────────────────────────────────────────────────

    public function test_student_cannot_initiate_a_refund(): void
    {
        $this->assertRefundDenied($this->booking->student);
    }

    public function test_student_who_owns_the_payment_still_cannot_refund_it(): void
    {
        // Ownership grants `view` on the attempt (BookingPaymentPolicy)
        // — it must never leak into the refund mutation.
        $owner = $this->booking->student;
        $this->obligation->forceFill(['user_id' => $owner->id])->save();

        $this->assertTrue($owner->can('view', $this->obligation->refresh()));
        $this->assertRefundDenied($owner);
    }

    public function test_instructor_cannot_initiate_a_refund(): void
    {
        $this->assertRefundDenied($this->booking->instructor);
    }

    public function test_manager_without_the_refund_permission_is_denied(): void
    {
        // An organization that withholds refund authority from managers
        // configures exactly this — and the panel must honour it. Being
        // a manager is never itself sufficient.
        Role::findByName('manager', 'web')->revokePermissionTo(self::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $manager = $this->staff('manager');

        $this->assertTrue($manager->can('viewAny', BookingPayment::class), 'The manager must still reach the payments panel.');
        $this->assertRefundDenied($manager);
    }

    public function test_unrelated_authenticated_user_is_denied(): void
    {
        $this->assertRefundDenied($this->staff());
    }

    public function test_guest_cannot_reach_the_refund_action(): void
    {
        $this->assertGuest();

        Livewire::test(ListBookingPayments::class)->assertForbidden();

        $this->assertRefundUnexecuted();
    }

    public function test_a_deleted_permission_denies_rather_than_erroring(): void
    {
        // hasPermissionTo() throws PermissionDoesNotExist for an unknown
        // permission; the policy must translate that into a denial, not
        // a 500 that some other layer might interpret as "allowed".
        Permission::query()->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->staff('manager')->can('refundViaProvider', $this->obligation));
    }

    // ── Duplicate submission ──────────────────────────────────────────────

    /**
     * Two clicks, one refund.
     *
     * There are two independent guards and the replay is stopped by
     * whichever it reaches first: the booking is no longer Paid, and —
     * should a status sync ever lag — the `refund_resolution` marker
     * written in the same transaction that resolved the charge. Either
     * way the refusal happens BEFORE the gateway, so `->once()` on the
     * mock is the assertion that actually matters.
     */
    public function test_a_replayed_refund_submission_does_not_call_the_gateway_twice(): void
    {
        $this->configureRazorpay();

        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('refundPayment')->once()->andReturn(['id' => 'rfnd_once']);
        $this->instance(RazorpayGatewayClient::class, $gateway);

        $admin = $this->staff('manager');
        $service = app(BookingPaymentServiceInterface::class);

        $service->refundViaProvider($this->booking->refresh(), $admin, 'Duplicate charge.');

        // The identical command again — a double-click, a replayed
        // Livewire message, or a second operator on the same record.
        try {
            $service->refundViaProvider($this->booking->refresh(), $admin, 'Duplicate charge.');
            $this->fail('The second refund submission must be refused.');
        } catch (BookingException $e) {
            $this->assertMatchesRegularExpression('/nothing to refund|already resolved/', $e->getMessage());
        }

        $this->assertSame('provider_refunded', $this->obligation->refresh()->metadata['refund_resolution']);
        $this->assertSame(
            49900,
            $this->obligation->amount_minor,
            'The original captured amount is history and is never rewritten by a refund.',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Denial is asserted at BOTH boundaries: the policy itself, and the
     * Filament action driven as that user — knowing the record key is
     * not enough to move money.
     */
    private function assertRefundDenied(User $user): void
    {
        $this->assertFalse(
            $user->can('refundViaProvider', $this->obligation),
            sprintf('%s must not hold refund authority.', $user->email),
        );

        $this->actingAs($user);

        // Deliberately NOT the ->callTableAction() helper: that asserts
        // the button is visible first, which would only ever re-prove
        // that the UI hides it. These are the raw Livewire messages a
        // crafted browser request sends when the attacker already knows
        // the action name and the record key.
        try {
            Livewire::test(ListBookingPayments::class)
                ->call('mountAction', 'refund_via_provider', [], [
                    'table' => true,
                    'recordKey' => $this->obligation->getKey(),
                ])
                ->set('mountedActions.0.data.reason', 'Crafted request from an unauthorized actor.')
                ->call('callMountedAction');
        } catch (\Throwable) {
            // Forbidden page, forbidden action, or an unresolvable
            // action — all are denials. The assertion that matters is
            // that no money moved, and it runs either way.
        }

        $this->assertRefundUnexecuted();
    }

    /** No money moved, no state changed, no refund record — by any route. */
    private function assertRefundUnexecuted(): void
    {
        $this->assertSame(
            BookingPaymentRecordStatus::Captured,
            $this->obligation->refresh()->status,
            'The captured obligation must survive an unauthorized refund attempt.',
        );

        $this->assertSame(BookingPaymentStatus::Paid, $this->booking->refresh()->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $this->booking->status);
        $this->assertNull($this->obligation->metadata['refund_resolution'] ?? null);
        $this->assertSame(0, WalletLedgerEntry::query()->count(), 'No wallet credit may be created by a denied actor.');
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString('test_key_secret');
        $gateways->save();

        $bookings = app(BookingSettings::class);
        $bookings->payment_provider = 'razorpay';
        $bookings->save();
    }

    private function staff(?string $role = null): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    /** A confirmed, paid booking with exactly one settled attempt — the only refundable shape. */
    private function seedCapturedBooking(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $type = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true]);

        $this->booking = Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => BookingPaymentStatus::Paid,
            'confirmed_at' => now(),
            'price' => 499,
            'currency' => 'INR',
            'payment_reference' => 'PAY-'.strtoupper(Str::random(12)),
        ]);

        $this->obligation = BookingPayment::factory()->captured()->create([
            'booking_id' => $this->booking->id,
            'user_id' => $student->id,
            'amount_minor' => 49900,
            'currency_code' => 'INR',
        ]);

        // The canonical ledger attempt: provider identity lives here,
        // never on the obligation.
        Payment::query()->create([
            'payable_type' => BookingPayment::PAYABLE_TYPE,
            'payable_id' => $this->obligation->id,
            'user_id' => $student->id,
            'provider' => 'razorpay',
            'provider_payment_id' => 'pay_'.strtoupper(Str::random(14)),
            'status' => PaymentStatus::Paid->value,
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'idempotency_key' => 'ATT-'.strtoupper(Str::random(12)),
            'paid_at' => now(),
        ]);
    }
}
