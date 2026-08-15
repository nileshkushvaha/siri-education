<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\AcademicLevel;
use App\Models\CountryEducationSystem;
use App\Models\Curriculum;
use App\Models\CurriculumModule;
use App\Models\CurriculumModuleTopic;
use App\Models\CurriculumVersion;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\PackageBenefitRule;
use App\Models\Subject;
use App\Models\SubjectTopic;
use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InternationalAcademicCatalogueSeeder;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InternationalAcademicCatalogueSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_idempotent_grade_six_to_twelve_catalogue(): void
    {
        $this->seed([
            CurrencySeeder::class,
            LanguageSeeder::class,
            CountrySeeder::class,
            InternationalAcademicCatalogueSeeder::class,
            InternationalAcademicCatalogueSeeder::class,
        ]);

        $this->assertSame(22, Subject::query()->count());
        $this->assertSame(110, SubjectTopic::query()->count());
        $this->assertSame(7, Subject::query()->whereIn('name', [
            'Further Mathematics',
            'Astronomy',
            'Hindi',
            'Artificial Intelligence',
            'Accounting',
            'Legal Studies',
            'Robotics',
        ])->count());

        $this->assertSame(9, EducationSystem::query()->count());
        $this->assertSame(9, CountryEducationSystem::query()->count());
        $this->assertSame(63, EducationSystemLevel::query()->count());

        EducationSystem::query()->each(function (EducationSystem $system): void {
            $this->assertSame(
                range(6, 12),
                $system->levels()->orderBy('normalized_grade')->pluck('normalized_grade')->all(),
            );
        });

        $this->assertSame(6, AcademicLevel::query()->where('slug', 'middle-school')->value('min_grade'));
        $this->assertSame(12, AcademicLevel::query()->where('slug', 'high-school')->value('max_grade'));

        $expectedCurricula = 9 * 22 * 2;
        $this->assertSame($expectedCurricula, Curriculum::query()->count());
        $this->assertSame($expectedCurricula, CurriculumVersion::query()->where('status', 'draft')->count());
        $this->assertSame($expectedCurricula, CurriculumModule::query()->count());
        $this->assertSame($expectedCurricula * 5, CurriculumModuleTopic::query()->count());
        $this->assertSame(4, PackageBenefitRule::query()->count());
    }
}
