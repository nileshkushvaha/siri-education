<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Models\Lesson;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired by LessonLifecycleService after every completion (manual, admin, and auto). */
final class LessonCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
    ) {}
}
