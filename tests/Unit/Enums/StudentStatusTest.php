<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\StudentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentStatusTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function test_every_case_has_a_label(StudentStatus $status): void
    {
        $this->assertNotSame('', $status->label());
    }

    #[DataProvider('caseProvider')]
    public function test_every_case_has_a_color(StudentStatus $status): void
    {
        $this->assertNotSame('', $status->color());
    }

    public static function caseProvider(): array
    {
        return array_map(fn (StudentStatus $status): array => [$status], StudentStatus::cases());
    }

    public function test_enum_has_exactly_the_required_four_lifecycle_states(): void
    {
        $this->assertEqualsCanonicalizing(
            ['registered', 'active', 'suspended', 'archived'],
            array_map(fn (StudentStatus $status): string => $status->value, StudentStatus::cases()),
        );
    }
}
