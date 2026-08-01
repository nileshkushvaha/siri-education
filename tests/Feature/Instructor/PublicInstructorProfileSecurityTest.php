<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PublicInstructorProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    public function test_anonymous_visitor_can_view_an_approved_instructor(): void
    {
        $instructor = $this->makeInstructor();

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee($instructor->name);
    }

    public function test_public_profile_never_exposes_email(): void
    {
        $instructor = $this->makeInstructor(userOverrides: ['email' => 'very-secret-address@sirieducation.com']);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('very-secret-address@sirieducation.com');
    }

    public function test_public_profile_never_exposes_phone(): void
    {
        $instructor = $this->makeInstructor(profileOverrides: [
            'phone' => '9998887776',
            'phone_e164' => '+19998887776',
            'show_phone' => true,
        ]);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('9998887776')
            ->assertDontSee('+19998887776');
    }

    public function test_public_profile_never_exposes_kyc_review_metadata(): void
    {
        $instructor = $this->makeInstructor(profileOverrides: [
            'instructor_review_reason' => 'INTERNAL-REASON-Rejected-for-fake-documents',
            'instructor_documents_requested_reason' => 'INTERNAL-Please-resubmit-a-clearer-government-id-scan',
        ]);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('INTERNAL-REASON-Rejected-for-fake-documents')
            ->assertDontSee('INTERNAL-Please-resubmit-a-clearer-government-id-scan');
    }

    public function test_public_profile_never_exposes_compensation_or_internal_notes(): void
    {
        $instructor = $this->makeInstructor();

        $response = $this->get(route('instructors.show', $instructor));

        $response->assertOk();

        // Specific enough not to collide with ordinary page copy (e.g. "earning"
        // would false-positive on "learning") while still catching a real leak
        // of an internal field name or label.
        foreach (['compensation', 'commission_rate', 'payout_amount', 'instructor_earning', 'salary', 'admin note', 'internal note'] as $forbidden) {
            $response->assertDontSee($forbidden, false)
                ->assertDontSee(ucfirst($forbidden), false);
        }
    }

    public function test_public_profile_never_exposes_address_or_postal_code(): void
    {
        $instructor = $this->makeInstructor(profileOverrides: [
            'address' => '221B Baker Street',
            'postal_code' => 'NW16XE',
        ]);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('221B Baker Street')
            ->assertDontSee('NW16XE');
    }

    private function makeInstructor(array $profileOverrides = [], array $userOverrides = []): User
    {
        $user = User::factory()->create(array_merge(['status' => User::STATUS_ACTIVE], $userOverrides));
        $user->profile->update(array_merge([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
            'offers_demo' => true,
        ], $profileOverrides));
        $user->assignRole('instructor');

        return $user->fresh();
    }
}
