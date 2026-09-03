<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\ReferralCode;
use App\Models\User;
use App\Referral\Contracts\ReferralCodeServiceInterface;
use App\Referral\Enums\ReferralCodeStatus;
use App\Referral\Exceptions\ReferralException;
use App\Referral\Services\ReferralCodeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferralCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function service(): ReferralCodeServiceInterface
    {
        return app(ReferralCodeServiceInterface::class);
    }

    private function student(): User
    {
        // student_status, not just the role: ReferralCodeService goes
        // through StudentLifecycleService::assertEligibleForStudentAction(),
        // which requires Active. A bare role assignment leaves the status
        // null, which is denied — see UserFactory::activeStudent().
        return User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
    }

    public function test_student_receives_an_uppercase_code_of_expected_shape(): void
    {
        $code = $this->service()->getOrCreateForStudent($this->student());

        $this->assertSame(ReferralCodeStatus::Active, $code->status);
        $this->assertMatchesRegularExpression('/^[A-HJ-KM-NP-Z2-9]{8}$/', $code->code);
        $this->assertSame(strtoupper($code->code), $code->code);
    }

    public function test_repeated_calls_return_the_same_code_idempotently(): void
    {
        $student = $this->student();

        $first = $this->service()->getOrCreateForStudent($student);
        $second = $this->service()->getOrCreateForStudent($student);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->code, $second->code);
        $this->assertSame(1, ReferralCode::query()->where('user_id', $student->id)->count());
    }

    public function test_instructor_cannot_receive_a_code(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $this->expectException(ReferralException::class);

        $this->service()->getOrCreateForStudent($instructor);
    }

    public function test_disabled_code_is_returned_as_is_and_never_silently_replaced(): void
    {
        $student = $this->student();
        $disabled = ReferralCode::factory()->disabled()->create(['user_id' => $student->id]);

        $returned = $this->service()->getOrCreateForStudent($student);

        $this->assertSame($disabled->id, $returned->id);
        $this->assertSame(ReferralCodeStatus::Disabled, $returned->status);
        $this->assertSame(1, ReferralCode::query()->where('user_id', $student->id)->count());
    }

    public function test_codes_are_unique_per_student_and_globally(): void
    {
        $codeA = $this->service()->getOrCreateForStudent($this->student());
        $codeB = $this->service()->getOrCreateForStudent($this->student());

        $this->assertNotSame($codeA->code, $codeB->code);

        // The DB is the final guard on both invariants.
        $this->expectException(QueryException::class);
        ReferralCode::factory()->create(['user_id' => $codeA->user_id]);
    }

    public function test_duplicate_code_value_is_rejected_by_the_unique_index_case_insensitively(): void
    {
        ReferralCode::factory()->create(['code' => 'ABCD2345']);

        $this->expectException(QueryException::class);

        // utf8mb4_unicode_ci: a case-variant duplicate is still a duplicate.
        ReferralCode::factory()->create(['code' => 'abcd2345']);
    }

    public function test_lookup_is_case_insensitive_and_ignores_disabled_and_malformed_codes(): void
    {
        $student = $this->student();
        $code = $this->service()->getOrCreateForStudent($student);

        $this->assertNotNull($this->service()->findActiveByCode(strtolower($code->code)));
        $this->assertNotNull($this->service()->findActiveByCode('  '.$code->code.'  '));

        $this->assertNull($this->service()->findActiveByCode(null));
        $this->assertNull($this->service()->findActiveByCode(''));
        $this->assertNull($this->service()->findActiveByCode('no'));
        $this->assertNull($this->service()->findActiveByCode('not a code!'));
        $this->assertNull($this->service()->findActiveByCode(str_repeat('A', 40)));

        $this->service()->disable($code, $this->superAdmin(), 'Abuse detected in test.');

        $this->assertNull($this->service()->findActiveByCode($code->code));
    }

    public function test_disable_requires_permission_and_reason_and_is_audit_logged(): void
    {
        $code = $this->service()->getOrCreateForStudent($this->student());

        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        try {
            $this->service()->disable($code, $unauthorized, 'Nope.');
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'DisableReferralCodes', 'guard_name' => 'web']));

        try {
            $this->service()->disable($code, $admin, '   ');
            $this->fail('Expected a ReferralException for the empty reason.');
        } catch (ReferralException) {
            // expected
        }

        $disabled = $this->service()->disable($code, $admin, 'Abuse detected.');

        $this->assertSame(ReferralCodeStatus::Disabled, $disabled->status);
        $this->assertNotNull($disabled->disabled_at);
        $this->assertSame($admin->id, $disabled->disabled_by);
        $this->assertSame('Abuse detected.', $disabled->disable_reason);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'referral_codes',
            'event' => 'disabled',
        ]);
    }

    public function test_generation_retry_is_bounded(): void
    {
        $reflection = new \ReflectionClassConstant(ReferralCodeService::class, 'MAX_GENERATION_ATTEMPTS');

        $this->assertIsInt($reflection->getValue());
        $this->assertGreaterThan(0, $reflection->getValue());
        $this->assertLessThanOrEqual(10, $reflection->getValue());
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        return $admin;
    }
}
