<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use Illuminate\Console\Command;

/**
 * Finalizes open lessons past the auto-completion grace period:
 * lessons with a recorded no-show become the matching no-show outcome,
 * everything else auto-completes. Idempotent — finalized lessons never
 * re-enter the sweep.
 */
class AutoCompleteLessons extends Command
{
    protected $signature = 'lessons:auto-complete';

    protected $description = 'Auto-complete open lessons whose end time has passed the grace period';

    public function handle(LessonLifecycleServiceInterface $lessons): int
    {
        $finalized = $lessons->autoCompleteDue();

        $this->info(sprintf('Finalized %d lesson(s).', $finalized));

        return self::SUCCESS;
    }
}
