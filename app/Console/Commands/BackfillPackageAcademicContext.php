<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InstructorPackageProposal;
use App\Models\User;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use Illuminate\Console\Command;

/**
 * Gives package proposals created before "Lesson packages fund bookings"
 * was switched on the frozen academic context that booking eligibility
 * requires — otherwise a paid package from that time can never fund a
 * lesson (PackageBookingEntitlementResolver fails closed on a missing
 * context).
 *
 * Dry-run by default: prints what each proposal would receive, or the
 * exact reason it cannot be inferred. --apply writes. Inference is
 * refused whenever it would be a guess; --proposal with --system and
 * --level backfills one proposal from explicit ids instead.
 */
final class BackfillPackageAcademicContext extends Command
{
    protected $signature = 'packages:backfill-academic-context
        {--apply : Write the contexts (dry-run otherwise)}
        {--proposal= : Only this proposal id}
        {--system= : Education system id to use (with --proposal)}
        {--level= : Education system level id to use (with --proposal)}
        {--actor= : User id recorded on the audit trail (defaults to the first super admin)}';

    protected $description = 'Freeze the academic context onto legacy package proposals so their paid lessons can fund bookings (dry-run by default; pass --apply to write).';

    public function handle(InstructorPackageProposalService $proposals): int
    {
        $apply = (bool) $this->option('apply');
        $only = $this->option('proposal');
        $systemId = $this->option('system') ?: null;
        $levelId = $this->option('level') ?: null;

        if (($systemId !== null || $levelId !== null) && $only === null) {
            $this->components->error('--system and --level apply to a single proposal; pass --proposal as well.');

            return self::INVALID;
        }

        $actor = $this->resolveActor();

        if ($apply && $actor === null) {
            $this->components->error('No user to record on the audit trail; pass --actor=<user id>.');

            return self::INVALID;
        }

        $candidates = InstructorPackageProposal::query()
            ->whereIn('status', InstructorPackageProposalService::BACKFILLABLE_STATUSES)
            ->whereDoesntHave('academicContext')
            ->when($only !== null, fn ($q) => $q->whereKey($only))
            ->with(['student', 'instructor', 'packageBenefitRule'])
            ->orderBy('created_at')
            ->get();

        if ($candidates->isEmpty()) {
            $this->components->info('No live package proposals are missing an academic context.');

            return self::SUCCESS;
        }

        $rows = [];
        $written = 0;
        $blocked = 0;

        foreach ($candidates as $proposal) {
            try {
                // Plan first so the row reads the same in both modes; the
                // write re-plans internally, which is cheap and keeps the
                // service the only place that decides what is frozen.
                $context = $proposals->planAcademicContextBackfill($proposal, $systemId, $levelId);

                if ($apply) {
                    $proposals->backfillAcademicContext($proposal, $actor, $systemId, $levelId);
                    $written++;
                }

                $rows[] = [
                    $proposal->id,
                    $proposal->status->label(),
                    $proposal->packageBenefitRule?->name ?? '—',
                    $apply ? 'written' : 'would write',
                    sprintf('%s · %s · %s', $context->educationSystemName, $context->levelDisplay, $context->curriculumName),
                ];
            } catch (PackageException $e) {
                $blocked++;
                $rows[] = [$proposal->id, $proposal->status->label(), $proposal->packageBenefitRule?->name ?? '—', 'blocked', $e->getMessage()];
            }
        }

        $this->table(['Proposal', 'Status', 'Offer', 'Outcome', 'Detail'], $rows);

        $this->components->info(sprintf(
            '%d proposal(s) examined, %d %s, %d blocked.%s',
            $candidates->count(),
            $apply ? $written : $candidates->count() - $blocked,
            $apply ? 'written' : 'resolvable',
            $blocked,
            $apply ? '' : ' Dry run — pass --apply to write.',
        ));

        return $blocked > 0 && $candidates->count() === $blocked ? self::FAILURE : self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $id = $this->option('actor');

        if ($id !== null) {
            return User::query()->find($id);
        }

        return User::role('super_admin')->orderBy('id')->first();
    }
}
