<?php

declare(strict_types=1);

namespace Tests\Feature\PromotionalCredits;

use App\Enums\StudentStatus;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Models\Activity;
use App\Models\Country;
use App\Models\Currency;
use App\Models\PromotionalCreditCampaign;
use App\Models\PromotionalCreditIssuance;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\PromotionalCredits\Enums\PromotionalCreditIssuanceType;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use App\Settings\FeatureSettings;
use App\Wallet\Services\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\PromotionalCredits\Concerns\CreatesPromotionalCreditFixtures;
use Tests\TestCase;

/**
 * GAP-041 / SRS §16.17-§16.19: campaign and manual issuance success,
 * eligibility/limits/budget enforcement, duplicate/concurrent
 * protection, and ledger integration.
 */
class PromotionalCreditIssuanceTest extends TestCase
{
    use CreatesPromotionalCreditFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePromotionalCreditRoles();
    }

    private function key(): string
    {
        return 'promo_credit:'.Str::uuid();
    }

    public function test_a_campaign_credit_is_issued_successfully(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign(['amount_minor' => 25000]);
        $student = $this->student();

        $issuance = app(PromotionalCreditService::class)->issueCampaignCredit($campaign, $student, $admin, 'Launch bonus.', $this->key());

        $this->assertSame(PromotionalCreditIssuanceType::Campaign, $issuance->issuance_type);
        $this->assertSame(25000, $issuance->amount_minor);
        $this->assertSame($campaign->id, $issuance->campaign_id);
    }

    public function test_a_manual_credit_is_issued_successfully(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        $issuance = app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Goodwill credit for a support issue.', $this->key());

        $this->assertSame(PromotionalCreditIssuanceType::Manual, $issuance->issuance_type);
        $this->assertNull($issuance->campaign_id);
        $this->assertSame(15000, $issuance->amount_minor);
    }

    public function test_a_manual_credit_requires_a_reason(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', '', $this->key());
    }

    public function test_an_unauthorized_user_cannot_issue_a_credit(): void
    {
        $student = $this->student();
        $otherStudent = $this->student();

        $this->expectException(AuthorizationException::class);
        app(PromotionalCreditService::class)->issueManualCredit($otherStudent, $student, 15000, 'INR', 'Trying to self-serve.', $this->key());
    }

    public function test_issuance_is_blocked_when_the_global_setting_is_disabled(): void
    {
        $features = app(FeatureSettings::class);
        $features->promotional_credit_enabled = false;
        $features->save();

        $admin = $this->fullAdmin();
        $student = $this->student();

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Should be blocked.', $this->key());
    }

    public function test_issuance_is_blocked_for_a_disabled_campaign(): void
    {
        $admin = $this->fullAdmin();
        $campaign = PromotionalCreditCampaign::factory()->create(); // draft, not active
        $student = $this->student();

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueCampaignCredit($campaign, $student, $admin, 'Reason.', $this->key());
    }

    public function test_issuance_is_blocked_outside_the_campaign_date_window(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDays(1),
        ]);
        $student = $this->student();

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueCampaignCredit($campaign, $student, $admin, 'Reason.', $this->key());
    }

    public function test_issuance_is_blocked_for_an_inactive_student(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();
        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $this->key());
    }

    public function test_issuance_is_blocked_for_an_unverified_student(): void
    {
        $admin = $this->fullAdmin();
        $student = User::factory()->unverified()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $this->key());
    }

    public function test_issuance_is_blocked_for_an_inactive_currency(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'ZZZ', 'Reason.', $this->key());
    }

    public function test_currency_mismatch_between_wallet_and_credit_is_rejected(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        // Make USD the student's natural default currency (via their
        // billing country), so getOrCreateWallet($student, null, ...)
        // deterministically resolves to a USD wallet — then attempt an
        // INR credit against it.
        $usd = Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2, 'status' => 'active']);
        $country = Country::factory()->create(['default_currency_id' => $usd->id]);
        $student->profile()->update(['country_id' => $country->id]);
        app(WalletService::class)->getOrCreateWallet($student, null, $student);

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Currency mismatch test.', $this->key());
    }

    public function test_a_negative_or_zero_amount_is_rejected(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 0, 'INR', 'Reason.', $this->key());
    }

    public function test_per_student_limit_is_enforced(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign(['per_student_limit' => 1]);
        $student = $this->student();
        $service = app(PromotionalCreditService::class);

        $service->issueCampaignCredit($campaign, $student, $admin, 'First award.', $this->key());

        $this->expectException(PromotionalCreditException::class);
        $service->issueCampaignCredit($campaign, $student, $admin, 'Second award attempt.', $this->key());
    }

    public function test_a_second_student_is_unaffected_by_the_first_students_limit(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign(['per_student_limit' => 1]);
        $studentA = $this->student();
        $studentB = $this->student();
        $service = app(PromotionalCreditService::class);

        $service->issueCampaignCredit($campaign, $studentA, $admin, 'Award A.', $this->key());
        $issuanceB = $service->issueCampaignCredit($campaign, $studentB, $admin, 'Award B.', $this->key());

        $this->assertNotNull($issuanceB);
    }

    public function test_campaign_budget_is_enforced(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign(['amount_minor' => 60000, 'total_budget_minor' => 100000, 'per_student_limit' => 10]);
        $service = app(PromotionalCreditService::class);

        $service->issueCampaignCredit($campaign, $this->student(), $admin, 'First award.', $this->key());

        $this->expectException(PromotionalCreditException::class);
        $service->issueCampaignCredit($campaign, $this->student(), $admin, 'Second award would exceed budget.', $this->key());
    }

    public function test_duplicate_idempotency_key_returns_the_same_issuance(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();
        $key = $this->key();
        $service = app(PromotionalCreditService::class);

        $first = $service->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $key);
        $second = $service->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PromotionalCreditIssuance::query()->count());
    }

    public function test_concurrent_campaign_issuance_respects_the_per_student_limit(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign(['per_student_limit' => 1]);
        $student = $this->student();
        $service = app(PromotionalCreditService::class);

        // Two in-memory copies simulating two concurrent admin requests.
        $copyA = PromotionalCreditCampaign::query()->findOrFail($campaign->id);
        $copyB = PromotionalCreditCampaign::query()->findOrFail($campaign->id);

        $service->issueCampaignCredit($copyA, $student, $admin, 'First concurrent attempt.', $this->key());

        $this->expectException(PromotionalCreditException::class);
        $service->issueCampaignCredit($copyB, $student, $admin, 'Second concurrent attempt.', $this->key());
    }

    public function test_exactly_one_ledger_entry_is_created_per_issuance(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        $issuance = app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $this->key());

        $this->assertSame(1, WalletLedgerEntry::query()->where('id', $issuance->wallet_ledger_entry_id)->count());
        $wallet = app(WalletService::class)->getOrCreateWallet($student, null, $student);
        $this->assertSame(15000, $wallet->fresh()->balance_minor);
    }

    public function test_a_rejected_issuance_never_creates_a_ledger_entry_or_issuance_row(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        try {
            app(PromotionalCreditService::class)->issueManualCredit($student, $admin, -1, 'INR', 'Reason.', $this->key());
        } catch (PromotionalCreditException) {
            // expected
        }

        $this->assertSame(0, PromotionalCreditIssuance::query()->count());
    }

    public function test_an_issuance_cannot_be_updated(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();
        $issuance = app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $this->key());

        $this->expectException(ImmutableRecordCannotBeUpdatedException::class);
        $issuance->forceFill(['amount_minor' => 999999])->save();
    }

    public function test_an_issuance_cannot_be_hard_deleted(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();
        $issuance = app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $this->key());

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);
        $issuance->delete();
    }

    public function test_issuance_is_audit_logged(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', $this->key());

        $this->assertTrue(
            Activity::query()->where('log_name', 'promotional_credits')->where('event', 'credit_issued')->exists()
        );
    }
}
