<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Marketplace;

use App\Reporting\DTOs\Operations\LabeledCountRow;

/**
 * Phase 18H — marketplace supply. An instructor is a user holding the
 * `instructor` role (identical to the Phase 18D predicate); lifecycle
 * counts read the CURRENT `user_profiles.instructor_status`. Every
 * figure here is current-state (no period applies to supply) and the
 * subject breakdown counts CURRENT subject assignment
 * (`teacher_subjects`) — explicitly labelled, never treated as
 * historical supply. Published weekly availability hours stay owned by
 * the Instructor Performance report (Phase 18D §6.5) and are not
 * recomputed here; no utilization rate exists anywhere.
 *
 * @param  array<string, int>  $byStatus  InstructorStatus::value => count (all cases, zero-filled)
 * @param  list<LabeledCountRow>  $bySubject  distinct active instructors per current subject assignment
 * @param  list<LabeledCountRow>  $byCountry  active instructors per current profile country
 */
final readonly class MarketplaceSupplyData
{
    public function __construct(
        public int $totalInstructors,
        public array $byStatus,
        public int $activeInstructors,
        public int $approvedInstructors,
        public int $onVacation,
        public int $suspended,
        public int $activeWithPublishedAvailability,
        public int $activeWithoutPublishedAvailability,
        public array $bySubject,
        public array $byCountry,
    ) {}
}
