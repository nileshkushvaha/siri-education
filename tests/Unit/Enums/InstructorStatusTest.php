<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\InstructorStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstructorStatusTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function test_every_case_has_a_label(InstructorStatus $status): void
    {
        $this->assertNotSame('', $status->label());
    }

    #[DataProvider('caseProvider')]
    public function test_every_case_has_a_color(InstructorStatus $status): void
    {
        $this->assertNotSame('', $status->color());
    }

    public static function caseProvider(): array
    {
        return array_map(fn (InstructorStatus $status): array => [$status], InstructorStatus::cases());
    }

    public function test_enum_has_exactly_the_required_eleven_lifecycle_states(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'draft',
                'submitted',
                'under_review',
                'documents_pending',
                'interview_required',
                'approved',
                'active',
                'vacation',
                'suspended',
                'archived',
                'rejected',
            ],
            array_map(fn (InstructorStatus $status): string => $status->value, InstructorStatus::cases()),
        );
    }

    public function test_bookable_contains_only_approved_and_active(): void
    {
        $this->assertEqualsCanonicalizing(
            [InstructorStatus::Approved, InstructorStatus::Active],
            InstructorStatus::bookable(),
        );
    }

    public function test_bookable_values_matches_bookable_enum_cases(): void
    {
        $this->assertEqualsCanonicalizing(['approved', 'active'], InstructorStatus::bookableValues());
    }

    #[DataProvider('nonBookableProvider')]
    public function test_non_bookable_statuses_are_excluded_from_bookable(InstructorStatus $status): void
    {
        $this->assertNotContains($status, InstructorStatus::bookable());
    }

    public static function nonBookableProvider(): array
    {
        return array_map(
            fn (InstructorStatus $status): array => [$status],
            array_values(array_filter(
                InstructorStatus::cases(),
                fn (InstructorStatus $status): bool => ! in_array($status, InstructorStatus::bookable(), true),
            )),
        );
    }
}
