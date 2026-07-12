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
     * $force bypasses the state machine and is reserved for
     * OverrideLessonOutcomeAction's admin corrections — an authorized,
     * reasoned, audited override may need to move a finalized lesson
     * between outcomes the normal machine forbids.
     *
     * @param  array<string, mixed>  $extra
     *
     * @throws InvalidLessonTransitionException
     */
    public function execute(Lesson $lesson, LessonStatus $next, array $extra = [], bool $force = false): Lesson
    {
        if (! $force && ! $lesson->status->canTransitionTo($next)) {
            throw InvalidLessonTransitionException::between($lesson->status, $next);
        }

        return DB::transaction(
            fn (): Lesson => $this->lessons->transitionStatus($lesson, $next, $extra),
        );
    }
}
