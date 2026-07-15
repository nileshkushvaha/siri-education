<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CreateBookingData;
use App\Booking\Enums\Weekday;
use App\Filament\Resources\Wallets\Pages\ViewWallet;
use App\Filament\Resources\Wallets\WalletResource;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Currency;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\FeatureSettings;
use App\Settings\GeneralSettings;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;
use App\Wallet\Exceptions\InsufficientBalanceException;
use App\Wallet\Exceptions\WalletException;
use App\Wallet\Exceptions\WalletNotUsableException;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WalletLedgerFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Currency $inr;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');

        $this->inr = Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );

        $this->enableWalletFeature();
    }

    private function enableWalletFeature(): void
    {
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->save();
    }

    private function permittedManager(array $permissions): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->assignRole('manager');

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $manager->givePermissionTo($permissions);

        return $manager;
    }

    // ── Wallet creation ──────────────────────────────────────────────────

    public function test_wallet_can_be_created_for_user_and_active_currency(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');

        $this->assertDatabaseHas('wallets', [
            'id' => $wallet->id,
            'user_id' => $this->student->id,
            'currency_code' => 'INR',
            'status' => 'active',
        ]);
    }

    public function test_duplicate_wallet_per_user_currency_is_prevented(): void
    {
        $first = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $second = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Wallet::query()->forUser($this->student->id)->count());
    }

    public function test_inactive_currency_rejected(): void
    {
        Currency::query()->create(['code' => 'XXX', 'name' => 'Inactive Currency', 'minor_units' => 2, 'status' => 'inactive']);

        $this->expectException(ValidationException::class);
        app(WalletService::class)->getOrCreateWallet($this->student, 'XXX');
    }

    public function test_default_currency_resolution_works(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student);

        $this->assertSame(app(GeneralSettings::class)->default_currency, $wallet->currency_code);
    }

    // ── Ledger ───────────────────────────────────────────────────────────

    public function test_credit_increases_balance(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');

        $entry = app(WalletLedgerService::class)->credit($wallet, 10000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $wallet->refresh();
        $this->assertSame(10000, $wallet->balance_minor);
        $this->assertSame(10000, $wallet->available_balance_minor);
        $this->assertSame(WalletLedgerDirection::Credit, $entry->direction);
    }

    public function test_debit_decreases_available_balance(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 10000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        app(WalletLedgerService::class)->debit($wallet->refresh(), 4000, WalletLedgerEntryType::BookingPayment, $this->student);

        $wallet->refresh();
        $this->assertSame(6000, $wallet->balance_minor);
        $this->assertSame(6000, $wallet->available_balance_minor);
    }

    public function test_debit_fails_on_insufficient_balance(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');

        $this->expectException(InsufficientBalanceException::class);
        app(WalletLedgerService::class)->debit($wallet, 100, WalletLedgerEntryType::BookingPayment, $this->student);
    }

    public function test_posted_ledger_entry_stores_balance_after_values(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');

        $entry = app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $this->assertSame(WalletLedgerStatus::Posted, $entry->status);
        $this->assertSame(5000, $entry->balance_after_minor);
        $this->assertSame(5000, $entry->available_after_minor);
        $this->assertSame(0, $entry->held_after_minor);
        $this->assertNotNull($entry->posted_at);
    }

    public function test_idempotency_key_prevents_duplicate_entry(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $ledger = app(WalletLedgerService::class);

        $first = $ledger->credit($wallet, 2000, WalletLedgerEntryType::PromotionalCredit, $this->student, idempotencyKey: 'promo-2026-01');
        $second = $ledger->credit($wallet, 2000, WalletLedgerEntryType::PromotionalCredit, $this->student, idempotencyKey: 'promo-2026-01');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WalletLedgerEntry::where('idempotency_key', 'promo-2026-01')->count());
        $this->assertSame(2000, $wallet->refresh()->balance_minor);
    }

    public function test_debit_idempotency_key_prevents_duplicate_entry(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $ledger = app(WalletLedgerService::class);
        $ledger->credit($wallet, 10000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $first = $ledger->debit($wallet->refresh(), 3000, WalletLedgerEntryType::BookingPayment, $this->student, idempotencyKey: 'booking-payment-xyz');
        $second = $ledger->debit($wallet->refresh(), 3000, WalletLedgerEntryType::BookingPayment, $this->student, idempotencyKey: 'booking-payment-xyz');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WalletLedgerEntry::where('idempotency_key', 'booking-payment-xyz')->count());
        $this->assertSame(7000, $wallet->refresh()->balance_minor);
    }

    public function test_reversal_works_safely(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);
        $entry = app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $reversal = app(WalletLedgerService::class)->reverse($entry, $admin, 'Granted in error');

        $this->assertSame(WalletLedgerDirection::Debit, $reversal->direction);
        $this->assertSame(0, $wallet->refresh()->balance_minor);
        $this->assertSame(WalletLedgerStatus::Reversed, $entry->refresh()->status);
        $this->assertNotNull($entry->reversed_at);
    }

    public function test_reversal_does_not_mutate_original_entry_amount(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);
        $entry = app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        app(WalletLedgerService::class)->reverse($entry, $admin, 'Granted in error');

        $entry->refresh();
        $this->assertSame(5000, $entry->amount_minor);
        $this->assertSame(WalletLedgerDirection::Credit, $entry->direction);
        $this->assertSame(2, WalletLedgerEntry::forWallet($wallet->id)->count());
    }

    /**
     * Deliberate: reversal is an administrative correction, not a new
     * transaction, so it is allowed regardless of wallet status — see
     * WalletLedgerService::reverse()'s docblock. Already gated behind
     * `Manage:Wallet`, so this is never student-reachable.
     */
    public function test_reversal_succeeds_on_frozen_wallet(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);
        $entry = app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);
        app(WalletService::class)->freeze($wallet->refresh(), $admin, 'Under review');

        $reversal = app(WalletLedgerService::class)->reverse($entry, $admin, 'Granted in error, correcting despite freeze');

        $this->assertSame(WalletLedgerDirection::Debit, $reversal->direction);
        $this->assertSame(0, $wallet->refresh()->balance_minor);
    }

    public function test_reversal_succeeds_on_closed_wallet(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);
        $entry = app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);
        app(WalletService::class)->close($wallet->refresh(), $admin, 'Account closed');

        $reversal = app(WalletLedgerService::class)->reverse($entry, $admin, 'Granted in error, correcting despite closure');

        $this->assertSame(WalletLedgerDirection::Debit, $reversal->direction);
        $this->assertSame(0, $wallet->refresh()->balance_minor);
    }

    public function test_wallet_balance_cannot_be_changed_without_ledger_service(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');

        $this->expectException(WalletException::class);
        $wallet->update(['balance_minor' => 999999]);
    }

    // ── Currency / minor units ──────────────────────────────────────────

    public function test_wallet_uses_integer_minor_units(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $entry = app(WalletLedgerService::class)->credit($wallet, 12345, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $this->assertIsInt($wallet->refresh()->balance_minor);
        $this->assertIsInt($entry->amount_minor);
        $this->assertIsInt($entry->balance_after_minor);
    }

    public function test_money_parameters_are_strictly_typed_int_not_float(): void
    {
        foreach (['credit', 'debit', 'placeHold', 'releaseHold', 'adjustment'] as $method) {
            $reflection = new ReflectionMethod(WalletLedgerService::class, $method);
            $amountParam = collect($reflection->getParameters())->firstWhere('name', 'amountMinor');

            $this->assertNotNull($amountParam, "{$method} must declare \$amountMinor");
            $this->assertSame('int', (string) $amountParam->getType(), "{$method}'s \$amountMinor must be strictly typed int, not float.");
        }
    }

    public function test_two_currencies_for_same_user_are_isolated_wallets(): void
    {
        $usd = Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'minor_units' => 2, 'status' => 'active']);

        $inrWallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $usdWallet = app(WalletService::class)->getOrCreateWallet($this->student, 'USD');

        $this->assertNotSame($inrWallet->id, $usdWallet->id);

        app(WalletLedgerService::class)->credit($inrWallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $this->assertSame(5000, $inrWallet->refresh()->balance_minor);
        $this->assertSame(0, $usdWallet->refresh()->balance_minor);
        $this->assertSame(2, Wallet::query()->forUser($this->student->id)->count());
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function test_permitted_admin_can_view_wallet(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['ViewAny:Wallet', 'View:Wallet']);

        Livewire::actingAs($admin)
            ->test(ViewWallet::class, ['record' => $wallet->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_non_permitted_admin_cannot_manage_wallet(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['ViewAny:Wallet', 'View:Wallet']);

        Livewire::actingAs($admin)
            ->test(ViewWallet::class, ['record' => $wallet->getRouteKey()])
            ->assertActionHidden('freeze')
            ->assertActionHidden('adjustment');

        $this->expectException(AuthorizationException::class);
        app(WalletLedgerService::class)->adjustment($wallet, 1000, WalletLedgerDirection::Credit, $admin, 'Test');
    }

    public function test_admin_adjustment_requires_reason(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);

        $this->expectException(WalletException::class);
        app(WalletLedgerService::class)->adjustment($wallet, 1000, WalletLedgerDirection::Credit, $admin, '   ');
    }

    public function test_admin_adjustment_logs_activity(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);

        app(WalletLedgerService::class)->adjustment($wallet, 1500, WalletLedgerDirection::Credit, $admin, 'Goodwill credit');

        $this->assertTrue(
            Activity::where('log_name', 'wallet_ledger_entries')
                ->where('event', 'admin_adjustment')
                ->where('causer_id', $admin->id)
                ->exists(),
        );
    }

    public function test_wallet_resource_has_no_create_or_edit_page(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['ViewAny:Wallet', 'View:Wallet']);

        $this->assertFalse(WalletResource::canCreate());
        $this->assertFalse(WalletResource::canEdit($wallet));

        $this->actingAs($admin)->get('/admin/wallets/create')->assertNotFound();
        $this->actingAs($admin)->get("/admin/wallets/{$wallet->id}/edit")->assertNotFound();
    }

    // ── Student UI ───────────────────────────────────────────────────────

    public function test_student_can_view_own_wallet_page(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee('Wallet');
    }

    public function test_wallet_page_returns_404_when_feature_disabled(): void
    {
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = false;
        $features->save();

        $this->actingAs($this->student)->get(route('dashboard.wallet'))->assertNotFound();
    }

    public function test_student_cannot_view_another_users_wallet_via_policy(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherStudent->assignRole('student');

        $this->assertTrue($this->student->can('view', $wallet));
        $this->assertFalse($otherStudent->can('view', $wallet));
    }

    public function test_wallet_statement_shows_own_entries(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 3000, WalletLedgerEntryType::PromotionalCredit, $this->student, description: 'Welcome bonus');

        $this->actingAs($this->student)
            ->get(route('dashboard.wallet'))
            ->assertOk()
            ->assertSee('Welcome bonus')
            ->assertSee('Promotional Credit');
    }

    public function test_recharge_cta_does_not_create_razorpay_order(): void
    {
        $response = $this->actingAs($this->student)->get(route('dashboard.wallet'))->assertOk();

        $response->assertSee('Coming soon');
        $this->assertFalse(Schema::hasTable('razorpay_orders'));
    }

    // ── Boundary ─────────────────────────────────────────────────────────

    public function test_wallet_credit_creates_no_razorpay_or_payment_record(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        foreach (['razorpay_orders', 'payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found.");
        }
    }

    public function test_wallet_debit_creates_no_booking_payment_capture(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        app(WalletLedgerService::class)->debit($wallet->refresh(), 2000, WalletLedgerEntryType::BookingPayment, $this->student);

        $this->assertSame(0, Booking::count());
    }

    public function test_booking_creation_does_not_debit_wallet(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 10000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $teacher = $this->makeTeacher();

        app(BookingServiceInterface::class)->request(new CreateBookingData(
            typeKey: 'free_demo',
            studentId: $this->student->id,
            instructorId: $teacher->id,
            startsAt: CarbonImmutable::now('UTC')->addDays(3)->setTime(9, 0),
            durationMinutes: 30,
        ));

        $this->assertSame(10000, $wallet->refresh()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::forWallet($wallet->id)->count());
    }

    public function test_no_meeting_created_from_wallet_action(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $this->assertFalse(Schema::hasTable('meetings'));
    }

    public function test_no_instructor_payout_table_exists(): void
    {
        foreach (['instructor_payouts', 'payouts', 'earnings'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found.");
        }
    }

    public function test_no_duplicate_payment_razorpay_wallet_tables_beyond_foundation(): void
    {
        $this->assertTrue(Schema::hasTable('wallets'));
        $this->assertTrue(Schema::hasTable('wallet_ledger_entries'));

        foreach ([
            'wallet_holds', 'wallet_transactions', 'payments', 'payment_transactions',
            'razorpay_orders', 'razorpay_payments', 'instructor_payouts', 'meetings',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] found — only the approved wallet foundation should exist.");
        }
    }

    // ── Frozen / closed wallet rules ────────────────────────────────────

    public function test_frozen_wallet_cannot_be_debited_but_can_still_be_credited(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::PromotionalCredit, $this->student);

        $admin = $this->permittedManager(['Manage:Wallet']);
        app(WalletService::class)->freeze($wallet->refresh(), $admin, 'Under review');

        $this->expectException(WalletNotUsableException::class);
        app(WalletLedgerService::class)->debit($wallet->refresh(), 1000, WalletLedgerEntryType::BookingPayment, $this->student);
    }

    public function test_frozen_wallet_credit_still_succeeds(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);
        app(WalletService::class)->freeze($wallet, $admin, 'Under review');

        $entry = app(WalletLedgerService::class)->credit($wallet->refresh(), 2000, WalletLedgerEntryType::Refund, $this->student);

        $this->assertSame(2000, $entry->balance_after_minor);
    }

    public function test_closed_wallet_cannot_be_used(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $admin = $this->permittedManager(['Manage:Wallet']);
        app(WalletService::class)->close($wallet, $admin, 'Account closed');

        $this->expectException(WalletNotUsableException::class);
        app(WalletLedgerService::class)->credit($wallet->refresh(), 1000, WalletLedgerEntryType::PromotionalCredit, $this->student);
    }

    private function makeTeacher(): User
    {
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $teacher->id])->subject('maths', 1, 12)->create();

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $teacher->id])->forDay($day)->between('09:00:00', '11:00:00')->create();
        }

        BookingType::query()->firstOrCreate(
            ['key' => 'free_demo'],
            ['name' => 'Free Demo', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'is_active' => true],
        );

        return $teacher;
    }
}
