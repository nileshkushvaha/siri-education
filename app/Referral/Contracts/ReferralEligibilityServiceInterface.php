<?php

declare(strict_types=1);

namespace App\Referral\Contracts;

use App\Models\Lesson;
use App\Models\ReferralReward;

interface ReferralEligibilityServiceInterface
{
    /**
     * Evaluate a finalized-Completed lesson for referral reward
     * eligibility (SRS 16.11). Runs every gate — feature switch,
     * attribution, student-only roles, referrer eligibility, paid
     * booking type, captured positive payment, applicable active
     * campaign (window matched at the lesson's finalization instant),
     * country scope, minimum-lesson threshold, class cap — then inserts
     * the reward row inside one locked transaction. Returns null when
     * any gate fails or another worker already rewarded the lesson;
     * never throws for a merely-ineligible lesson.
     */
    public function evaluateCompletedLesson(Lesson $lesson): ?ReferralReward;
}
