<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\InstructorStatus;
use App\Filament\Resources\Instructors\InstructorResource;
use App\Filament\Resources\Instructors\Pages\ListInstructors;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The People section offers three ways into the same user list: "All
 * Users" plus role-scoped "Students" and "Instructors". These prove the
 * scoping is real (not just a label), that the scoped lists never become
 * a second place to create or edit a user, and that a missing role row
 * renders an empty list instead of throwing.
 */
class RoleScopedUserListsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->superAdmin->assignRole('super_admin');
    }

    private function userWithRole(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);

        return $user;
    }

    public function test_student_list_shows_only_users_holding_the_student_role(): void
    {
        $student = $this->userWithRole('student');
        $instructor = $this->userWithRole('instructor');

        $this->actingAs($this->superAdmin);

        Livewire::test(ListStudents::class)
            ->assertCanSeeTableRecords([$student])
            ->assertCanNotSeeTableRecords([$instructor, $this->superAdmin]);
    }

    public function test_instructor_list_shows_only_users_holding_the_instructor_role(): void
    {
        $student = $this->userWithRole('student');
        $instructor = $this->userWithRole('instructor');

        $this->actingAs($this->superAdmin);

        Livewire::test(ListInstructors::class)
            ->assertCanSeeTableRecords([$instructor])
            ->assertCanNotSeeTableRecords([$student, $this->superAdmin]);
    }

    public function test_all_users_list_still_shows_every_user_regardless_of_role(): void
    {
        $student = $this->userWithRole('student');
        $instructor = $this->userWithRole('instructor');

        $this->actingAs($this->superAdmin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$student, $instructor, $this->superAdmin]);
    }

    /**
     * The scoped lists are find-a-person surfaces; creating and editing
     * (and the audit logging that hangs off EditUser) stay on
     * UserResource so there is exactly one write path per user.
     */
    /**
     * "Roles" is noise on a list that is already one role, and these
     * people don't author posts. Contact details take their place so an
     * admin can identify and reach someone from the list itself.
     */
    public function test_scoped_lists_drop_roles_and_posts_and_show_contact_details(): void
    {
        $this->userWithRole('student');
        $this->userWithRole('instructor');
        $this->actingAs($this->superAdmin);

        Livewire::test(ListStudents::class)
            ->assertTableColumnDoesNotExist('roles.name')
            ->assertTableColumnDoesNotExist('authored_posts_count')
            ->assertTableColumnExists('profile.phone_e164')
            ->assertTableColumnExists('profile.country.name')
            ->assertTableColumnExists('profile.student_status')
            ->assertTableColumnDoesNotExist('profile.instructor_status');

        Livewire::test(ListInstructors::class)
            ->assertTableColumnDoesNotExist('roles.name')
            ->assertTableColumnDoesNotExist('authored_posts_count')
            ->assertTableColumnExists('profile.phone_e164')
            ->assertTableColumnExists('profile.instructor_status')
            ->assertTableColumnDoesNotExist('profile.student_status');
    }

    /**
     * "All Users" keeps Roles — it is the only list where a person's role
     * is not already implied — but loses Posts for the same reason the
     * scoped lists never had it: authoring is nobody's primary work here.
     */
    public function test_all_users_list_keeps_roles_but_drops_posts_for_contact_details(): void
    {
        $this->userWithRole('student');
        $this->actingAs($this->superAdmin);

        Livewire::test(ListUsers::class)
            ->assertTableColumnExists('roles.name')
            ->assertTableColumnDoesNotExist('authored_posts_count')
            ->assertTableColumnExists('profile.phone_e164')
            ->assertTableColumnExists('profile.country.name');
    }

    /**
     * "Instructor/Student Status", "Account" and "Verified" all rendered
     * near-identical badges — and each has a state called Active or
     * Verified meaning something different. There are now two axes: the
     * role lifecycle, and account access (which carries email
     * confirmation as its subtitle rather than a third badge).
     */
    public function test_account_access_absorbs_email_verification_leaving_two_status_axes(): void
    {
        $unverified = $this->userWithRole('student');
        $unverified->forceFill(['email_verified_at' => null, 'status' => User::STATUS_PENDING])->save();

        $this->actingAs($this->superAdmin);

        foreach ([ListStudents::class, ListInstructors::class, ListUsers::class] as $list) {
            Livewire::test($list)
                ->assertTableColumnExists('status')
                ->assertTableColumnDoesNotExist('email_verified_at');
        }

        Livewire::test(ListStudents::class)
            ->assertSee('Account access')
            ->assertSee('Student lifecycle')
            ->assertSee('Pending verification')
            ->assertSee('Email not verified');
    }

    public function test_account_status_labels_and_colors_come_from_one_place(): void
    {
        $this->assertSame('Pending verification', User::statusLabel(User::STATUS_PENDING));
        $this->assertSame('Active', User::statusLabel(User::STATUS_ACTIVE));
        $this->assertSame('warning', User::statusColor(User::STATUS_PENDING));
        $this->assertSame('success', User::statusColor(User::STATUS_ACTIVE));
        $this->assertSame('danger', User::statusColor(User::STATUS_BLOCKED));
        $this->assertSame('danger', User::statusColor(User::STATUS_SUSPENDED));
        $this->assertSame('gray', User::statusColor(User::STATUS_INACTIVE));
        $this->assertSame('gray', User::statusColor(null));
    }

    public function test_mobile_column_is_searchable_by_any_stored_number_format(): void
    {
        $student = $this->userWithRole('student');
        $student->profile()->update([
            'phone' => '98765 43210',
            'phone_national_number' => '9876543210',
            'phone_e164' => '+919876543210',
        ]);
        $other = $this->userWithRole('student');

        $this->actingAs($this->superAdmin);

        foreach (['+919876543210', '9876543210', '98765 43210'] as $search) {
            Livewire::test(ListStudents::class)
                ->searchTable($search)
                ->assertCanSeeTableRecords([$student])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    /**
     * The five filter dropdowns (role, status, instructor status, student
     * status, verified) were a question-per-dropdown for what is almost
     * always "find this one person". Search does all of it now, including
     * the status labels — so there is one place to type, not a panel to
     * open first.
     */
    public function test_user_lists_have_no_filter_panel_and_search_covers_what_the_filters_did(): void
    {
        $this->actingAs($this->superAdmin);

        foreach ([ListUsers::class, ListStudents::class, ListInstructors::class] as $list) {
            $this->assertSame([], Livewire::test($list)->instance()->getTable()->getFilters());
        }

        $pending = $this->userWithRole('student');
        $pending->forceFill(['status' => User::STATUS_PENDING])->save();
        $active = $this->userWithRole('student');
        $active->forceFill(['status' => User::STATUS_ACTIVE])->save();

        // Typed as it reads on the badge, not as it is stored.
        Livewire::test(ListStudents::class)
            ->searchTable('pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_search_matches_email_as_well_as_name(): void
    {
        $student = $this->userWithRole('student');
        $student->forceFill(['name' => 'Jordan Rivera', 'email' => 'jordan.findme@example.test'])->save();
        $other = $this->userWithRole('student');

        $this->actingAs($this->superAdmin);

        Livewire::test(ListStudents::class)
            ->searchTable('jordan.findme@example.test')
            ->assertCanSeeTableRecords([$student])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_search_matches_the_role_lifecycle_by_its_label(): void
    {
        $underReview = $this->userWithRole('instructor');
        $underReview->profile()->update(['instructor_status' => InstructorStatus::UnderReview]);
        $draft = $this->userWithRole('instructor');
        $draft->profile()->update(['instructor_status' => InstructorStatus::Draft]);

        $this->actingAs($this->superAdmin);

        Livewire::test(ListInstructors::class)
            ->searchTable('under review')
            ->assertCanSeeTableRecords([$underReview])
            ->assertCanNotSeeTableRecords([$draft]);
    }

    public function test_each_list_states_what_it_is_and_what_can_be_typed_into_its_search(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertSame('All Users', Livewire::test(ListUsers::class)->instance()->getTitle());
        $this->assertSame('Students', Livewire::test(ListStudents::class)->instance()->getTitle());
        $this->assertSame('Instructors', Livewire::test(ListInstructors::class)->instance()->getTitle());

        $this->assertStringContainsString(
            'mobile',
            Livewire::test(ListStudents::class)->instance()->getTable()->getSearchPlaceholder() ?? '',
        );
        $this->assertStringNotContainsString(
            'role',
            Livewire::test(ListStudents::class)->instance()->getTable()->getSearchPlaceholder() ?? '',
        );
    }

    public function test_scoped_lists_expose_no_create_or_edit_pages(): void
    {
        foreach ([StudentResource::class, InstructorResource::class] as $resource) {
            $this->assertSame(['index'], array_keys($resource::getPages()));
            $this->assertFalse($resource::canCreate());
        }
    }

    public function test_scoped_lists_render_empty_rather_than_throwing_when_the_role_row_is_absent(): void
    {
        $this->assertFalse(Role::where('name', 'student')->where('guard_name', 'web')->exists());

        $this->actingAs($this->superAdmin);

        Livewire::test(ListStudents::class)->assertCanNotSeeTableRecords([$this->superAdmin]);
        Livewire::test(ListInstructors::class)->assertCanNotSeeTableRecords([$this->superAdmin]);
    }

    public function test_scoped_lists_are_reachable_over_http(): void
    {
        $this->actingAs($this->superAdmin);

        $this->get(StudentResource::getUrl('index'))->assertOk();
        $this->get(InstructorResource::getUrl('index'))->assertOk();
    }
}
