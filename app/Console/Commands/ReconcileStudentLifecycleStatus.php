<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StudentStatus;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Student\StudentLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aligns legacy accounts that were already
 * verified, whole-account-Active students under earlier lifecycle rules
 * but never got promoted past Registered (no automatic trigger existed
 * at the time). Dry-run by default — never mutates without the
 * explicit --apply flag. Every alignment goes through the exact same
 * governed, row-locked, audited transition primitive
 * (StudentLifecycleService::alignLegacyVerifiedStudent()) as any other
 * student-status change; this command never writes student_status
 * directly. Idempotent and safe to re-run: already-aligned accounts are
 * simply no longer Registered, so they drop out of the candidate query.
 *
 * Never touches Suspended or Archived records (excluded by the base
 * query itself, which only ever selects student_status = Registered).
 */
class ReconcileStudentLifecycleStatus extends Command
{
    protected $signature = 'students:reconcile-lifecycle-status
                            {--apply : Actually align eligible accounts; without this flag, the command only reports counts}';

    protected $description = 'Align eligible legacy verified students from Registered to Active (dry-run by default; pass --apply to mutate)';

    public function handle(StudentLifecycleService $service): int
    {
        $apply = (bool) $this->option('apply');

        $this->printReadOnlyReport();

        $eligibleIds = $this->eligibleCandidateIds();
        $ambiguousIds = $this->ambiguousCandidateIds();

        $this->components->info(sprintf('%d account(s) eligible for Registered -> Active alignment.', count($eligibleIds)));
        $this->components->info(sprintf('%d ambiguous record(s) excluded from automatic reconciliation.', count($ambiguousIds)));

        if (! $apply) {
            $this->components->warn('Dry-run mode — no changes were made. Re-run with --apply to align eligible accounts.');

            return self::SUCCESS;
        }

        $applied = 0;

        User::query()
            ->whereIn('id', $eligibleIds)
            ->chunkById(100, function ($users) use ($service, &$applied): void {
                foreach ($users as $user) {
                    if ($service->alignLegacyVerifiedStudent($user)) {
                        $applied++;
                    }
                }
            });

        $this->components->info(sprintf('Aligned %d account(s) from Registered to Active.', $applied));

        return self::SUCCESS;
    }

    /** Student-role, verified, whole-account-Active, not super_admin/manager, currently Registered. */
    private function baseCandidateQuery(): Builder
    {
        return User::query()
            ->role('student')
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('email_verified_at')
            ->whereDoesntHave('roles', fn (Builder $q) => $q->whereIn('name', ['super_admin', 'manager']))
            ->whereHas('profile', fn (Builder $q) => $q->where('student_status', StudentStatus::Registered->value));
    }

    /**
     * Excludes any candidate with prior 'student' lifecycle audit
     * evidence — under the governed service, a Registered account with
     * existing audit history would mean something previously
     * transitioned it away from Registered and it somehow ended up back
     * there (never expected in normal operation), which is exactly the
     * "no lifecycle audit evidence shows a deliberate prior restriction"
     * exclusion criterion. Treated as ambiguous rather than silently
     * reconciled.
     *
     * @return list<int>
     */
    private function ambiguousCandidateIds(): array
    {
        $candidateIds = $this->baseCandidateQuery()->pluck('users.id');

        if ($candidateIds->isEmpty()) {
            return [];
        }

        return Activity::query()
            ->where('log_name', 'student')
            ->where('subject_type', User::class)
            ->whereIn('subject_id', $candidateIds)
            ->distinct()
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function eligibleCandidateIds(): array
    {
        $ambiguous = $this->ambiguousCandidateIds();

        return $this->baseCandidateQuery()
            ->whereNotIn('users.id', $ambiguous)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** Read-only counts only — never names, emails, or IDs. */
    private function printReadOnlyReport(): void
    {
        $totalStudents = User::query()->role('student')->count();

        $registered = User::query()->role('student')
            ->whereHas('profile', fn (Builder $q) => $q->where('student_status', StudentStatus::Registered->value));

        $this->components->twoColumnDetail('Total student-role users', (string) $totalStudents);
        $this->components->twoColumnDetail('Registered and verified', (string) (clone $registered)->whereNotNull('email_verified_at')->count());
        $this->components->twoColumnDetail('Registered and unverified', (string) (clone $registered)->whereNull('email_verified_at')->count());
        $this->components->twoColumnDetail('Registered with non-Active whole-account status', (string) (clone $registered)->where('status', '!=', User::STATUS_ACTIVE)->count());
        $this->components->twoColumnDetail('Active', (string) User::query()->role('student')->whereHas('profile', fn (Builder $q) => $q->where('student_status', StudentStatus::Active->value))->count());
        $this->components->twoColumnDetail('Suspended', (string) User::query()->role('student')->whereHas('profile', fn (Builder $q) => $q->where('student_status', StudentStatus::Suspended->value))->count());
        $this->components->twoColumnDetail('Archived', (string) User::query()->role('student')->whereHas('profile', fn (Builder $q) => $q->where('student_status', StudentStatus::Archived->value))->count());
        $this->components->twoColumnDetail('Missing profiles', (string) User::query()->role('student')->whereDoesntHave('profile')->count());
        $this->components->twoColumnDetail('Multiple/invalid profiles', (string) $this->multipleProfileUserCount());
        $this->components->twoColumnDetail('Multi-role student/instructor users', (string) User::query()->role('student')->role('instructor')->count());

        $nullStatus = $this->nullStatusStudentCount();
        $this->components->twoColumnDetail('Null/invalid student_status (ambiguous — excluded)', (string) $nullStatus);

        if ($nullStatus > 0) {
            $this->components->warn(sprintf(
                '%d student-role account(s) have a null student_status. This command never infers Active for '.
                'them (student role, verified email, bookings, or prior login are not sufficient evidence). '.
                'Recommended remediation: inspect these accounts individually, initialize genuinely valid ones '.
                'to Registered through a governed action (e.g. StudentLifecycleService::initializeStudentRoleIfNeeded()), '.
                'then let them activate through normal verification or an authorized lifecycle transition — never '.
                'promote them to Active directly as part of a bulk remediation.',
                $nullStatus,
            ));
        }
    }

    /**
     * A null student_status is invalid/ambiguous legacy or
     * incomplete-initialization data — never inferred as Active, and
     * never included in eligibleCandidateIds() (the base query only ever
     * selects student_status = Registered, so null rows never match it).
     * Reported here purely for visibility per Step 4/"Null legacy
     * records" — this command performs no remediation for them.
     */
    private function nullStatusStudentCount(): int
    {
        return User::query()
            ->role('student')
            ->whereHas('profile', fn (Builder $q) => $q->whereNull('student_status'))
            ->count();
    }

    private function multipleProfileUserCount(): int
    {
        return UserProfile::query()
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
    }
}
