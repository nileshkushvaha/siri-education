<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Models\Currency;
use App\Models\User;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24M — GAP-031: wallet creation for NEW use requires an Active
 * currency; protective credits (refunds, reversals, held-reward
 * crediting) must remain payable even after the currency is disabled.
 */
final class WalletCurrencyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');

        $this->currency = Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );
    }

    // ── 13: new wallet creation rejects inactive currency ────────────

    public function test_new_wallet_creation_rejects_inactive_currency(): void
    {
        $this->currency->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);

        app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
    }

    // ── 14: existing wallet/history remains readable ─────────────────

    public function test_existing_wallet_and_history_remains_readable_after_deactivation(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        app(WalletLedgerService::class)->credit($wallet, 5000, WalletLedgerEntryType::AdminAdjustment, $this->student, description: 'Seed');

        $this->currency->update(['status' => 'inactive']);

        $statement = app(WalletLedgerService::class)->statement($wallet->fresh());

        $this->assertSame(1, $statement->total());
        $this->assertSame(5000, $wallet->fresh()->balance_minor);
    }

    // ── 15: valid refund credits an inactive-currency wallet ─────────

    public function test_protective_credit_credits_a_new_wallet_in_an_inactive_currency(): void
    {
        // The student has NO wallet yet — the original charge's currency
        // was later disabled; a protective credit (e.g. a refund) must
        // still be able to create the wallet it needs to land in.
        $this->currency->update(['status' => 'inactive']);

        $wallet = app(WalletService::class)->getOrCreateWalletForExistingObligation($this->student, 'INR');

        $this->assertSame('INR', $wallet->currency_code);

        $entry = app(WalletLedgerService::class)->credit($wallet, 4990, WalletLedgerEntryType::Refund, $this->student, description: 'Refund');

        $this->assertSame(4990, $wallet->fresh()->balance_minor);
        $this->assertSame(4990, $entry->amount_minor);
    }

    public function test_protective_credit_finds_an_existing_wallet_in_an_inactive_currency(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR'); // created while active
        $this->currency->update(['status' => 'inactive']);

        $resolved = app(WalletService::class)->getOrCreateWalletForExistingObligation($this->student, 'INR');

        $this->assertSame($wallet->id, $resolved->id);
    }

    // ── 16: reversal works in inactive currency ──────────────────────

    public function test_reversal_works_in_an_inactive_currency(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $entry = app(WalletLedgerService::class)->credit($wallet, 3000, WalletLedgerEntryType::AdminAdjustment, $this->student, description: 'Seed');

        $this->currency->update(['status' => 'inactive']);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo($this->managePermission());

        $reversal = app(WalletLedgerService::class)->reverse($entry, $admin, 'Correction after deactivation');

        $this->assertSame(0, $wallet->fresh()->balance_minor);
        $this->assertNotNull($reversal->id);
    }

    // ── 19: admin correction/reconciliation remains available ────────

    public function test_admin_adjustment_remains_available_in_an_inactive_currency(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->student, 'INR');
        $this->currency->update(['status' => 'inactive']);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo($this->managePermission());

        $entry = app(WalletLedgerService::class)->adjustment($wallet, 1000, WalletLedgerDirection::Credit, $admin, 'Manual correction');

        $this->assertSame(1000, $wallet->fresh()->balance_minor);
        $this->assertNotNull($entry->id);
    }

    private function managePermission(): Permission
    {
        return Permission::firstOrCreate(['name' => 'Manage:Wallet', 'guard_name' => 'web']);
    }
}
