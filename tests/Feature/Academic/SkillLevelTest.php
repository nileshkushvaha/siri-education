<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\SkillLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_skill_level_can_be_created_with_default_active_state(): void
    {
        $skill = SkillLevel::create(['name' => 'Beginner', 'slug' => 'beginner']);

        $this->assertTrue($skill->is_active);
        $this->assertSame(0, $skill->display_order);
    }

    public function test_scope_active_excludes_inactive_skill_levels(): void
    {
        SkillLevel::create(['name' => 'Active Skill', 'slug' => 'active-skill', 'is_active' => true]);
        SkillLevel::create(['name' => 'Inactive Skill', 'slug' => 'inactive-skill', 'is_active' => false]);

        $active = SkillLevel::active()->get();

        $this->assertTrue($active->contains('name', 'Active Skill'));
        $this->assertFalse($active->contains('name', 'Inactive Skill'));
    }
}
