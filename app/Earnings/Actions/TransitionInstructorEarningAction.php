<?php

declare(strict_types=1);

namespace App\Earnings\Actions;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Exceptions\InvalidEarningTransitionException;
use App\Models\InstructorEarning;
use Illuminate\Support\Facades\DB;

/**
 * The one place an earning's status is written. Guards every change
 * through InstructorEarningStatus::canTransitionTo().
 */
final class TransitionInstructorEarningAction
{
    public function __construct(
        private readonly InstructorEarningRepositoryInterface $earnings,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws InvalidEarningTransitionException
     */
    public function execute(InstructorEarning $earning, InstructorEarningStatus $next, array $extra = []): InstructorEarning
    {
        if (! $earning->status->canTransitionTo($next)) {
            throw InvalidEarningTransitionException::between($earning->status, $next);
        }

        return DB::transaction(
            fn (): InstructorEarning => $this->earnings->transitionStatus($earning, $next, $extra),
        );
    }
}
