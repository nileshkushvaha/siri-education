<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds the 4 baseline roles/users for local development:
 *   1. super_admin — admin@mailinator.com   (full access, granted via SuperAdminSeeder + the app's super_admin Gate)
 *   2. manager     — manager@mailinator.com (read access across admin resources)
 *   3. instructor  — instructor@mailinator.com (manage CMS content: pages, posts, categories)
 *   4. student     — student@mailinator.com (no admin panel permissions; frontend dashboard only)
 *
 * Run after `php artisan shield:generate --all --option=permissions` so the
 * `ViewAny:*` / `Create:*` / etc. permissions referenced below already exist.
 *
 * Deliberately does NOT use WithoutModelEvents: User::firstOrCreate() must
 * fire UserObserver so these accounts get a slug (required for Filament's
 * Users resource route-model binding) and an auto-created profile, exactly
 * like any other user. See backfill_missing_user_slugs_and_profiles for
 * the one-time repair this omission previously required.
 */
class DefaultRolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);

        $admin = $this->createUser('admin@mailinator.com', 'super_admin');
        $admin->assignRole('super_admin');

        $manager = $this->createUser('manager@mailinator.com', 'manager');
        $manager->assignRole($this->managerRole());

        $instructor = $this->createUser('instructor@mailinator.com', 'instructor');
        $instructor->assignRole($this->instructorRole());

        $this->call(StudentRoleSeeder::class);

        $student = $this->createUser('student@mailinator.com', 'student');
        $student->assignRole('student');
        app(StudentLifecycleService::class)->initializeStudentRoleIfNeeded($student);

        $this->command->info('✓ Default roles and users seeded (admin, manager, instructor, student).');
    }

    private function createUser(string $email, string $label): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($label),
                'first_name' => ucfirst($label),
                'last_name' => 'User',
                'password' => Hash::make('Admin@123'),
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]
        );
    }

    private function managerRole(): Role
    {
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $role->syncPermissions(
            Permission::query()
                ->whereIn('name', [
                    'ViewAny:User', 'View:User',
                    'ViewAny:Role', 'View:Role',
                    'ViewAny:Activity', 'View:Activity',
                    'ViewAny:LoginHistory', 'View:LoginHistory',
                    'ViewAny:Post', 'View:Post',
                    'ViewAny:Page', 'View:Page',
                    // Dashboard widgets — granted to match the underlying
                    // data permissions above (manager can already see this
                    // data via the resources, so the dashboard summary
                    // widgets should be visible too).
                    'View:StatsOverviewWidget',
                    'View:RecentUsersWidget',
                    'View:RecentLoginsWidget',
                    'View:RecentAuditTrailWidget',
                ])
                ->get()
        );

        return $role;
    }

    private function instructorRole(): Role
    {
        $role = Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $role->syncPermissions(
            Permission::query()
                ->whereIn('name', [
                    'ViewAny:Post', 'View:Post', 'Create:Post', 'Update:Post',
                    'ViewAny:PostCategory', 'View:PostCategory', 'Create:PostCategory', 'Update:PostCategory',
                    'ViewAny:Page', 'View:Page', 'Create:Page', 'Update:Page',
                ])
                ->get()
        );

        return $role;
    }
}
