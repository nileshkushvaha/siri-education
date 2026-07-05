<?php

declare(strict_types=1);

namespace Tests\Feature\AccountPortal;

use App\Models\User;
use App\Services\Account\AccountMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountMenuServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountMenuService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccountMenuService::class);

        Permission::firstOrCreate(['name' => 'profile.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function makeUser(string $status = 'active'): User
    {
        return User::factory()->create([
            'status' => $status,
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
    }

    public function test_dashboard_item_always_present_for_any_frontend_user(): void
    {
        $user = $this->makeUser();

        $labels = array_column($this->service->items($user), 'label');

        $this->assertContains('Dashboard', $labels);
    }

    /**
     * 'profile.view' is Gate-bound to ProfilePolicy::view(), which checks
     * isActive() — reusing the existing policy rather than inventing a new
     * Spatie permission. An inactive user therefore fails the 'profile.view'
     * ability and the item disappears, proving isVisible() fails closed.
     */
    public function test_profile_item_hidden_when_permission_check_fails(): void
    {
        $user = $this->makeUser(status: 'inactive');

        $labels = array_column($this->service->items($user), 'label');

        $this->assertNotContains('My Profile', $labels);
    }

    public function test_profile_item_visible_when_permission_check_passes(): void
    {
        $user = $this->makeUser();

        $labels = array_column($this->service->items($user), 'label');

        $this->assertContains('My Profile', $labels);
    }

    public function test_instructor_menu_does_not_show_student_workflow_links(): void
    {
        $instructor = $this->makeUser();
        $instructor->assignRole('instructor');

        $student = $this->makeUser();
        $student->assignRole('student');

        $instructorLabels = array_column($this->service->items($instructor), 'label');
        $studentLabels = array_column($this->service->items($student), 'label');

        $this->assertContains('My Profile', $instructorLabels);
        $this->assertContains('My Profile', $studentLabels);

        $this->assertContains('Dashboard', $instructorLabels);
        $this->assertContains('Dashboard', $studentLabels);

        $this->assertNotContains('My Bookings', $instructorLabels);
        $this->assertNotContains('Payments', $instructorLabels);
        $this->assertNotContains('Homework', $instructorLabels);
        $this->assertContains('My Bookings', $studentLabels);
        $this->assertContains('Payments', $studentLabels);
        $this->assertContains('Homework', $studentLabels);
    }

    public function test_student_links_remain_visible_when_user_also_has_instructor_role(): void
    {
        $user = $this->makeUser();
        $user->assignRole(['student', 'instructor']);

        $labels = array_column($this->service->items($user), 'label');

        $this->assertContains('Upcoming Classes', $labels);
        $this->assertContains('My Bookings', $labels);
        $this->assertContains('Payments', $labels);
        $this->assertContains('Homework', $labels);
    }

    public function test_items_have_expected_shape_for_future_nesting_and_badges(): void
    {
        $user = $this->makeUser();

        foreach ($this->service->items($user) as $item) {
            $this->assertArrayHasKey('label', $item);
            $this->assertArrayHasKey('url', $item);
            $this->assertArrayHasKey('route', $item);
            $this->assertArrayHasKey('icon', $item);
            $this->assertArrayHasKey('audience', $item);
            $this->assertArrayHasKey('badge', $item);
            $this->assertArrayHasKey('children', $item);
            $this->assertIsArray($item['children']);
        }
    }
}
