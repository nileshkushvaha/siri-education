<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentAdminTabTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin',  'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student',      'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor',   'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole('super_admin');
        $this->actingAs($this->superAdmin);
    }

    public function test_student_tab_is_visible_for_student_role_user(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Livewire::test(EditUser::class, ['record' => $student->getRouteKey()])
            ->assertSee('Learning Overview');
    }

    public function test_student_tab_is_hidden_for_non_student_user(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertDontSee('Learning Overview');
    }

    public function test_student_tab_is_hidden_for_instructor_only_user(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->assertDontSee('Learning Overview');
    }

    public function test_student_tab_shows_the_account_summary_section(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        // The section is "Account summary" on UserForm — it was renamed
        // from "Account Info" without this assertion following it. Its
        // contents (Member Since and the rest) never moved.
        Livewire::test(EditUser::class, ['record' => $student->getRouteKey()])
            ->assertSee('Account summary')
            ->assertSee('Member Since');
    }

    public function test_student_tab_is_visible_when_user_has_both_student_and_instructor_roles(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole(['student', 'instructor']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertSee('Learning Overview');
    }

    // ── Google Meet co-host address, editable by an administrator ─────────

    public function test_an_administrator_can_set_the_instructors_google_meet_account(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'active']);

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->assertSee('Google account for Meet lessons')
            ->fillForm(['profile.google_meet_account' => 'Teacher.Meet@Gmail.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        // Stored normalised, exactly as the instructor-facing form stores it.
        $this->assertSame('teacher.meet@gmail.com', $instructor->profile()->value('google_meet_account'));
    }

    public function test_the_google_meet_account_must_be_an_email_address(): void
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'active']);

        Livewire::test(EditUser::class, ['record' => $instructor->getRouteKey()])
            ->fillForm(['profile.google_meet_account' => 'not-an-email'])
            ->call('save')
            ->assertHasFormErrors(['profile.google_meet_account']);
    }

    public function test_the_google_meet_account_field_is_only_offered_for_instructors(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Livewire::test(EditUser::class, ['record' => $student->getRouteKey()])
            ->assertDontSee('Google account for Meet lessons');
    }
}
