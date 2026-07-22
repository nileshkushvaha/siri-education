<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Services\Payment\InvoiceService;
use App\Settings\FeatureSettings;
use App\Settings\GeneralSettings;
use App\Wallet\Contracts\WalletRechargeServiceInterface;
use App\Wallet\DTOs\WalletRechargeProviderEvent;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletRechargeProviderEventType;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §14.21-14.24 (GAP-007): an invoice/receipt is generated
 * automatically for every successful booking payment (gateway or
 * wallet) and every successful wallet recharge, is uniquely numbered,
 * is fully immutable, and is visible only to its owner or an
 * authorized administrator.
 */
class InvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;

    private BookingType $paidType;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        app(FeatureSettings::class)->wallet_enabled = true;

        app(GeneralSettings::class)->organization_name = 'SIRI Education';
        app(GeneralSettings::class)->save();

        $this->paidType = BookingType::factory()->create(['key' => 'paid_one_to_one', 'is_paid' => true]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function student(?Country $country = null): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        if ($country !== null) {
            $student->profile()->update(['country_id' => $country->id]);
        }

        return $student;
    }

    private function pendingBooking(User $student, int $priceMinor = 49900): Booking
    {
        $startsAt = CarbonImmutable::now('UTC')->addDays(3);

        return Booking::factory()->create([
            'student_id' => $student->id,
            'booking_type_id' => $this->paidType->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'price' => $priceMinor / 100,
            'currency' => 'INR',
            'reserved_until' => CarbonImmutable::now('UTC')->addMinutes(15),
        ]);
    }

    private function fundWallet(User $student, int $amountMinor): Wallet
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR', $student);
        app(WalletLedgerService::class)->credit($wallet, $amountMinor, WalletLedgerEntryType::PromotionalCredit, $student);

        return $wallet->fresh();
    }

    private function succeededRecharge(User $student, int $amountMinor = 50000): WalletRecharge
    {
        $service = app(WalletRechargeServiceInterface::class);
        $checkout = $service->initiate($student, $amountMinor);
        $recharge = WalletRecharge::query()->where('idempotency_key', $checkout->reference)->sole();

        $service->processProviderEvent(new WalletRechargeProviderEvent(
            provider: $recharge->provider,
            reference: $recharge->idempotency_key,
            providerOrderId: $recharge->provider_order_id,
            providerPaymentId: 'fake_payment_'.$recharge->id,
            amountMinor: $recharge->amount_minor,
            currencyCode: $recharge->currency_code,
            type: WalletRechargeProviderEventType::Captured,
        ));

        return $recharge->fresh();
    }

    private function admin(): User
    {
        Permission::firstOrCreate(['name' => 'ViewAny:Invoice', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'View:Invoice', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('manager');
        $admin->givePermissionTo(['ViewAny:Invoice', 'View:Invoice']);

        return $admin;
    }

    // ── Generation: happy paths ──────────────────────────────────────

    public function test_a_wallet_booking_payment_generates_an_invoice(): void
    {
        $student = $this->student();
        $this->fundWallet($student, 100000);
        $booking = $this->pendingBooking($student);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $invoice = Invoice::query()->where('user_id', $student->id)->sole();
        $this->assertSame(BookingPayment::class, $invoice->source_type);
        $this->assertSame($booking->reference, $invoice->booking_reference);
        $this->assertNull($invoice->wallet_recharge_reference);
    }

    public function test_a_wallet_recharge_generates_an_invoice(): void
    {
        $student = $this->student();
        $recharge = $this->succeededRecharge($student, 75000);

        $invoice = Invoice::query()->where('user_id', $student->id)->sole();
        $this->assertSame(WalletRecharge::class, $invoice->source_type);
        $this->assertSame($recharge->idempotency_key, $invoice->wallet_recharge_reference);
        $this->assertNull($invoice->booking_reference);
        $this->assertSame(75000, $invoice->amount_minor);
    }

    public function test_an_unsuccessful_source_never_generates_an_invoice(): void
    {
        $student = $this->student();
        $booking = $this->pendingBooking($student);
        $payment = BookingPayment::factory()->create([
            'booking_id' => $booking->id,
            'user_id' => $student->id,
            'status' => BookingPaymentRecordStatus::Pending,
        ]);

        $this->expectException(RuntimeException::class);
        app(InvoiceService::class)->generateForBookingPayment($payment);

        $this->assertSame(0, Invoice::query()->count());
    }

    // ── Snapshot correctness ──────────────────────────────────────────

    public function test_invoice_snapshots_student_billing_financial_and_platform_fields(): void
    {
        $country = Country::factory()->create(['name' => 'Testland']);
        $student = $this->student($country);
        $this->fundWallet($student, 100000);
        $booking = $this->pendingBooking($student, priceMinor: 49900);

        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $invoice = Invoice::query()->where('user_id', $student->id)->sole();
        $this->assertSame($student->name, $invoice->student_name);
        $this->assertSame('Testland', $invoice->billing_country);
        $this->assertSame(49900, $invoice->amount_minor);
        $this->assertSame('INR', $invoice->currency_code);
        $this->assertNotNull($invoice->payment_date);
        $this->assertNotEmpty($invoice->payment_reference);
        $this->assertStringContainsString($booking->reference, $invoice->service_description);
        $this->assertSame('SIRI Education', $invoice->organization_name);
    }

    public function test_later_profile_country_and_settings_changes_never_alter_the_historical_invoice(): void
    {
        $original = Country::factory()->create(['name' => 'OriginalCountry']);
        $student = $this->student($original);
        $recharge = $this->succeededRecharge($student, 50000);
        $invoice = Invoice::query()->where('user_id', $student->id)->sole();

        $changed = Country::factory()->create(['name' => 'ChangedCountry']);
        $student->update(['name' => 'Renamed Student']);
        $student->profile()->update(['country_id' => $changed->id]);
        app(GeneralSettings::class)->organization_name = 'A Different Org';
        app(GeneralSettings::class)->save();

        $invoice->refresh();
        $this->assertSame('OriginalCountry', $invoice->billing_country);
        $this->assertNotSame('Renamed Student', $invoice->student_name);
        $this->assertSame('SIRI Education', $invoice->organization_name);
    }

    // ── Idempotency and concurrency ───────────────────────────────────

    public function test_duplicate_generation_for_the_same_source_produces_exactly_one_invoice(): void
    {
        $student = $this->student();
        $this->fundWallet($student, 100000);
        $booking = $this->pendingBooking($student);
        app(BookingPaymentServiceInterface::class)->payWithWallet($booking, $student);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->sole();
        $first = app(InvoiceService::class)->generateForBookingPayment($payment);
        $second = app(InvoiceService::class)->generateForBookingPayment($payment);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::query()->where('source_type', BookingPayment::class)->where('source_id', $payment->id)->count());
    }

    public function test_two_different_sources_receive_unique_sequential_invoice_numbers(): void
    {
        $studentA = $this->student();
        $studentB = $this->student();
        $this->succeededRecharge($studentA, 10000);
        $this->succeededRecharge($studentB, 20000);

        $numbers = Invoice::query()->orderBy('created_at')->pluck('invoice_number')->all();
        $this->assertCount(2, array_unique($numbers));
        $this->assertStringEndsWith('000001', $numbers[0]);
        $this->assertStringEndsWith('000002', $numbers[1]);
    }

    // ── Immutability ──────────────────────────────────────────────────

    public function test_an_invoice_cannot_be_updated_after_creation(): void
    {
        $student = $this->student();
        $this->succeededRecharge($student, 10000);
        $invoice = Invoice::query()->where('user_id', $student->id)->sole();

        $this->expectException(ImmutableRecordCannotBeUpdatedException::class);
        $invoice->forceFill(['student_name' => 'Tampered'])->save();
    }

    public function test_an_invoice_cannot_be_deleted(): void
    {
        $student = $this->student();
        $this->succeededRecharge($student, 10000);
        $invoice = Invoice::query()->where('user_id', $student->id)->sole();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $invoice->delete();
    }

    // ── Authorization and privacy ─────────────────────────────────────

    public function test_owner_can_view_and_download_their_own_invoice(): void
    {
        $student = $this->student();
        $this->succeededRecharge($student, 10000);
        $invoice = Invoice::query()->where('user_id', $student->id)->sole();

        $this->actingAs($student)
            ->get(route('dashboard.invoices.download', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_unrelated_student_cannot_view_or_download_another_students_invoice(): void
    {
        $student = $this->student();
        $this->succeededRecharge($student, 10000);
        $invoice = Invoice::query()->where('user_id', $student->id)->sole();

        $otherStudent = $this->student();

        $this->actingAs($otherStudent)
            ->get(route('dashboard.invoices.download', $invoice))
            ->assertForbidden();
    }

    public function test_authorized_administrator_can_view_the_admin_invoice_resource(): void
    {
        $student = $this->student();
        $this->succeededRecharge($student, 10000);
        $invoice = Invoice::query()->where('user_id', $student->id)->sole();
        $admin = $this->admin();

        $this->assertTrue($admin->can('view', $invoice));

        $this->actingAs($admin)
            ->get('/admin/invoices/'.$invoice->id)
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_access_the_admin_invoice_resource(): void
    {
        $student = $this->student();
        $this->succeededRecharge($student, 10000);

        $this->actingAs($student)
            ->get('/admin/invoices')
            ->assertForbidden();
    }

    public function test_no_admin_create_or_edit_route_exists_for_invoices(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.invoices.create'));
        $this->assertFalse(Route::has('filament.admin.resources.invoices.edit'));
    }

    public function test_rendered_invoice_document_never_leaks_another_students_data(): void
    {
        $studentA = $this->student();
        $studentB = $this->student();
        $this->succeededRecharge($studentA, 10000);
        $this->succeededRecharge($studentB, 20000);

        $invoiceA = Invoice::query()->where('user_id', $studentA->id)->sole();
        $invoiceB = Invoice::query()->where('user_id', $studentB->id)->sole();

        $renderedA = view('invoices.pdf', ['invoice' => $invoiceA])->render();

        $this->assertStringContainsString($invoiceA->invoice_number, $renderedA);
        $this->assertStringNotContainsString($invoiceB->invoice_number, $renderedA);
        $this->assertStringNotContainsString($studentB->name, $renderedA);
    }
}
