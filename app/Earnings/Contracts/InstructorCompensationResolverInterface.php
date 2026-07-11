<?php

declare(strict_types=1);

namespace App\Earnings\Contracts;

use App\Earnings\DTOs\CompensationResolution;
use App\Models\Lesson;

interface InstructorCompensationResolverInterface
{
    /**
     * Resolve the compensation for one eligible completed lesson from
     * the instructor's agreement in force at the completion instant.
     *
     * Receives only compensation-relevant inputs (instructor, lesson
     * timing/duration, subject, education level, demo flag). Student
     * price, wallet, payment, invoice, margin, discount, and gateway
     * amounts are not parameters and can never influence the result.
     *
     * Returns null with an audited reason when no earning should be
     * created (no applicable agreement — blocked for controlled retry;
     * periodic basis — base pay accrues per period; demo policy none).
     * Never falls back to percentage, student price, or a global fixed
     * amount, and never invents a zero earning.
     */
    public function resolveForLesson(Lesson $lesson): ?CompensationResolution;
}
