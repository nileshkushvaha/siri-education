<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Models\User;
use App\Services\Instructor\InstructorProfileTextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers InstructorProfileTextResolver — the field-reconciliation
 * decision.
 *
 * Note on scope vs. the original ask: a "designation fallback" case is not
 * covered here because `user_profiles.designation` no longer exists — the
 * audit (see the resolver's own docblock) found it was write-only
 * everywhere in the app (never read by any public view/card/search/SEO
 * output), so per explicit instruction this is a dev-mode cleanup with no
 * legacy data to preserve, it was dropped outright rather than kept as a
 * fallback nothing would ever populate. `short_bio`, by contrast, WAS found
 * to be a genuinely distinct, actively-used field (a hand-written
 * marketplace-card excerpt, not a duplicate of `bio`), so it was kept and
 * summary() covers its fallback behavior below.
 */
final class InstructorProfileFieldResolverTest extends TestCase
{
    use RefreshDatabase;

    private InstructorProfileTextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(InstructorProfileTextResolver::class);
    }

    public function test_headline_resolves_from_profile_headline(): void
    {
        $user = $this->userWithProfile(['headline' => 'Senior Mathematics Tutor']);

        $this->assertSame('Senior Mathematics Tutor', $this->resolver->headline($user));
    }

    public function test_headline_is_null_when_unset(): void
    {
        $user = $this->userWithProfile([]);

        $this->assertNull($this->resolver->headline($user));
    }

    public function test_biography_resolves_from_profile_bio(): void
    {
        $user = $this->userWithProfile(['bio' => 'A long, full biography paragraph.']);

        $this->assertSame('A long, full biography paragraph.', $this->resolver->biography($user));
    }

    public function test_summary_prefers_short_bio_when_present(): void
    {
        $user = $this->userWithProfile([
            'short_bio' => 'Hand-written short excerpt.',
            'bio' => str_repeat('Full biography text. ', 20),
        ]);

        $this->assertSame('Hand-written short excerpt.', $this->resolver->summary($user));
    }

    public function test_summary_falls_back_to_a_truncated_bio_when_short_bio_is_blank(): void
    {
        $bio = str_repeat('word ', 60);
        $user = $this->userWithProfile(['short_bio' => null, 'bio' => $bio]);

        $summary = $this->resolver->summary($user, 20);

        $this->assertNotNull($summary);
        $this->assertLessThanOrEqual(24, mb_strlen($summary)); // 20 + ellipsis
        $this->assertStringStartsWith('word', $summary);
    }

    public function test_summary_is_null_when_neither_field_is_set(): void
    {
        $user = $this->userWithProfile([]);

        $this->assertNull($this->resolver->summary($user));
    }

    private function userWithProfile(array $profileData): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->profile->update($profileData);

        return $user->fresh();
    }
}
