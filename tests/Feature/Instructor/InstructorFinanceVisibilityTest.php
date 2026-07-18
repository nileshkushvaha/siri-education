<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\SettlementBatchStatus;
use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Instructor\EarningsOverview;
use App\Livewire\Frontend\Instructor\SettlementsOverview;
use App\Models\InstructorEarning;
use App\Models\InstructorSettlementBatch;
use App\Models\User;
use App\Services\Account\AccountMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorFinanceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $otherInstructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->otherInstructor->assignRole('instructor');
        $this->otherInstructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    // ── Access ────────────────────────────────────────────────────────

    public function test_instructor_can_view_own_earnings_page(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.earnings'))
            ->assertOk()
            ->assertSeeLivewire(EarningsOverview::class);
    }

    public function test_instructor_can_view_own_settlements_page(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.settlements'))
            ->assertOk()
            ->assertSeeLivewire(SettlementsOverview::class);
    }

    public function test_student_cannot_access_instructor_earnings_page(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.instructor.earnings'))
            ->assertForbidden();
    }

    public function test_student_cannot_access_instructor_settlements_page(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.instructor.settlements'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_earnings_page(): void
    {
        $this->get(route('dashboard.instructor.earnings'))->assertRedirect(route('auth.login'));
    }

    // ── Ownership ─────────────────────────────────────────────────────

    public function test_instructor_a_cannot_see_instructor_b_earnings(): void
    {
        InstructorEarning::factory()->create([
            'instructor_id' => $this->otherInstructor->id,
            'earning_amount_minor' => 999999,
        ]);

        Livewire::actingAs($this->instructor)
            ->test(EarningsOverview::class)
            ->assertDontSee('9,999.99');
    }

    public function test_instructor_a_cannot_see_instructor_b_settlements(): void
    {
        InstructorSettlementBatch::factory()->create([
            'instructor_id' => $this->otherInstructor->id,
            'batch_reference' => 'ISB-OTHERGUY01',
        ]);

        Livewire::actingAs($this->instructor)
            ->test(SettlementsOverview::class)
            ->assertDontSee('ISB-OTHERGUY01');
    }

    public function test_earnings_list_only_shows_the_authenticated_instructors_own_rows(): void
    {
        $mine = InstructorEarning::factory()->create([
            'instructor_id' => $this->instructor->id,
            'earning_amount_minor' => 12345,
            'currency_code' => 'INR',
        ]);
        InstructorEarning::factory()->count(3)->create([
            'instructor_id' => $this->otherInstructor->id,
            'earning_amount_minor' => 55555,
            'currency_code' => 'INR',
        ]);

        Livewire::actingAs($this->instructor)
            ->test(EarningsOverview::class)
            ->assertSee('123.45 INR')
            ->assertDontSee('555.55 INR');

        $this->assertSame(1, InstructorEarning::query()->forInstructor($this->instructor->id)->count());
    }

    // ── Query safety ──────────────────────────────────────────────────

    public function test_earnings_page_query_count_is_bounded_as_earnings_grow(): void
    {
        InstructorEarning::factory()->count(10)->create(['instructor_id' => $this->instructor->id]);
        $initial = $this->queryCountForEarningsPage();

        InstructorEarning::factory()->count(90)->create(['instructor_id' => $this->instructor->id]);
        $grown = $this->queryCountForEarningsPage();

        $this->assertLessThanOrEqual($initial + 2, $grown, 'Earnings page queries must stay bounded (paginated + one aggregate), not scale with row count.');
    }

    private function queryCountForEarningsPage(): int
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $this->actingAs($this->instructor)->get(route('dashboard.instructor.earnings'))->assertOk();

        return $queries;
    }

    // ── Privacy ───────────────────────────────────────────────────────

    public function test_earnings_page_never_exposes_student_contact_details(): void
    {
        $student = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email' => 'private-student@example.test',
        ]);

        InstructorEarning::factory()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $student->id,
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.earnings'))->assertOk();

        $response->assertDontSee('private-student@example.test');
    }

    public function test_earnings_page_never_exposes_internal_admin_notes_or_metadata(): void
    {
        InstructorEarning::factory()->create([
            'instructor_id' => $this->instructor->id,
            'notes' => 'INTERNAL-ADMIN-NOTE-DO-NOT-SHOW',
            'metadata' => ['gateway_fee_minor' => 500, 'internal_flag' => 'INTERNAL-METADATA-MARKER'],
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.earnings'))->assertOk();

        $response->assertDontSee('INTERNAL-ADMIN-NOTE-DO-NOT-SHOW');
        $response->assertDontSee('INTERNAL-METADATA-MARKER');
    }

    public function test_settlements_page_never_exposes_payout_provider_reference_or_notes(): void
    {
        InstructorSettlementBatch::factory()->create([
            'instructor_id' => $this->instructor->id,
            'status' => SettlementBatchStatus::Paid,
            'paid_at' => now(),
            'payment_reference' => 'PROVIDER-REF-SECRET-XYZ',
            'notes' => 'INTERNAL-SETTLEMENT-NOTE',
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.settlements'))->assertOk();

        $response->assertDontSee('PROVIDER-REF-SECRET-XYZ');
        $response->assertDontSee('INTERNAL-SETTLEMENT-NOTE');
    }

    // ── Summary correctness ──────────────────────────────────────────

    public function test_earnings_summary_totals_are_grouped_by_status(): void
    {
        InstructorEarning::factory()->create([
            'instructor_id' => $this->instructor->id,
            'status' => InstructorEarningStatus::PendingHold,
            'earning_amount_minor' => 10000,
            'currency_code' => 'INR',
        ]);
        InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $this->instructor->id,
            'earning_amount_minor' => 20000,
            'currency_code' => 'INR',
        ]);
        InstructorEarning::factory()->create([
            'instructor_id' => $this->instructor->id,
            'status' => InstructorEarningStatus::Settled,
            'earning_amount_minor' => 30000,
            'currency_code' => 'INR',
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.earnings'))->assertOk();

        // Total = 10000 + 20000 + 30000 = 600.00 INR
        $response->assertSee('600.00 INR');
        $response->assertSee('100.00 INR');
        $response->assertSee('200.00 INR');
    }

    public function test_empty_earnings_state_is_shown(): void
    {
        Livewire::actingAs($this->instructor)
            ->test(EarningsOverview::class)
            ->assertSee('No earnings yet');
    }

    public function test_empty_settlements_state_is_shown(): void
    {
        Livewire::actingAs($this->instructor)
            ->test(SettlementsOverview::class)
            ->assertSee('No settlements available yet');
    }

    // ── Navigation ────────────────────────────────────────────────────

    public function test_instructor_navigation_includes_earnings_and_settlements(): void
    {
        $items = app(AccountMenuService::class)->items($this->instructor);
        $labels = collect($items)->flatMap(fn (array $group) => collect($group['items'])->pluck('label'))->all();

        $this->assertContains('Earnings', $labels);
        $this->assertContains('Settlements', $labels);
    }
}
