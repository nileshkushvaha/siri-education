<?php

declare(strict_types=1);

namespace App\Earnings\Repositories;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class InstructorEarningRepository implements InstructorEarningRepositoryInterface
{
    public function findForLesson(Lesson $lesson): ?InstructorEarning
    {
        return InstructorEarning::query()->where('lesson_id', $lesson->id)->first();
    }

    public function create(array $attributes): InstructorEarning
    {
        return InstructorEarning::query()->create($attributes);
    }

    public function transitionStatus(InstructorEarning $earning, InstructorEarningStatus $status, array $extra = []): InstructorEarning
    {
        $earning->fill([...$extra, 'status' => $status]);
        $earning->save();

        return $earning;
    }

    public function dueForRelease(CarbonInterface $now): LazyCollection
    {
        return InstructorEarning::query()
            ->where('status', InstructorEarningStatus::PendingHold)
            ->where(fn ($q) => $q->whereNull('hold_until')->orWhere('hold_until', '<=', $now))
            ->orderBy('hold_until')
            ->cursor();
    }

    public function settleable(int $instructorId, string $currencyCode, ?CarbonInterface $from = null, ?CarbonInterface $until = null): Collection
    {
        return InstructorEarning::query()
            ->settleable()
            ->where('instructor_id', $instructorId)
            ->where('currency_code', $currencyCode)
            ->when($from, fn ($q) => $q->where('released_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('released_at', '<=', $until))
            ->orderBy('released_at')
            ->get();
    }
}
