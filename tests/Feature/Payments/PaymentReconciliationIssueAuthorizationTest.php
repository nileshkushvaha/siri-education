<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Filament\Resources\PaymentReconciliationIssues\Pages\ListPaymentReconciliationIssues;
use App\Models\Payment;
use App\Models\PaymentReconciliationIssue;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Payments\Enums\PaymentReconciliationIssueStatus;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Payments\Enums\PaymentStatus;
use Database\Seeders\PaymentReconciliationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
