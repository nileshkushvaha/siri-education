<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Instructor\OnboardingWizard;
use App\Models\AcademicLevel;
use App\Models\User;
use App\Settings\AuthenticationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorApplicationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    public function test_guest_cannot_reach_the_instructor_start_route(): void
    {
        $this->post(route('dashboard.instructor.start'))
            ->assertRedirect(route('auth.login'));
    }

    public function test_unverified_user_cannot_bypass_email_verification_via_the_start_route(): void
    {
        // Platform-wide verification is off, so the email.verify.if.required
        // route middleware would otherwise let this request through — the
        // instructor-specific rule inside InstructorEligibilityService must
        // still catch it independently.
        app(AuthenticationSettings::class)->email_verification_required = false;
        app(AuthenticationSettings::class)->save();

        $user = User::factory()->unverified()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('student');

        $this->actingAs($user)
            ->post(route('dashboard.instructor.start'))
            ->assertRedirect(route('dashboard.instructor.onboarding'))
            ->assertSessionHas('error', 'Please verify your email before applying to teach.');

        $this->assertNull($user->fresh()->profile?->instructor_status);
        $this->assertFalse($user->fresh()->hasRole('instructor'));
    }

    public function test_direct_onboarding_wizard_start_action_respects_eligibility(): void
    {
        $level = AcademicLevel::query()->create([
            'name' => 'High School', 'slug' => 'high-school',
            'min_grade' => 9, 'max_grade' => 12, 'status' => 'active', 'display_order' => 0,
        ]);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $user->assignRole('student');
        $user->profile()->update(['student_academic_level_id' => $level->id]);

        Livewire::actingAs($user->fresh())
            ->test(OnboardingWizard::class)
            ->call('start')
            ->assertSet('step', 1);

        $this->assertNull($user->fresh()->profile->instructor_status);
        $this->assertFalse($user->fresh()->hasRole('instructor'));
    }

    public function test_resuming_an_existing_application_via_the_wizard_is_not_re_gated_by_eligibility(): void
    {
        // A Draft applicant (instructor_status already set) must be able to
        // keep using the wizard even though a fresh evaluate() call would
        // report AlreadyInstructor — that is expected/correct for a resume,
        // not a rejection. InstructorApplicationStart::attempt() must skip
        // the eligibility gate entirely once instructor_status is non-null.
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email_verified_at' => now()]);
        $user->assignRole(['student', 'instructor']);
        $user->profile()->update(['instructor_status' => InstructorStatus::Draft]);

        Livewire::actingAs($user->fresh())
            ->test(OnboardingWizard::class)
            ->call('start')
            ->assertSet('step', 2);

        $this->assertSame(InstructorStatus::Draft, $user->fresh()->profile->instructor_status);
    }

    public function test_neither_onboarding_entry_point_assigns_the_instructor_role_directly(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Instructor/InstructorOnboardingController.php'));
        $wizard = file_get_contents(app_path('Livewire/Frontend/Instructor/OnboardingWizard.php'));

        $this->assertIsString($controller);
        $this->assertIsString($wizard);
        $this->assertStringNotContainsString('assignRole(', $controller);
        $this->assertStringNotContainsString('assignRole(', $wizard);
    }

    public function test_no_duplicate_instructor_registration_endpoint_exists(): void
    {
        $this->assertFalse(Route::has('instructor.register'));
        $this->assertFalse(Route::has('instructor-registration'));

        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertIsString($routes);
        $this->assertStringNotContainsString('instructor-registration', $routes);

        // /become-instructor itself must be display-only — no POST route
        // under that path creates an account or an application.
        $this->assertFalse(Route::has('instructor.apply.start'));
    }
}
