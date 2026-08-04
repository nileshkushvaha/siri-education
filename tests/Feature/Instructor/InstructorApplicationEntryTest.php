<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Auth\VerifyEmailNotice;
use App\Models\AcademicLevel;
use App\Models\User;
use App\Notifications\Auth\EmailVerificationCodeNotification;
use App\Services\Auth\EmailVerificationOtpService;
use App\Settings\RegistrationSettings;
use App\Support\InstructorApplicationIntent;
use App\Support\PendingEmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorApplicationEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        app(RegistrationSettings::class)->self_registration_enabled = true;
        app(RegistrationSettings::class)->save();
    }

    public function test_public_page_loads_for_a_guest(): void
    {
        $this->get(route('instructor.apply'))
            ->assertOk()
            ->assertSee('Become an Instructor')
            ->assertSee('Application Process');
    }

    public function test_cta_links_guests_to_registration_with_instructor_intent(): void
    {
        $this->get(route('instructor.apply'))
            ->assertOk()
            ->assertSee(route('auth.register', ['intent' => 'instructor']), false);
    }

    public function test_registration_intent_survives_and_redirects_to_onboarding_after_email_verification(): void
    {
        $this->get(route('auth.register', ['intent' => 'instructor']))->assertOk();
        $this->assertTrue(InstructorApplicationIntent::pending());

        $user = User::factory()->unverified()->create(['status' => User::STATUS_PENDING]);

        Notification::fake();
        app(EmailVerificationOtpService::class)->issue($user);

        $code = null;
        Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        PendingEmailVerification::remember($user);

        Livewire::test(VerifyEmailNotice::class)
            ->set('code', $code)
            ->call('verify')
            ->assertRedirect(route('dashboard.instructor.onboarding'));

        $this->assertFalse(InstructorApplicationIntent::pending());
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_existing_eligible_student_can_start_instructor_onboarding(): void
    {
        $user = $this->studentAt('Undergraduate', 'undergraduate', null, null);

        $this->actingAs($user)
            ->post(route('dashboard.instructor.start'))
            ->assertRedirect(route('dashboard.instructor.onboarding'))
            ->assertSessionHas('success');

        $this->assertSame(InstructorStatus::Draft, $user->fresh()->profile->instructor_status);
        $this->assertTrue($user->fresh()->hasRole('instructor'));
    }

    public function test_class_12_student_is_blocked_from_starting_an_application(): void
    {
        $user = $this->studentAt('High School', 'high-school', 9, 12);

        $this->actingAs($user)
            ->post(route('dashboard.instructor.start'))
            ->assertRedirect(route('dashboard.instructor.onboarding'))
            ->assertSessionHas('error', 'Current school students cannot apply as instructors.');

        $this->assertNull($user->fresh()->profile->instructor_status);
        $this->assertFalse($user->fresh()->hasRole('instructor'));
    }

    public function test_existing_draft_applicant_sees_continue_message_on_the_landing_page(): void
    {
        $user = $this->studentAt('Undergraduate', 'undergraduate', null, null);
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Draft]);

        $this->actingAs($user->fresh())
            ->get(route('instructor.apply'))
            ->assertOk()
            ->assertSee('Continue your application');
    }

    public function test_submitted_applicant_sees_under_review_message_and_continues_without_reapplying(): void
    {
        $user = $this->studentAt('Undergraduate', 'undergraduate', null, null);
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Submitted]);
        $user->refresh();

        $this->actingAs($user)
            ->get(route('instructor.apply'))
            ->assertOk()
            ->assertSee('under review');

        // Hitting the start route again must not throw/duplicate — it stays Submitted.
        $this->actingAs($user)
            ->post(route('dashboard.instructor.start'))
            ->assertRedirect(route('dashboard.instructor.onboarding'));

        $this->assertSame(InstructorStatus::Submitted, $user->fresh()->profile->instructor_status);
    }

    public function test_already_active_instructor_sees_active_message(): void
    {
        $user = $this->studentAt('Undergraduate', 'undergraduate', null, null);
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->actingAs($user->fresh())
            ->get(route('instructor.apply'))
            ->assertOk()
            ->assertSee('already an instructor');
    }

    private function studentAt(string $name, string $slug, ?int $minGrade, ?int $maxGrade): User
    {
        $level = AcademicLevel::query()->create([
            'name' => $name,
            'slug' => $slug,
            'min_grade' => $minGrade,
            'max_grade' => $maxGrade,
            'status' => 'active',
            'display_order' => 0,
        ]);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $user->assignRole('student');
        $user->profile()->update(['student_academic_level_id' => $level->id]);

        return $user->fresh();
    }
}
