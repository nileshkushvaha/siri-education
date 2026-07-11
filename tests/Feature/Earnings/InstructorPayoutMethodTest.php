<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\DTOs\PayoutMethodDetails;
use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\PayoutMethodType;
use App\Earnings\Exceptions\InvalidPayoutMethodTransitionException;
use App\Earnings\Exceptions\PayoutMethodException;
use App\Enums\InstructorStatus;
use App\Models\Activity;
use App\Models\Currency;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorPayoutMethodTest extends TestCase
{
    use RefreshDatabase;

    private InstructorPayoutMethodServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InstructorPayoutMethodServiceInterface::class);

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);
    }

    // ── Creation & eligibility ───────────────────────────────────────

    public function test_instructor_can_create_own_draft_with_masked_label(): void
    {
        $instructor = $this->makeInstructor();

        $method = $this->service->createDraft($instructor, PayoutMethodType::BankTransfer, null, 'INR', $this->details());

        $this->assertSame(PayoutMethodStatus::Draft, $method->status);
        $this->assertSame($instructor->id, $method->instructor_id);
        $this->assertSame('Bank Transfer ending in 4821', $method->display_label);
        $this->assertSame('Account ending in 4821', $method->masked_identifier);
        $this->assertFalse($method->is_default);
    }

    public function test_non_instructor_cannot_create_a_payout_method(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        $this->expectException(PayoutMethodException::class);

        $this->service->createDraft($student, PayoutMethodType::BankTransfer, null, 'INR', $this->details());
    }

    public function test_unapproved_instructor_cannot_create_a_payout_method(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Submitted]);

        $this->expectException(PayoutMethodException::class);

        $this->service->createDraft($user, PayoutMethodType::BankTransfer, null, 'INR', $this->details());
    }

    public function test_unsupported_currency_is_rejected(): void
    {
        $this->expectException(PayoutMethodException::class);

        $this->service->createDraft($this->makeInstructor(), PayoutMethodType::BankTransfer, null, 'XXX', $this->details());
    }

    public function test_account_holder_name_and_identifier_are_required(): void
    {
        $instructor = $this->makeInstructor();

        $this->expectException(PayoutMethodException::class);

        $this->service->createDraft($instructor, PayoutMethodType::BankTransfer, null, 'INR', new PayoutMethodDetails(
            accountHolderName: 'Someone',
            // no account number, no IBAN
        ));
    }

    // ── Encryption / serialization / fingerprint ─────────────────────

    public function test_details_are_encrypted_at_rest(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());

        $raw = DB::table('instructor_payout_methods')->where('id', $method->id)->value('encrypted_details');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('9998887774821', $raw);
        $this->assertStringNotContainsString('account_holder_name', $raw);

        // Decrypting through the cast recovers the normalized payload.
        $this->assertSame('9998887774821', $method->fresh()->encrypted_details['account_number']);
    }

    public function test_sensitive_fields_are_hidden_from_serialization(): void
    {
        $method = $this->createDraftFor($this->makeInstructor())->fresh();

        $json = json_encode($method->toArray());

        $this->assertStringNotContainsString('encrypted_details', $json);
        $this->assertStringNotContainsString('fingerprint', $json);
        $this->assertStringNotContainsString('9998887774821', $json);
    }

    public function test_fingerprint_is_keyed_and_reveals_nothing(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());

        $fingerprint = $method->getAttributes()['fingerprint'];

        $this->assertNotSame(hash('sha256', '9998887774821'), $fingerprint);
        $this->assertStringNotContainsString('4821', $fingerprint);
    }

    public function test_duplicate_normalized_details_are_rejected(): void
    {
        $instructor = $this->makeInstructor();
        $this->createDraftFor($instructor);

        $this->expectException(PayoutMethodException::class);
        $this->expectExceptionMessage('already have a payout method');

        // Same account, different formatting — normalization must catch it.
        $this->service->createDraft($instructor, PayoutMethodType::BankTransfer, null, 'INR', new PayoutMethodDetails(
            accountHolderName: 'Asha Instructor',
            bankName: 'Test Bank',
            accountNumber: '999-888 777 4821',
            routingType: 'ifsc',
            routingNumber: 'test 000 1234',
        ));
    }

    public function test_same_details_are_allowed_for_a_different_instructor(): void
    {
        $this->createDraftFor($this->makeInstructor());

        $method = $this->createDraftFor($this->makeInstructor());

        $this->assertSame(PayoutMethodStatus::Draft, $method->status);
    }

    // ── Editing rules ────────────────────────────────────────────────

    public function test_draft_can_be_edited(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->createDraftFor($instructor);

        $updated = $this->service->updateDraft($method, null, 'INR', new PayoutMethodDetails(
            accountHolderName: 'Asha Instructor',
            bankName: 'Other Bank',
            accountNumber: '1112223339999',
            routingType: 'ifsc',
            routingNumber: 'OTHR0009999',
        ));

        $this->assertSame('Account ending in 9999', $updated->masked_identifier);
        $this->assertSame('1112223339999', $updated->fresh()->encrypted_details['account_number']);
    }

    public function test_pending_method_cannot_be_silently_edited(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->createDraftFor($instructor);
        $this->service->submitForVerification($method, $instructor);

        $this->expectException(PayoutMethodException::class);

        $this->service->updateDraft($method->fresh(), null, 'INR', $this->details());
    }

    public function test_verified_details_are_immutable(): void
    {
        $method = $this->verifiedMethodFor($this->makeInstructor());

        $this->expectException(PayoutMethodException::class);

        $this->service->updateDraft($method, null, 'INR', $this->details());
    }

    public function test_rejected_method_can_be_corrected_and_resubmitted(): void
    {
        $instructor = $this->makeInstructor();
        $admin = $this->makeAdminWith(['Reject:InstructorPayoutMethod']);

        $method = $this->createDraftFor($instructor);
        $this->service->submitForVerification($method, $instructor);
        $this->service->reject($method->fresh(), $admin, 'IFSC does not match the bank.');

        $corrected = $this->service->updateDraft($method->fresh(), null, 'INR', new PayoutMethodDetails(
            accountHolderName: 'Asha Instructor',
            accountNumber: '5556667778888',
            routingType: 'ifsc',
            routingNumber: 'CORR0001234',
        ));

        $this->assertSame(PayoutMethodStatus::Draft, $corrected->status);
        $this->assertNull($corrected->rejection_reason);

        $resubmitted = $this->service->submitForVerification($corrected, $instructor);

        $this->assertSame(PayoutMethodStatus::PendingVerification, $resubmitted->status);
    }

    // ── Verification lifecycle ───────────────────────────────────────

    public function test_draft_cannot_be_verified_directly(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());
        $admin = $this->makeAdminWith(['Verify:InstructorPayoutMethod']);

        $this->expectException(InvalidPayoutMethodTransitionException::class);

        $this->service->verify($method, $admin);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->createDraftFor($instructor);
        $this->service->submitForVerification($method, $instructor);

        $this->expectException(PayoutMethodException::class);

        $this->service->reject($method->fresh(), $this->makeAdminWith(['Reject:InstructorPayoutMethod']), '   ');
    }

    public function test_admin_verification_permissions_are_policy_enforced(): void
    {
        $method = InstructorPayoutMethod::factory()->pendingVerification()->create();

        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $authorized = $this->makeAdminWith(['Verify:InstructorPayoutMethod']);

        $this->assertFalse($unauthorized->can('verify', $method));
        $this->assertTrue($authorized->can('verify', $method));
    }

    // ── Default method ───────────────────────────────────────────────

    public function test_only_a_verified_method_can_become_default(): void
    {
        $instructor = $this->makeInstructor();
        $method = $this->createDraftFor($instructor);

        $this->expectException(PayoutMethodException::class);

        $this->service->setDefault($method, $instructor);
    }

    public function test_setting_a_new_default_unsets_the_old_one(): void
    {
        $instructor = $this->makeInstructor();
        $first = InstructorPayoutMethod::factory()->verified()->default()->create(['instructor_id' => $instructor->id]);
        $second = InstructorPayoutMethod::factory()->verified()->create(['instructor_id' => $instructor->id]);

        $this->service->setDefault($second, $instructor);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, InstructorPayoutMethod::query()->where('instructor_id', $instructor->id)->where('is_default', true)->count());
    }

    // ── Disable ──────────────────────────────────────────────────────

    public function test_disable_clears_default_and_is_terminal(): void
    {
        $instructor = $this->makeInstructor();
        $method = InstructorPayoutMethod::factory()->verified()->default()->create(['instructor_id' => $instructor->id]);

        $disabled = $this->service->disable($method, $instructor);

        $this->assertSame(PayoutMethodStatus::Disabled, $disabled->status);
        $this->assertFalse($disabled->is_default);
        $this->assertNotNull($disabled->disabled_at);

        $this->expectException(PayoutMethodException::class);
        $this->service->submitForVerification($disabled, $instructor);
    }

    // ── Sensitive detail viewing ─────────────────────────────────────

    public function test_sensitive_detail_viewing_requires_permission(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(PayoutMethodException::class);

        $this->service->viewSensitiveDetails($method, $admin);
    }

    public function test_sensitive_detail_access_is_returned_and_audit_logged_without_values(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());
        $admin = $this->makeAdminWith(['ViewSensitive:InstructorPayoutMethod']);

        $details = $this->service->viewSensitiveDetails($method, $admin);

        $this->assertSame('9998887774821', $details['account_number']);

        $activity = Activity::query()
            ->where('log_name', 'instructor_payouts')
            ->where('event', 'payout_method_sensitive_viewed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringNotContainsString('9998887774821', json_encode($activity->properties));
        $this->assertStringNotContainsString('9998887774821', (string) $activity->description);
    }

    public function test_model_activity_log_never_contains_sensitive_attributes(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());

        $entries = Activity::query()
            ->where('subject_type', InstructorPayoutMethod::class)
            ->where('subject_id', $method->id)
            ->get();

        foreach ($entries as $entry) {
            $json = json_encode($entry->properties);
            $this->assertStringNotContainsString('9998887774821', $json);
            $this->assertStringNotContainsString('encrypted_details', $json);
            $this->assertStringNotContainsString('fingerprint', $json);
        }
    }

    // ── Policies: ownership ──────────────────────────────────────────

    public function test_instructor_cannot_manage_another_instructors_method(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());
        $other = $this->makeInstructor();

        $this->assertFalse($other->can('update', $method));
        $this->assertFalse($other->can('submit', $method));
        $this->assertFalse($other->can('setDefault', $method));
        $this->assertFalse($other->can('disable', $method));
    }

    public function test_delete_is_denied_for_everyone_below_super_admin(): void
    {
        $method = $this->createDraftFor($this->makeInstructor());
        $admin = $this->makeAdminWith(['Verify:InstructorPayoutMethod', 'Disable:InstructorPayoutMethod']);

        $this->assertFalse($method->instructor->can('delete', $method));
        $this->assertFalse($admin->can('delete', $method));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Approved]);

        return $user;
    }

    private function makeAdminWith(array $permissions): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin->givePermissionTo($permissions);

        return $admin;
    }

    private function details(): PayoutMethodDetails
    {
        return new PayoutMethodDetails(
            accountHolderName: 'Asha Instructor',
            bankName: 'Test Bank',
            accountNumber: '9998887774821',
            routingType: 'ifsc',
            routingNumber: 'TEST0001234',
            accountType: 'savings',
        );
    }

    private function createDraftFor(User $instructor): InstructorPayoutMethod
    {
        return $this->service->createDraft($instructor, PayoutMethodType::BankTransfer, null, 'INR', $this->details());
    }

    private function verifiedMethodFor(User $instructor): InstructorPayoutMethod
    {
        $method = $this->createDraftFor($instructor);
        $this->service->submitForVerification($method, $instructor);

        return $this->service->verify($method->fresh(), $this->makeAdminWith(['Verify:InstructorPayoutMethod']));
    }
}
