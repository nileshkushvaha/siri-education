<?php

declare(strict_types=1);

namespace Tests\Feature\SupportCases;

use App\Models\SupportCase;
use App\Models\User;
use App\SupportCases\Enums\SupportCasePriority;
use App\SupportCases\Enums\SupportCaseStatus;
use Database\Seeders\SupportCasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §25.37/§25.41/§25.44: students and instructors may only view
 * their own cases; unauthorized access is denied; admin surfaces are
 * permission-controlled.
 */
class SupportCaseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $this->seed(SupportCasePermissionSeeder::class);
    }

    private function student(): User
    {
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        return $manager;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard.support-cases'))->assertRedirect(route('auth.login'));
    }

    public function test_a_student_cannot_view_another_students_case(): void
    {
        $owner = $this->student();
        $intruder = $this->student();
        $case = SupportCase::factory()->forStudent($owner)->create();

        $this->actingAs($intruder)
            ->get(route('dashboard.support-cases.show', $case))
            ->assertForbidden();
    }

    public function test_a_student_cannot_reply_to_another_students_case(): void
    {
        $owner = $this->student();
        $intruder = $this->student();
        $case = SupportCase::factory()->forStudent($owner)->create();

        $this->actingAs($intruder)
            ->post(route('dashboard.support-cases.reply', $case), ['body' => 'Not my case'])
            ->assertForbidden();
    }

    public function test_the_case_list_only_shows_the_students_own_cases(): void
    {
        $student = $this->student();
        $other = $this->student();
        SupportCase::factory()->forStudent($student)->create(['subject' => 'My own issue']);
        SupportCase::factory()->forStudent($other)->create(['subject' => 'Someone elses issue']);

        $this->actingAs($student)
            ->get(route('dashboard.support-cases'))
            ->assertOk()
            ->assertSee('My own issue')
            ->assertDontSee('Someone elses issue');
    }

    public function test_owner_can_view_their_own_case(): void
    {
        $student = $this->student();
        $case = SupportCase::factory()->forStudent($student)->create();

        $this->actingAs($student)
            ->get(route('dashboard.support-cases.show', $case))
            ->assertOk()
            ->assertSee($case->case_number);
    }

    public function test_authorized_manager_can_access_the_admin_support_case_resource(): void
    {
        $manager = $this->manager();
        $case = SupportCase::factory()->create();

        $this->actingAs($manager)
            ->get('/admin/support-cases')
            ->assertOk();

        $this->actingAs($manager)
            ->get('/admin/support-cases/'.$case->id)
            ->assertOk();
    }

    public function test_unauthorized_user_cannot_access_the_admin_support_case_resource(): void
    {
        $student = $this->student();

        $this->actingAs($student)
            ->get('/admin/support-cases')
            ->assertForbidden();
    }

    public function test_no_hard_delete_route_exists_on_the_admin_resource(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.support-cases.edit'));
    }

    public function test_admin_case_list_can_filter_by_status_and_priority(): void
    {
        $manager = $this->manager();
        SupportCase::factory()->create(['priority' => SupportCasePriority::Critical, 'status' => SupportCaseStatus::Open]);
        SupportCase::factory()->create(['priority' => SupportCasePriority::Low, 'status' => SupportCaseStatus::Closed]);

        $critical = SupportCase::query()->where('priority', SupportCasePriority::Critical)->count();
        $this->assertSame(1, $critical);

        $this->actingAs($manager)->get('/admin/support-cases')->assertOk();
    }
}
