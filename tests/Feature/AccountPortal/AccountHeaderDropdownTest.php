<?php

declare(strict_types=1);

namespace Tests\Feature\AccountPortal;

use App\Models\Currency;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Services\WalletLedgerService;
use App\Wallet\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AccountHeaderDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );
    }

    public function test_student_header_dropdown_shows_real_balance_and_important_links(): void
    {
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->referral_enabled = true;
        $features->save();
        $student = $this->user('student');
        $wallet = app(WalletService::class)->getOrCreateWallet($student, 'INR');
        app(WalletLedgerService::class)->credit(
            $wallet,
            12345,
            WalletLedgerEntryType::PromotionalCredit,
            $student,
        );

        $response = $this->actingAs($student)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('aria-haspopup="menu"', false)
            ->assertSee('id="account-header-menu"', false)
            ->assertSee('Available wallet balance')
            ->assertSee('123.45 INR')
            ->assertSee('View wallet and statement')
            ->assertSee('Earn wallet credits')
            ->assertSee('Explore website')
            ->assertSee('My profile')
            ->assertSee('Sign out')
            ->assertSee('method="POST"', false)
            ->assertSee('action="'.route('auth.logout').'"', false);
    }

    public function test_student_without_wallet_gets_safe_wallet_entry_point_without_creating_one(): void
    {
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->save();
        $student = $this->user('student');

        $this->actingAs($student)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No wallet yet')
            ->assertSee(route('dashboard.wallet'), false);

        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_instructor_header_does_not_query_or_render_student_wallet_data(): void
    {
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = true;
        $features->save();
        $instructor = $this->user('instructor');
        $walletQueries = 0;
        DB::listen(static function ($query) use (&$walletQueries): void {
            if (str_contains($query->sql, 'from `wallets`')) {
                $walletQueries++;
            }
        });

        $response = $this->actingAs($instructor)->get(route('dashboard'));

        $response->assertOk()->assertDontSee('Available wallet balance');
        $this->assertSame(0, $walletQueries);
    }

    public function test_disabled_wallet_executes_no_wallet_query(): void
    {
        $features = app(FeatureSettings::class);
        $features->wallet_enabled = false;
        $features->save();
        $walletQueries = 0;
        DB::listen(static function ($query) use (&$walletQueries): void {
            if (str_contains($query->sql, 'from `wallets`')) {
                $walletQueries++;
            }
        });

        $this->actingAs($this->user('student'))->get(route('dashboard'))->assertOk();

        $this->assertSame(0, $walletQueries);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
