<?php

declare(strict_types=1);

namespace App\Lessons\Repositories;

use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\Lesson;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

final class LessonRepository implements LessonRepositoryInterface
{
    public function findForBooking(Booking $booking): ?Lesson
    {
        return Lesson::query()->where('booking_id', $booking->id)->first();
    }

    public function create(array $attributes): Lesson
    {
        return Lesson::query()->create($attributes);
    }

    public function transitionStatus(Lesson $lesson, LessonStatus $status, array $extra = []): Lesson
    {
        $lesson->fill([...$extra, 'status' => $status]);
        $lesson->save();

        return $lesson;
    }

    public function openEndedBefore(CarbonInterface $cutoff): LazyCollection
    {
        return Lesson::query()
            ->open()
            ->where('ends_at', '<=', $cutoff)
            ->orderBy('ends_at')
            ->cursor();
    }
}
