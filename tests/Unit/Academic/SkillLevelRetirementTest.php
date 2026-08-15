<?php

declare(strict_types=1);

namespace Tests\Unit\Academic;

use App\Filament\Navigation\NavigationRegistry;
use App\Livewire\Frontend\Instructor\OnboardingWizard;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

class SkillLevelRetirementTest extends TestCase
{
    public function test_skill_level_domain_and_admin_route_are_retired(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/SkillLevel.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/Academic/SkillLevelResource.php'));
        $this->assertNull(Route::getRoutes()->getByName('filament.admin.resources.academic.skill-levels.index'));

        foreach (array_keys(NavigationRegistry::destinations()) as $destination) {
            $this->assertStringNotContainsString('SkillLevel', $destination);
        }
    }

    public function test_instructor_onboarding_and_profile_no_longer_expose_skill_levels(): void
    {
        $wizard = new ReflectionClass(OnboardingWizard::class);

        $this->assertFalse($wizard->hasProperty('skillLevelIds'));
        $this->assertFalse($wizard->hasProperty('skillLevels'));
        $this->assertNotContains('instructor_skill_level_ids', (new UserProfile)->getFillable());
        $this->assertStringNotContainsString(
            'Skill Levels',
            file_get_contents(resource_path('views/livewire/frontend/instructor/onboarding-wizard.blade.php')),
        );
    }
}
