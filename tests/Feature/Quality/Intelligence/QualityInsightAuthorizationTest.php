<?php

declare(strict_types=1);

namespace Tests\Feature\Quality\Intelligence;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Filament\Resources\AiQualityInsights\AiQualityInsightResource;
use App\Filament\Resources\AiQualityInsights\Pages\ListAiQualityInsights;
use App\Filament\Resources\AiQualityInsights\Pages\ViewAiQualityInsight;
use App\Models\AiQualityInsight;
use App\Models\User;
use App\Policies\AiQualityInsightPolicy;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\Enums\QualityInsightStatus;
use App\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Quality\Intelligence\Concerns\BuildsQualityInsightFixtures;
use Tests\TestCase;

/**
 * Who may see, generate and review an AI quality insight.
 *
 * The instructor case is the one that matters most: an insight is a
 * model's hedged, unreviewed observation about a real person's work,
 * and it must never reach that person before — or after — an
 * administrator has read it.
 */
class QualityInsightAuthorizationTest extends TestCase
{
    use BuildsQualityInsightFixtures, RefreshDatabase;

    private function readyInsight(?User $instructor = null): AiQualityInsight
    {
        $this->enableQualityInsights();

        $settings = app(AiSettings::class);
        $settings->provider = 'openai';
        $settings->openai_api_key = Crypt::encryptString('sk-test-key');
        $settings->save();

        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'summary' => 'A short period with a handful of lessons and consistently positive written feedback from students.',
                'strengths' => ['Clear explanations are mentioned repeatedly.'],
                'concerns' => [],
                'recommended_review' => '',
                'confidence' => 0.5,
                'requires_human_review' => true,
            ], JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ])]);

        $instructor ??= $this->instructor();
        $this->publishedReview($instructor, $this->student(), 5, 'Explains difficult ideas very clearly.');

        return app(QualityInsightServiceInterface::class)
            ->request($instructor, $this->period(), $this->admin())
            ->refresh();
    }

    // ── Admin ─────────────────────────────────────────────────────────

    public function test_an_admin_with_the_permissions_can_list_and_view(): void
    {
        $insight = $this->readyInsight();

        $this->actingAs($this->admin());

        Livewire::test(ListAiQualityInsights::class)->assertOk();
        Livewire::test(ViewAiQualityInsight::class, ['record' => $insight->getKey()])->assertOk();
    }

    public function test_a_super_admin_always_has_access(): void
    {
        $insight = $this->readyInsight();

        $this->actingAs($this->superAdmin());

        $this->assertTrue(AiQualityInsightResource::canViewAny());
        Livewire::test(ViewAiQualityInsight::class, ['record' => $insight->getKey()])->assertOk();
    }

    // ── Instructors and students ──────────────────────────────────────

    public function test_an_instructor_cannot_see_insights_about_themselves(): void
    {
        $instructor = $this->instructor();
        $insight = $this->readyInsight($instructor);

        $this->actingAs($instructor);

        $this->assertFalse($instructor->can('viewAny', AiQualityInsight::class));
        $this->assertFalse($instructor->can('view', $insight));
        $this->assertFalse($instructor->can('generate', AiQualityInsight::class));
        $this->assertFalse($instructor->can('review', $insight));
    }

    public function test_a_student_has_no_access_at_all(): void
    {
        $insight = $this->readyInsight();
        $student = $this->student();

        $this->actingAs($student);

        $this->assertFalse($student->can('viewAny', AiQualityInsight::class));
        $this->assertFalse($student->can('view', $insight));
    }

    public function test_the_admin_pages_are_unreachable_over_http_for_an_instructor(): void
    {
        $insight = $this->readyInsight();

        $this->actingAs($this->instructor('Other', 'Teacher'))
            ->get(AiQualityInsightResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($this->instructor('Third', 'Teacher'))
            ->get(AiQualityInsightResource::getUrl('view', ['record' => $insight]))
            ->assertForbidden();
    }

    // ── Separated rights ──────────────────────────────────────────────

    public function test_viewing_does_not_imply_generating(): void
    {
        $this->readyInsight();

        $viewer = $this->admin(['ViewAny:AiQualityInsight', 'View:AiQualityInsight']);

        $this->actingAs($viewer);

        $this->assertTrue($viewer->can('viewAny', AiQualityInsight::class));
        $this->assertFalse($viewer->can('generate', AiQualityInsight::class));

        // The generate action is not even offered.
        Livewire::test(ListAiQualityInsights::class)->assertActionHidden('generate');
    }

    public function test_viewing_does_not_imply_reviewing(): void
    {
        $insight = $this->readyInsight();

        $viewer = $this->admin(['ViewAny:AiQualityInsight', 'View:AiQualityInsight']);

        $this->actingAs($viewer);

        $this->assertFalse($viewer->can('review', $insight));

        Livewire::test(ViewAiQualityInsight::class, ['record' => $insight->getKey()])
            ->assertActionHidden('markReviewed');
    }

    public function test_a_reviewer_can_mark_an_insight_reviewed_from_the_admin_page(): void
    {
        $insight = $this->readyInsight();
        $reviewer = $this->admin();

        $this->actingAs($reviewer);

        Livewire::test(ViewAiQualityInsight::class, ['record' => $insight->getKey()])
            ->callAction('markReviewed', ['note' => 'Checked the recent lessons.']);

        $insight->refresh();

        $this->assertSame(QualityInsightStatus::Reviewed, $insight->status);
        $this->assertSame($reviewer->id, $insight->reviewed_by);
    }

    // ── Read-only by construction ─────────────────────────────────────

    public function test_insights_can_never_be_created_edited_or_deleted_by_hand(): void
    {
        $insight = $this->readyInsight();
        $admin = $this->superAdmin();

        $this->assertFalse(AiQualityInsightResource::canCreate());
        $this->assertFalse(AiQualityInsightResource::canEdit($insight));
        $this->assertFalse(AiQualityInsightResource::canDelete($insight));

        // Even a super admin's policy answer is false — these abilities
        // return false unconditionally rather than being permission-gated.
        $this->assertFalse(app(AiQualityInsightPolicy::class)->update($admin, $insight));
        $this->assertFalse(app(AiQualityInsightPolicy::class)->delete($admin, $insight));
    }

    public function test_an_insight_is_a_historical_record_and_cannot_be_deleted(): void
    {
        $insight = $this->readyInsight();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $insight->delete();
    }
}
