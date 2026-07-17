<?php

declare(strict_types=1);

namespace Tests\Feature\Referral\Concurrency;

use App\Enums\StudentStatus;
use App\Models\ReferralAttribution;
use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Settings\FeatureSettings;
use Spatie\Permission\Models\Role;

/**
 * Real multi-process races for the Phase 19B referral invariants: the
 * user_id unique index makes code generation single-winner, and the
 * referred_student_id unique index makes attribution single-winner.
 * Both losers must degrade gracefully (same code returned / null),
 * never error out of a registration.
 */
class ReferralConcurrencyTest extends ConcurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $features = app(FeatureSettings::class);
        $features->referral_enabled = true;
        $features->save();
    }

    private function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile?->update(['student_status' => StudentStatus::Active]);

        return $student;
    }

    public function test_concurrent_code_generation_creates_exactly_one_code(): void
    {
        $student = $this->activeStudent();

        $results = $this->race([
            ['referral-generate-code', ['student_id' => $student->id]],
            ['referral-generate-code', ['student_id' => $student->id]],
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame($results[0]['result']['code'], $results[1]['result']['code'], json_encode($results));

        $this->assertSame(1, ReferralCode::query()->where('user_id', $student->id)->count());
    }

    public function test_concurrent_attribution_attempts_create_exactly_one_attribution(): void
    {
        $codeA = app(ReferralCodeServiceInterface::class)->getOrCreateForStudent($this->activeStudent());
        $codeB = app(ReferralCodeServiceInterface::class)->getOrCreateForStudent($this->activeStudent());

        $referred = $this->activeStudent();

        $results = $this->race([
            ['referral-attribute', ['referred_student_id' => $referred->id, 'code' => $codeA->code]],
            ['referral-attribute', ['referred_student_id' => $referred->id, 'code' => $codeB->code]],
        ]);

        // Both calls must complete without error — the loser silently
        // returns null exactly as it would inside a live registration.
        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));

        $winners = array_values(array_filter([
            $results[0]['result']['attribution_id'],
            $results[1]['result']['attribution_id'],
        ]));

        $this->assertCount(1, $winners, json_encode($results));
        $this->assertSame(1, ReferralAttribution::query()->where('referred_student_id', $referred->id)->count());
    }
}
