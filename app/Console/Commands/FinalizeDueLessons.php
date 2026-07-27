<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lessons\Contracts\LessonFinalizationServiceInterface;
use Illuminate\Console\Command;

/**
 * Evidence-driven finalizer: seals due attendance records,
 * determines each open ended lesson's outcome from the evidence, and
 * finalizes it through the existing outcome pipeline. Idempotent and
 * concurrent-safe; a no-op while
 * lessons.automated_finalization_enabled is off.
 */
class FinalizeDueLessons extends Command
{
    protected $signature = 'lessons:finalize-due';

    protected $description = 'Finalize due lessons from attendance evidence (seal attendance, determine and finalize outcomes)';

    public function handle(LessonFinalizationServiceInterface $finalizer): int
    {
        $finalized = $finalizer->processDue();

        $this->info(sprintf('Finalized %d lesson(s).', $finalized));

        return self::SUCCESS;
    }
}
