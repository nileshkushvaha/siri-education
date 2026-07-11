<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\SettlementBatchStatus;
use App\Earnings\Exceptions\EarningException;
use App\Earnings\Exceptions\InvalidEarningTransitionException;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstructorSettlementTest extends TestCase
{
    use RefreshDatabase;

    private InstructorEarningServiceInterface $earnings;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->earnings = app(InstructorEarningServiceInterface::class);
        $this->admin = User::factory()->create();
    }

    public function test_batch_is_drafted_from_releasable_earnings_of_one_instructor_and_currency(): void
    {
        $instructor = User::factory()->create();

        $inr1 = InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'earning_amount_minor' => 30000, 'currency_code' => 'INR']);
        $inr2 = InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'earning_amount_minor' => 20000, 'currency_code' => 'INR']);
        // Never mixed in: another currency, another instructor, still on hold.
        $usd = InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'USD']);
        $otherInstructor = InstructorEarning::factory()->releasable()->create(['currency_code' => 'INR']);
        $stillHeld = InstructorEarning::factory()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $batch = $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);

        $this->assertSame(SettlementBatchStatus::Draft, $batch->status);
        $this->assertSame(50000, $batch->total_amount_minor);
        $this->assertSame('INR', $batch->currency_code);
        $this->assertStringStartsWith('ISB-', $batch->batch_reference);

        $this->assertSame($batch->id, $inr1->refresh()->settlement_batch_id);
        $this->assertSame($batch->id, $inr2->refresh()->settlement_batch_id);
        $this->assertNull($usd->refresh()->settlement_batch_id);
        $this->assertNull($otherInstructor->refresh()->settlement_batch_id);
        $this->assertNull($stillHeld->refresh()->settlement_batch_id);
    }

    public function test_approve_then_mark_paid_settles_earnings_without_external_calls(): void
    {
        Http::fake();

        $instructor = User::factory()->create();
        $earning = InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $batch = $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
        $batch = $this->earnings->approveSettlementBatch($batch, $this->admin);

        $this->assertSame(SettlementBatchStatus::Approved, $batch->status);
        $this->assertSame($this->admin->id, $batch->approved_by);

        $batch = $this->earnings->markSettlementBatchPaid($batch, $this->admin, 'NEFT-12345');

        $this->assertSame(SettlementBatchStatus::Paid, $batch->status);
        $this->assertNotNull($batch->paid_at);
        $this->assertSame('NEFT-12345', $batch->payment_reference);

        $earning->refresh();
        $this->assertSame(InstructorEarningStatus::Settled, $earning->status);
        $this->assertNotNull($earning->settled_at);

        // No external payout call, no wallet mutation — money moved outside the system.
        Http::assertNothingSent();
        $this->assertSame(0, Wallet::query()->count());
        $this->assertSame(0, WalletLedgerEntry::query()->count());
    }

    public function test_draft_batch_cannot_be_marked_paid_directly(): void
    {
        $instructor = User::factory()->create();
        InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $batch = $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);

        $this->expectException(InvalidEarningTransitionException::class);

        $this->earnings->markSettlementBatchPaid($batch, $this->admin);
    }

    public function test_settled_earnings_never_enter_another_batch(): void
    {
        $instructor = User::factory()->create();
        InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $batch = $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
        $this->earnings->approveSettlementBatch($batch, $this->admin);
        $this->earnings->markSettlementBatchPaid($batch, $this->admin);

        // The pool is empty now — settled earnings are excluded forever.
        $this->expectException(EarningException::class);

        $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
    }

    public function test_assigned_but_unpaid_earnings_are_not_available_to_a_second_batch(): void
    {
        $instructor = User::factory()->create();
        InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);

        $this->expectException(EarningException::class);

        $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
    }

    public function test_cancelled_draft_batch_returns_earnings_to_the_pool(): void
    {
        $instructor = User::factory()->create();
        $earning = InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $batch = $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
        $batch = $this->earnings->cancelSettlementBatch($batch, $this->admin, 'Wrong period.');

        $this->assertSame(SettlementBatchStatus::Cancelled, $batch->status);

        $earning->refresh();
        $this->assertNull($earning->settlement_batch_id);
        $this->assertSame(InstructorEarningStatus::Releasable, $earning->status);

        // The earning is immediately batchable again.
        $second = $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
        $this->assertSame($second->id, $earning->refresh()->settlement_batch_id);
    }

    public function test_approved_batch_cannot_be_cancelled(): void
    {
        $instructor = User::factory()->create();
        InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $batch = $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
        $this->earnings->approveSettlementBatch($batch, $this->admin);

        $this->expectException(EarningException::class);

        $this->earnings->cancelSettlementBatch($batch, $this->admin);
    }

    public function test_empty_pool_and_minimum_amount_are_enforced(): void
    {
        $instructor = User::factory()->create();

        try {
            $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
            $this->fail('Expected an empty pool to be rejected.');
        } catch (EarningException) {
            // expected
        }

        $settings = app(InstructorEarningSettings::class);
        $settings->minimum_settlement_amount_minor = 100000;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());

        InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'earning_amount_minor' => 5000, 'currency_code' => 'INR']);

        $this->expectException(EarningException::class);

        $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);
    }

    public function test_reversing_an_earning_assigned_to_a_batch_is_blocked(): void
    {
        $instructor = User::factory()->create();
        $earning = InstructorEarning::factory()->releasable()->create(['instructor_id' => $instructor->id, 'currency_code' => 'INR']);

        $this->earnings->createSettlementBatch($instructor->id, 'INR', actor: $this->admin);

        $this->expectException(EarningException::class);

        $this->earnings->reverse($earning->refresh(), $this->admin, 'Attempted while batched.');
    }

    public function test_batch_serialization_hides_admin_internals(): void
    {
        $batch = InstructorSettlementBatch::factory()->create([
            'notes' => 'Internal admin note',
            'metadata' => ['internal' => true],
        ]);

        $serialized = $batch->toArray();

        $this->assertArrayNotHasKey('notes', $serialized);
        $this->assertArrayNotHasKey('metadata', $serialized);
    }
}
