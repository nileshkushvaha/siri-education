<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Exceptions\InvalidLessonTransitionException;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

/**
 * The one place a lesson's status is written. Guards every change
 * through LessonStatus::canTransitionTo() — mirrors the booking
 * domain's action-guarded transitions.
 */
final class TransitionLessonAction
{
    public function __construct(
        private readonly LessonRepositoryInterface $lessons,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws InvalidLessonTransitionException
     */
    public function execute(Lesson $lesson, LessonStatus $next, array $extra = []): Lesson
    {
        if (! $lesson->status->canTransitionTo($next)) {
            throw InvalidLessonTransitionException::between($lesson->status, $next);
        }

        return DB::transaction(
            fn (): Lesson => $this->lessons->transitionStatus($lesson, $next, $extra),
        );
    }
}
