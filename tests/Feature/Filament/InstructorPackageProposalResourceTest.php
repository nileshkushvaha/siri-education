<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Booking\Types\PaidOneToOneType;
use App\Filament\Resources\InstructorPackageProposals\InstructorPackageProposalResource;
use App\Filament\Resources\InstructorPackageProposals\Pages\ListInstructorPackageProposals;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class InstructorPackageProposalResourceTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackagePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole('manager');
    }

    private function submittedProposal(): InstructorPackageProposal
    {
        $fixture = $this->createPaidBookingTypeWithPrice(PaidOneToOneType::KEY, 20.00, 'GBP');

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');
        $this->assignBillingCountry($student, $fixture['country']);

        Booking::factory()->confirmed()->paid()->create([
            'booking_type_id' => $fixture['type']->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        $rule = app(PackageBenefitRuleService::class)->create($this->manager, [
            'name' => 'Rule', 'paid_quantity' => 14, 'bonus_quantity' => 1, 'total_quantity' => 15,
        ]);

        return app(InstructorPackageProposalService::class)->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: $instructor->id,
            studentId: $student->id,
            packageBenefitRuleId: $rule->id,
            subjectId: $this->seedLessonSubject()->id,
            academicLevelId: null,
        ));
    }

    public function test_manager_can_view_the_resource(): void
    {
        $this->actingAs($this->manager);

        $this->assertTrue(InstructorPackageProposalResource::canViewAny());
    }

    /** Phase 3.2 — admin is reviewing instructor-created offers, so the resource says so explicitly. */
    public function test_resource_labels_use_instructor_package_proposal_terminology(): void
    {
        $this->assertSame('Instructor Package Proposal', InstructorPackageProposalResource::getModelLabel());
        $this->assertSame('Instructor Package Proposals', InstructorPackageProposalResource::getPluralModelLabel());
    }

    /** The linked offer column reads "Package Offer", never the internal "Package Rule". */
    public function test_table_shows_package_offer_column_not_package_rule(): void
    {
        $proposal = $this->submittedProposal();
        $this->actingAs($this->manager);

        Livewire::test(ListInstructorPackageProposals::class)
            ->assertSee('Package Offer')
            ->assertDontSee('Package Rule');
    }

    public function test_instructor_cannot_view_the_resource(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $this->actingAs($instructor);

        $this->assertFalse(InstructorPackageProposalResource::canViewAny());
    }

    public function test_manager_can_approve_a_submitted_proposal_via_table_action(): void
    {
        $proposal = $this->submittedProposal();
        $this->actingAs($this->manager);

        Livewire::test(ListInstructorPackageProposals::class)
            ->callTableAction('approve', $proposal, data: ['final_price' => 280, 'override_reason' => '']);

        $this->assertSame('approved', $proposal->fresh()->status->value);
    }

    public function test_manager_can_reject_a_submitted_proposal_via_table_action(): void
    {
        $proposal = $this->submittedProposal();
        $this->actingAs($this->manager);

        Livewire::test(ListInstructorPackageProposals::class)
            ->callTableAction('reject', $proposal, data: ['reason' => 'Not eligible right now.']);

        $this->assertSame('rejected', $proposal->fresh()->status->value);
    }
}
