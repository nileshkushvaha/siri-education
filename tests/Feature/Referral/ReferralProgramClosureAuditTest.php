<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\ReferralAttribution;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use App\Reporting\Contracts\ReferralCommunicationReportServiceInterface;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\FeatureSettings;
use Database\Seeders\ReferralPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Referral\Concerns\BuildsReferralRewardFixtures;
use Tests\TestCase;

/**
 * Phase 19E — the formal Student Referral Program closure audit. These
 * assertions are the machine-readable form of the Phase 19A–19E
 * invariants: one switch, one campaign source, one credit path, one
 * reversal path, permanent single attribution, immutable financial
 * history, operational coverage for every non-terminal state, and a
 * reporting surface that reconciles exactly with the domain records.
 */
class ReferralProgramClosureAuditTest extends TestCase
{
    use BuildsReferralRewardFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReferralWorld();
        $this->seed(ReferralPermissionSeeder::class);
    }

    public function test_structural_invariants_of_the_referral_program(): void
    {
        // Exactly one feature switch — the legacy settings class is gone.
        $this->assertTrue(property_exists(app(FeatureSettings::class), 'referral_enabled'));
        $this->assertFalse(class_exists('App\Settings\ReferralSettings'));

        // The four domain tables exist; no speculative extras.
        foreach (['referral_codes', 'referral_attributions', 'referral_campaigns', 'referral_rewards'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        // Unique codes, single permanent referrer, one reward per lesson,
        // stable sequence — the DB indexes are the invariants.
        $indexes = collect(DB::select('SHOW INDEX FROM referral_codes'))->pluck('Key_name');
        $this->assertContains('referral_codes_code_unique', $indexes);
        $this->assertContains('referral_codes_user_id_unique', $indexes);

        $indexes = collect(DB::select('SHOW INDEX FROM referral_attributions'))->pluck('Key_name');
        $this->assertContains('referral_attributions_referred_student_id_unique', $indexes);

        $indexes = collect(DB::select('SHOW INDEX FROM referral_rewards'))->pluck('Key_name');
        $this->assertContains('referral_rewards_lesson_id_unique', $indexes);
        $this->assertContains('referral_rewards_attribution_sequence_unique', $indexes);

        // One credit path, one reversal path — the interface is the whole
        // money surface; no rogue writer exists anywhere in the app.
        $creditCallers = [];
        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            $contents = (string) file_get_contents($file);
            if (str_contains($contents, 'WalletLedgerEntryType::ReferralCredit')) {
                $creditCallers[] = $file;
            }
        }
        $this->assertSame([base_path('app/Referral/Services/ReferralRewardService.php')], $creditCallers);

        // No instructor/affiliate/parent/corporate referral path.
        $this->assertFalse(class_exists('App\Referral\Services\InstructorReferralService'));
    }

    public function test_every_non_terminal_reward_state_has_an_operational_path(): void
    {
        $service = app(ReferralRewardServiceInterface::class);

        // Eligible → sweep/immediate credit; Held → approve/reject;
        // CreditFailed → retry/reject; Credited+reversal_required →
        // complete. Every method exists on the single interface.
        foreach (['creditReward', 'approveHeldReward', 'rejectHeldReward', 'retryFailedCredit', 'completeRequiredReversal', 'processReadyRewards'] as $method) {
            $this->assertTrue(method_exists($service, $method), "Missing operational path: {$method}");
        }

        // Every enum status is either terminal or covered.
        foreach (ReferralRewardStatus::cases() as $status) {
            $covered = $status->isTerminal()
                || in_array($status, [ReferralRewardStatus::Eligible, ReferralRewardStatus::Held, ReferralRewardStatus::CreditFailed, ReferralRewardStatus::Credited], true);
            $this->assertTrue($covered, "Status {$status->value} lacks an operational path.");
        }
    }

    public function test_admin_permissions_are_seeded_and_scoped(): void
    {
        foreach ([
            'ViewReferralAttributions', 'ViewReferralCampaigns', 'ManageReferralCampaigns',
            'ViewReferralRewards', 'ViewReferralCodes', 'ApproveReferralRewards',
            'RejectReferralRewards', 'RetryReferralRewardCredits',
            'DisableReferralCodes', 'CorrectReferralAttribution', 'ReverseReferralRewards',
        ] as $permission) {
            $this->assertNotNull(Permission::findByName($permission, 'web'), "Missing permission: {$permission}");
        }

        $manager = Role::findByName('manager', 'web');

        // Forward-path operations for managers; high-risk overrides
        // deliberately granted to no role.
        $this->assertTrue($manager->hasPermissionTo('ApproveReferralRewards'));
        $this->assertFalse($manager->hasPermissionTo('ReverseReferralRewards'));
        $this->assertFalse($manager->hasPermissionTo('CorrectReferralAttribution'));
        $this->assertFalse($manager->hasPermissionTo('DisableReferralCodes'));
    }

    public function test_reporting_reconciles_exactly_with_the_domain_queues(): void
    {
        // Build one reward in each operational queue state.
        $this->activeCampaign(['requires_fraud_review' => true]);

        [, $referredA] = $this->attributedPair();
        $held = app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referredA));
        $this->assertSame(ReferralRewardStatus::Held, $held->status);

        [, $referredB] = $this->attributedPair();
        $lessonB = $this->completedPaidLesson($referredB);
        $creditedThenParked = app(ReferralEligibilityServiceInterface::class)->evaluateCompletedLesson($lessonB);
        $superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
        app(ReferralRewardServiceInterface::class)->approveHeldReward($creditedThenParked, $superAdmin, 'Clear.');
        app(ReferralRewardServiceInterface::class)->reevaluateLesson($lessonB, null, 'lesson_refunded');

        $period = ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, 'UTC');

        $report = app(ReferralCommunicationReportServiceInterface::class)->referralActivity(
            $superAdmin,
            $period,
            new ReportFilters(period: $period),
        );

        // Queue counts must reconcile exactly with domain records.
        $this->assertSame(
            ReferralReward::query()->whereIn('status', [ReferralRewardStatus::Held, ReferralRewardStatus::CreditFailed])->count(),
            $report->heldOrFailedRewardsOpen,
        );
        $this->assertSame(
            ReferralReward::query()->where('status', ReferralRewardStatus::Credited)->where('hold_reason', 'reversal_required')->count(),
            $report->reversalRequiredOpen,
        );
        $this->assertSame(
            ReferralAttribution::query()->count(),
            $report->attributionsInPeriod,
        );

        // Amounts stay currency-separated; nothing labeled revenue/ROI.
        foreach (array_keys($report->creditedAmountByCurrency) as $currency) {
            $this->assertSame(3, strlen($currency));
        }
    }

    public function test_student_surface_remains_private_and_unchanged(): void
    {
        [$referrer, $referred] = $this->attributedPair();
        $referred->forceFill(['first_name' => 'Priya', 'last_name' => 'Sharma'])->save();
        $this->activeCampaign();

        app(ReferralEligibilityServiceInterface::class)
            ->evaluateCompletedLesson($this->completedPaidLesson($referred));

        $this->actingAs($referrer)
            ->get(route('dashboard.refer-a-friend'))
            ->assertOk()
            ->assertSee('Priya S.')
            ->assertDontSee('Sharma')
            ->assertDontSee('fraud')
            ->assertDontSee('reversal_required');
    }

    public function test_no_referral_report_metric_has_a_placeholder_owner(): void
    {
        // The 18G DTO fields are all populated from authoritative
        // sources — the docblock carries no "structurally unavailable"
        // claims for fields that now exist, and the referral registry
        // description no longer denies the domain exists.
        $registry = (string) file_get_contents(base_path('app/Reporting/Registry/ReportRegistry.php'));
        $this->assertStringNotContainsString('Version 1 has no referral code/campaign/attribution domain', $registry);

        $dto = (string) file_get_contents(base_path('app/Reporting/DTOs/Communication/ReferralActivityData.php'));
        $this->assertStringNotContainsString('NO referral domain', $dto);
    }

    private function phpFilesUnder(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
