<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging\Safety;

use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use App\Models\MessageSafetyFinding;
use App\Models\User;
use App\Policies\MessageSafetyFindingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Messaging\Safety\Concerns\BuildsMessageSafetyFixtures;
use Tests\TestCase;

/**
 * Who may see a safety finding.
 *
 * The participants' denial is the decision that matters: showing
 * someone an unreviewed machine suspicion about their own words teaches
 * evasion, invites argument with a classifier, and for a student —
 * often a minor — amounts to being accused by software.
 */
class MessageSafetyAuthorizationTest extends TestCase
{
    use BuildsMessageSafetyFixtures, CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    private function finding(?User $sender = null): MessageSafetyFinding
    {
        $student = $this->student();
        $instructor = $sender ?? $this->instructor();
        $conversation = $this->conversation($student, $instructor);
        $message = $this->message($conversation, $instructor, 'My email is a@example.com', ['email_address']);

        return app(MessageSafetyServiceInterface::class)->recordDeterministicFinding($message);
    }

    public function test_a_compliance_admin_may_view_and_review(): void
    {
        $finding = $this->finding();
        $admin = $this->complianceAdmin();

        $this->actingAs($admin);

        $this->assertTrue($admin->can('viewAny', MessageSafetyFinding::class));
        $this->assertTrue($admin->can('view', $finding));
        $this->assertTrue($admin->can('review', $finding));
    }

    public function test_viewing_does_not_imply_reviewing(): void
    {
        $finding = $this->finding();
        $viewer = $this->complianceAdmin(['ViewAny:SuspiciousActivityFlag', 'View:SuspiciousActivityFlag']);

        $this->actingAs($viewer);

        $this->assertTrue($viewer->can('view', $finding));
        $this->assertFalse($viewer->can('review', $finding));
    }

    public function test_the_sender_can_never_see_a_finding_about_their_own_message(): void
    {
        $instructor = $this->instructor();
        $finding = $this->finding($instructor);

        $this->actingAs($instructor);

        $this->assertFalse($instructor->can('viewAny', MessageSafetyFinding::class));
        $this->assertFalse($instructor->can('view', $finding));
        $this->assertFalse($instructor->can('review', $finding));
    }

    public function test_a_student_participant_can_never_see_a_finding(): void
    {
        $finding = $this->finding();
        $student = $this->student();

        $this->actingAs($student);

        $this->assertFalse($student->can('view', $finding));
    }

    public function test_a_manager_without_compliance_permissions_sees_nothing(): void
    {
        $finding = $this->finding();

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        $this->actingAs($manager->fresh());

        $this->assertFalse($manager->fresh()->can('view', $finding));
    }

    public function test_findings_can_never_be_created_or_edited_by_hand(): void
    {
        $finding = $this->finding();
        $admin = $this->complianceAdmin();

        $policy = app(MessageSafetyFindingPolicy::class);

        $this->assertFalse($policy->create($admin));
        $this->assertFalse($policy->update($admin, $finding));
        $this->assertFalse($policy->delete($admin, $finding));
    }

    /** No permission in this feature grants the power to act on a user. */
    public function test_the_policy_exposes_no_enforcement_ability(): void
    {
        $methods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(MessageSafetyFindingPolicy::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (['block', 'ban', 'suspend', 'restrict', 'enforce', 'remove'] as $forbidden) {
            foreach ($methods as $method) {
                $this->assertStringNotContainsString($forbidden, strtolower($method));
            }
        }
    }
}
