<?php

declare(strict_types=1);

namespace App\Earnings\Repositories;

use App\DTOs\InstructorDashboard\InstructorFinanceSummaryData;
use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class InstructorEarningRepository implements InstructorEarningRepositoryInterface
{
    public function findForLesson(Lesson $lesson): ?InstructorEarning
    {
        return InstructorEarning::query()->where('lesson_id', $lesson->id)->first();
    }

    public function findBySource(string $sourceType, string $sourceId): ?InstructorEarning
    {
        return InstructorEarning::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
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

    public function settleable(int $instructorId, string $currencyCode, ?CarbonInterface $from = null, ?CarbonInterface $until = null, bool $forUpdate = false): Collection
    {
        return InstructorEarning::query()
            ->settleable()
            ->where('instructor_id', $instructorId)
            ->where('currency_code', $currencyCode)
            ->when($from, fn ($q) => $q->where('released_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('released_at', '<=', $until))
            ->orderBy('released_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->when($forUpdate, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    public function paginatedForInstructor(int $instructorId, int $perPage = 20): LengthAwarePaginator
    {
        return InstructorEarning::query()
            ->forInstructor($instructorId)
            ->with([
                'lesson:id,starts_at,status',
                'student:id,first_name,last_name,name',
                'subject:id,name',
                'settlementBatch:id,batch_reference,status',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function summaryForInstructor(int $instructorId): Collection
    {
        return InstructorEarning::query()
            ->forInstructor($instructorId)
            ->selectRaw(
                'currency_code,
                 SUM(CASE WHEN status = ? THEN earning_amount_minor ELSE 0 END) as pending_hold_minor,
                 SUM(CASE WHEN status = ? THEN earning_amount_minor ELSE 0 END) as releasable_minor,
                 SUM(CASE WHEN status = ? THEN earning_amount_minor ELSE 0 END) as settled_minor,
                 SUM(earning_amount_minor) as total_minor',
                [
                    InstructorEarningStatus::PendingHold->value,
                    InstructorEarningStatus::Releasable->value,
                    InstructorEarningStatus::Settled->value,
                ],
            )
            ->groupBy('currency_code')
            ->get()
            ->map(fn (object $row): InstructorFinanceSummaryData => new InstructorFinanceSummaryData(
                currencyCode: $row->currency_code,
                pendingHoldMinor: (int) $row->pending_hold_minor,
                releasableMinor: (int) $row->releasable_minor,
                settledMinor: (int) $row->settled_minor,
                totalEarnedMinor: (int) $row->total_minor,
            ));
    }
}
