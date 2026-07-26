<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\AssignmentCriteriaData;
use App\Models\SubjectTopic;
use App\Models\User;
use Illuminate\Support\Collection;

interface TeacherCandidateRepositoryInterface
{
    /**
     * Teachers who can teach the requested subject at the requested
     * grade and are approved/published instructors. Availability is
     * NOT checked here — the assignment service filters it.
     *
     * @return Collection<int, User> with profile eager-loaded
     */
    public function eligible(AssignmentCriteriaData $criteria): Collection;

    /** @return Collection<int, string> distinct subjects offered by approved/published teachers */
    public function availableSubjects(): Collection;

    /** The teacher matches subject/grade and is an approved/published instructor. */
    public function isEligible(int $teacherId, AssignmentCriteriaData $criteria): bool;

    /** The user is an approved/published instructor (no subject check). */
    public function isApprovedTeacher(int $teacherId): bool;

    /**
     * The teacher has active, admin-approved coverage for this exact
     * topic, at a level covering $grade (null coverage
     * level = all levels; null $grade = any level accepted). Explicit
     * coverage is required when a topic is selected — whole-subject
     * rows never imply topic coverage.
     */
    public function teachesTopic(int $teacherId, SubjectTopic $topic, ?int $grade): bool;
}
