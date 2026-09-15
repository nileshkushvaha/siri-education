<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only audit of student/instructor role holdings. Never writes.
 *
 *  1. Instructor-only accounts that still carry a student_status — left
 *     over from the old flow where an instructor applicant first became
 *     a student. These accounts now see the "instructor account" page on
 *     student routes; an admin decides per account.
 *  2. Dual-role accounts (students who later applied to teach) with their
 *     activity on each side, for visibility only — dual roles are legal.
 */
class AuditPortalRoles extends Command
{
    protected $signature = 'users:audit-portal-roles';

    protected $description = 'List instructor-only accounts carrying a student status, and dual-role accounts (read-only)';

    public function handle(): int
    {
        $leftovers = User::query()
            ->whereHasRoleNamed('instructor')
            ->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'student'))
            ->whereHas('profile', fn (Builder $q) => $q->whereNotNull('student_status'))
            ->with('profile:user_id,student_status,instructor_status')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        $this->components->info(sprintf('%d instructor-only account(s) still carry a student status.', $leftovers->count()));

        if ($leftovers->isNotEmpty()) {
            $this->table(
                ['ID', 'Name', 'Email', 'student_status', 'instructor_status', 'Bookings as student'],
                $leftovers->map(fn (User $user): array => [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->profile?->student_status?->value ?? '—',
                    $user->profile?->instructor_status?->value ?? '—',
                    Booking::query()->forStudent($user->id)->count(),
                ])->all(),
            );
        }

        $dual = User::query()
            ->whereHasRoleNamed('instructor')
            ->whereHasRoleNamed('student')
            ->with('profile:user_id,student_status,instructor_status')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        $this->components->info(sprintf('%d dual-role account(s) (student who later applied to teach).', $dual->count()));

        if ($dual->isNotEmpty()) {
            $this->table(
                ['ID', 'Name', 'Email', 'student_status', 'instructor_status', 'Bookings as student', 'Bookings as instructor'],
                $dual->map(fn (User $user): array => [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->profile?->student_status?->value ?? '—',
                    $user->profile?->instructor_status?->value ?? '—',
                    Booking::query()->forStudent($user->id)->count(),
                    Booking::query()->forInstructor($user->id)->count(),
                ])->all(),
            );
        }

        $this->components->warn('Read-only: nothing was changed. Fix individual accounts from the admin user form.');

        return self::SUCCESS;
    }
}
