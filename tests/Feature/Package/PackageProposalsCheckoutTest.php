<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Types\PaidOneToOneType;
use App\Livewire\Frontend\Student\PackageProposals;
use App\Models\Booking;
use App\Models\InstructorPackageProposal;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Models\User;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Enums\PackagePurchaseStatus;
use App\Package\Services\InstructorPackageProposalService;
use App\Package\Services\PackageBenefitRuleService;
use App\Payments\Enums\PaymentStatus;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

/**
 * The student's own Lesson Packages page, end to end: accept, pay, and
 * see the lessons unlock on return from checkout.
 *
 * Regression: pay() dispatched checkout events nothing listened for, and
 * the page had no verified-return or polling path — "Continue Payment"
 * opened nothing, and only a webhook could ever settle the purchase.
 */
class PackageProposalsCheckoutTest extends TestCase
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

        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole('manager');
    }

    /** An approved offer to a student who has already taken a paid class with the instructor. */
    private function approvedProposal(): InstructorPackageProposal
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
            'name' => 'Progress — 10 + 1 Bonus Lessons',
            'paid_quantity' => 10,
            'bonus_quantity' => 1,
            'total_quantity' => 11,
            'validity_days' => 90,
        ]);

        $proposals = app(InstructorPackageProposalService::class);

        return $proposals->approve(
            $proposals->proposeAndSubmit(new CreatePackageProposalData(
                instructorId: $instructor->id,
                studentId: $student->id,
                packageBenefitRuleId: $rule->id,
                subjectId: $this->seedLessonSubject()->id,
                academicLevelId: null,
            )),
            $this->manager,
            null,
            null,
        );
    }

    public function test_a_student_can_accept_then_pay_and_the_lessons_unlock_on_return(): void
    {
        $proposal = $this->approvedProposal();
        $student = $proposal->student;

        $component = Livewire::actingAs($student)->test(PackageProposals::class)
            ->assertSee('Accept')
            ->call('accept', (string) $proposal->id)
            ->assertHasNoErrors()
            ->assertSee('Package accepted');

        $purchase = StudentPackagePurchase::query()->where('proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->status);

        $component
            ->call('pay', (string) $purchase->id)
            ->assertHasNoErrors()
            ->assertSee('Simulate success')
            ->assertSee('Continue Payment');

        $paymentId = (string) $component->get('pendingFakeCheckout')['payment_id'];
        $this->assertSame(PaymentStatus::Pending, Payment::query()->findOrFail($paymentId)->status);

        $component
            ->call('simulateFakePackagePayment', true)
            ->assertHasNoErrors()
            ->assertSet('pendingFakeCheckout', null)
            ->assertSet('pendingPaymentId', null)
            ->assertSee('Payment received')
            ->assertSee('Your lessons')
            ->assertDontSee('Continue Payment');

        $this->assertSame(PackagePurchaseStatus::Paid, $purchase->refresh()->status);
        $this->assertSame(11, (int) StudentPackageEntitlement::query()->where('proposal_id', $proposal->id)->value('total_quantity'));
    }

    public function test_a_failed_payment_says_so_and_leaves_the_purchase_payable(): void
    {
        $proposal = $this->approvedProposal();
        $student = $proposal->student;

        $component = Livewire::actingAs($student)->test(PackageProposals::class)
            ->call('accept', (string) $proposal->id);

        $purchase = StudentPackagePurchase::query()->where('proposal_id', $proposal->id)->firstOrFail();

        $component
            ->call('pay', (string) $purchase->id)
            ->call('simulateFakePackagePayment', false)
            ->assertHasErrors(['form'])
            ->assertSee('Your payment could not be completed')
            ->assertSee('Pay Now');

        $this->assertSame(PackagePurchaseStatus::PendingPayment, $purchase->refresh()->status);
        $this->assertDatabaseCount('student_package_entitlements', 0);
    }

    public function test_the_razorpay_checkout_event_is_handled_and_carries_the_purchase_id(): void
    {
        // The return handler has to know WHICH purchase to verify the
        // order against; the browser event is the only carrier, and the
        // page must actually listen for it.
        $this->assertStringContainsString('purchaseId: (string) $purchase->id', file_get_contents(app_path('Livewire/Frontend/Student/PackageProposals.php')));

        $view = file_get_contents(resource_path('views/livewire/frontend/student/package-proposals.blade.php'));
        $this->assertStringContainsString("@include('livewire.frontend.student.partials.package-checkout-script')", $view);
        $this->assertStringContainsString("@include('livewire.frontend.student.partials.package-stripe-checkout-script')", $view);

        $script = file_get_contents(resource_path('views/livewire/frontend/student/partials/package-checkout-script.blade.php'));
        $this->assertStringContainsString("\$wire.on('package-checkout-ready'", $script);
        $this->assertStringContainsString('$wire.verifyPackagePayment(', $script);
    }

    public function test_another_student_cannot_verify_someone_elses_purchase(): void
    {
        $proposal = $this->approvedProposal();
        Livewire::actingAs($proposal->student)->test(PackageProposals::class)->call('accept', (string) $proposal->id);
        $purchase = StudentPackagePurchase::query()->where('proposal_id', $proposal->id)->firstOrFail();

        $other = User::factory()->create(['status' => 'active']);
        $other->assignRole('student');

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($other)->test(PackageProposals::class)
            ->call('verifyPackagePayment', (string) $purchase->id, 'order_x', 'pay_x', 'sig');
    }
}
