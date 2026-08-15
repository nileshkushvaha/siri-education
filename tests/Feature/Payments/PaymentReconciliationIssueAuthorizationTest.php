<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Booking\Enums\BookingPaymentReconciliationIssueStatus;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use App\Filament\Resources\BookingPaymentReconciliationIssues\BookingPaymentReconciliationIssueResource;
use App\Filament\Resources\PaymentReconciliationIssues\Pages\ListPaymentReconciliationIssues;
use App\Filament\Resources\PaymentReconciliationIssues\PaymentReconciliationIssueResource;
use App\Models\BookingPayment;
use App\Models\BookingPaymentReconciliationIssue;
use App\Models\Payment;
use App\Models\PaymentReconciliationIssue;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Payments\Enums\PaymentReconciliationIssueStatus;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use Database\Seeders\BookingPaymentReconciliationPermissionSeeder;
use Database\Seeders\PaymentReconciliationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4E.2 — who may see and touch the payment discrepancy queue.
 *
 * The queue exposes what the platform believed it was owed against what
 * a provider claimed to collect. That is internal financial operations:
 * a student must not see another party's collection problem, and an
 * instructor has no role in gateway reconciliation at all.
 *
 * The most important assertions here are NEGATIVE — that no permission,
 * action, or policy ability exists anywhere that could settle a payment
 * by hand.
 */
class PaymentReconciliationIssueAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PaymentReconciliationPermissionSeeder::class);

        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function issue(): PaymentReconciliationIssue
    {
        Schema::disableForeignKeyConstraints();

        $payment = Payment::query()->create([
            'payable_type' => StudentPackagePurchase::PAYABLE_TYPE,
            'payable_id' => (string) Str::uuid(),
            'provider' => 'razorpay',
            'amount_minor' => 49900,
            'currency_code' => 'INR',
            'status' => PaymentStatus::Pending,
            'idempotency_key' => 'PAY-'.strtoupper(Str::random(16)),
        ]);

        $issue = PaymentReconciliationIssue::query()->create([
            'payment_id' => $payment->id,
            'provider' => 'razorpay',
            'issue_type' => PaymentReconciliationIssueType::AmountMismatch,
            'status' => PaymentReconciliationIssueStatus::Open,
            'expected_amount_minor' => 49900,
            'observed_amount_minor' => 99900,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Schema::enableForeignKeyConstraints();

        return $issue;
    }

    // ── 31-33. Visibility ─────────────────────────────────────────────────

    public function test_a_manager_may_view_the_queue(): void
    {
        $issue = $this->issue();
        $manager = $this->user('manager');

        $this->assertTrue($manager->can('viewAny', PaymentReconciliationIssue::class));
        $this->assertTrue($manager->can('view', $issue));

        $this->actingAs($manager);
        Livewire::test(ListPaymentReconciliationIssues::class)->assertOk();
    }

    public function test_an_instructor_may_not_view_the_queue(): void
    {
        $issue = $this->issue();
        $instructor = $this->user('instructor');

        $this->assertFalse($instructor->can('viewAny', PaymentReconciliationIssue::class));
        $this->assertFalse($instructor->can('view', $issue));
        $this->assertFalse($instructor->can('resolve', $issue));
    }

    public function test_a_student_may_not_view_the_queue(): void
    {
        $issue = $this->issue();
        $student = $this->user('student');

        $this->assertFalse($student->can('viewAny', PaymentReconciliationIssue::class));
        $this->assertFalse($student->can('view', $issue));
        $this->assertFalse($student->can('resolve', $issue));
    }

    // ── 34-35. No mutation ────────────────────────────────────────────────

    public function test_nobody_may_create_update_or_delete_an_issue(): void
    {
        $issue = $this->issue();

        foreach (['manager', 'instructor', 'student'] as $role) {
            $user = $this->user($role);

            $this->assertFalse($user->can('create', PaymentReconciliationIssue::class), $role);
            $this->assertFalse($user->can('update', $issue), $role);
            $this->assertFalse($user->can('delete', $issue), $role);
        }
    }

    public function test_no_permission_exists_that_could_settle_a_payment_by_hand(): void
    {
        // The absence of these is the entire safety argument for having
        // an operator queue at all: an operator may record that a
        // discrepancy was handled, never assert that money arrived.
        $dangerous = Permission::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', '%MarkPaid%')
                ->orWhere('name', 'like', 'Update:Payment%')
                ->orWhere('name', 'like', '%Settle:Payment%'))
            ->pluck('name');

        $this->assertTrue($dangerous->isEmpty(), 'Unexpected payment-settling permissions: '.$dangerous->implode(', '));
    }

    public function test_the_seeder_grants_exactly_three_abilities_and_only_to_managers(): void
    {
        $expected = [
            'ViewAny:PaymentReconciliationIssue',
            'View:PaymentReconciliationIssue',
            'Resolve:PaymentReconciliationIssue',
        ];

        foreach ($expected as $permission) {
            $this->assertTrue(
                Role::findByName('manager', 'web')->hasPermissionTo($permission),
                sprintf('Manager should hold %s.', $permission),
            );

            foreach (['instructor', 'student'] as $role) {
                $this->assertFalse(
                    Role::findByName($role, 'web')->hasPermissionTo($permission),
                    sprintf('%s must not hold %s.', $role, $permission),
                );
            }
        }

        $this->assertSame(
            count($expected),
            Permission::query()->where('name', 'like', '%:PaymentReconciliationIssue')->count(),
        );
    }

    public function test_a_resolved_issue_can_no_longer_be_resolved(): void
    {
        $issue = $this->issue();
        $issue->fill(['status' => PaymentReconciliationIssueStatus::Resolved, 'resolved_at' => now()])->save();

        // Guards the Filament action's visibility as well as the policy.
        $this->assertFalse($this->user('manager')->can('resolve', $issue->refresh()));
    }

    // ── PAY-3 · four-way permission matrix ──────────────────────────────
    //
    // The two reconciliation queues now share one navigation home
    // (Finance -> Billing & Payments). Sharing a heading must not share
    // authorization: each queue keeps its own permission, and a common
    // parent must never become a backdoor into the other domain.

    /** A user holding exactly the listed permissions and nothing else. */
    private function operatorWith(string ...$permissions): User
    {
        $this->seed(BookingPaymentReconciliationPermissionSeeder::class);

        // PortalResolver grants admin-panel access only to super_admin or
        // manager, so the operator must BE a manager to reach a URL at
        // all — otherwise a deep-link test would pass for the wrong
        // reason (panel denial, not resource authorization).
        //
        // The shared seeders also grant manager both reconciliation
        // permissions, which would make every assertion below vacuous, so
        // they are stripped from the role and re-granted per-user. Scoped
        // to this test class by RefreshDatabase.
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->revokePermissionTo(array_filter([
            Permission::where('name', 'ViewAny:BookingPaymentReconciliationIssue')->first(),
            Permission::where('name', 'ViewAny:PaymentReconciliationIssue')->first(),
        ]));

        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->syncRoles([$manager]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function bookingIssue(): BookingPaymentReconciliationIssue
    {
        Schema::disableForeignKeyConstraints();

        $payment = BookingPayment::factory()->create([
            'provider' => 'razorpay',
            'amount_minor' => 4900,
            'currency_code' => 'INR',
        ]);

        $issue = BookingPaymentReconciliationIssue::query()->create([
            'reference' => 'BPRI-'.strtoupper(Str::random(8)),
            'booking_payment_id' => $payment->id,
            'provider' => 'razorpay',
            'type' => BookingPaymentReconciliationIssueType::ProviderUnavailable,
            'severity' => BookingPaymentReconciliationSeverity::Warning,
            'local_status' => 'pending',
            'amount_minor' => 4900,
            'currency_code' => 'INR',
            'safe_summary' => 'Provider unreachable.',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);
        $issue->forceFill(['status' => BookingPaymentReconciliationIssueStatus::Open])->save();

        Schema::enableForeignKeyConstraints();

        return $issue;
    }

    public function test_booking_only_operator_reaches_the_booking_queue_and_not_the_package_queue(): void
    {
        $this->bookingIssue();
        $this->issue();
        $operator = $this->operatorWith('ViewAny:BookingPaymentReconciliationIssue');

        $this->actingAs($operator);

        $this->assertTrue(BookingPaymentReconciliationIssueResource::canViewAny());
        $this->assertFalse(PaymentReconciliationIssueResource::canViewAny());

        // Badge follows the same boundary — one count, not two.
        $this->assertNotNull(BookingPaymentReconciliationIssueResource::getNavigationBadge());
        $this->assertNull(PaymentReconciliationIssueResource::getNavigationBadge());
    }

    public function test_package_only_operator_reaches_the_package_queue_and_not_the_booking_queue(): void
    {
        $this->bookingIssue();
        $this->issue();
        $operator = $this->operatorWith('ViewAny:PaymentReconciliationIssue');

        $this->actingAs($operator);

        $this->assertTrue(PaymentReconciliationIssueResource::canViewAny());
        $this->assertFalse(BookingPaymentReconciliationIssueResource::canViewAny());

        $this->assertNotNull(PaymentReconciliationIssueResource::getNavigationBadge());
        $this->assertNull(BookingPaymentReconciliationIssueResource::getNavigationBadge());
    }

    public function test_an_operator_holding_both_permissions_reaches_both_queues(): void
    {
        $this->bookingIssue();
        $this->issue();
        $operator = $this->operatorWith(
            'ViewAny:BookingPaymentReconciliationIssue',
            'ViewAny:PaymentReconciliationIssue',
        );

        $this->actingAs($operator);

        $this->assertTrue(BookingPaymentReconciliationIssueResource::canViewAny());
        $this->assertTrue(PaymentReconciliationIssueResource::canViewAny());
        $this->assertNotNull(BookingPaymentReconciliationIssueResource::getNavigationBadge());
        $this->assertNotNull(PaymentReconciliationIssueResource::getNavigationBadge());
    }

    public function test_an_operator_holding_neither_permission_reaches_neither_queue(): void
    {
        $this->bookingIssue();
        $this->issue();
        $operator = $this->operatorWith();

        $this->actingAs($operator);

        $this->assertFalse(BookingPaymentReconciliationIssueResource::canViewAny());
        $this->assertFalse(PaymentReconciliationIssueResource::canViewAny());
        $this->assertNull(BookingPaymentReconciliationIssueResource::getNavigationBadge());
        $this->assertNull(PaymentReconciliationIssueResource::getNavigationBadge());
    }

    // ── Deep links: hiding navigation is not a boundary ─────────────────

    public function test_a_package_only_operator_is_denied_the_booking_queue_url(): void
    {
        $this->actingAs($this->operatorWith('ViewAny:PaymentReconciliationIssue'));

        $this->get(BookingPaymentReconciliationIssueResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_booking_only_operator_is_denied_the_package_queue_url(): void
    {
        $this->actingAs($this->operatorWith('ViewAny:BookingPaymentReconciliationIssue'));

        $this->get(PaymentReconciliationIssueResource::getUrl('index'))->assertForbidden();
    }

    public function test_an_operator_with_neither_permission_is_denied_both_urls(): void
    {
        $this->actingAs($this->operatorWith());

        $this->get(BookingPaymentReconciliationIssueResource::getUrl('index'))->assertForbidden();
        $this->get(PaymentReconciliationIssueResource::getUrl('index'))->assertForbidden();
    }

    // ── Leakage: the unauthorized table must never be READ ──────────────

    public function test_an_unauthorized_badge_executes_no_query_against_the_restricted_table(): void
    {
        // "Badge is null" is not enough. If the count still ran, the row
        // count of a queue this operator cannot open has already been
        // read out of the database — the leak is the query, not the
        // rendering. Authorization must short-circuit first.
        $this->bookingIssue();
        $this->issue();

        $this->actingAs($this->operatorWith('ViewAny:BookingPaymentReconciliationIssue'));

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        PaymentReconciliationIssueResource::getNavigationBadge();

        $touched = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'payment_reconciliation_issues'));

        $this->assertSame([], array_values($touched), 'The restricted queue was queried for an operator who cannot view it.');
    }

    public function test_the_reverse_direction_also_executes_no_restricted_query(): void
    {
        $this->bookingIssue();
        $this->issue();

        $this->actingAs($this->operatorWith('ViewAny:PaymentReconciliationIssue'));

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        BookingPaymentReconciliationIssueResource::getNavigationBadge();

        $touched = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'booking_payment_reconciliation_issues'));

        $this->assertSame([], array_values($touched), 'The restricted queue was queried for an operator who cannot view it.');
    }

    public function test_the_booking_badge_counts_only_live_issue_types(): void
    {
        // A dormant historical row must not inflate today's work: the
        // queue's own filters cannot reproduce it, so counting it would
        // produce a badge number the operator can never account for.
        $issue = $this->bookingIssue();
        $issue->forceFill(['type' => BookingPaymentReconciliationIssueType::RefundStatusMismatch])->save();

        $this->actingAs($this->operatorWith('ViewAny:BookingPaymentReconciliationIssue'));

        $this->assertNull(BookingPaymentReconciliationIssueResource::getNavigationBadge());
    }
}
