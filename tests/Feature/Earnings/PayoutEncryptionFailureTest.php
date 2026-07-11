<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings;

use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Exceptions\PayoutMethodException;
use App\Models\Activity;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 15.1 §8 — corrupted or undecryptable encrypted payloads
 * (payload tampering, APP_KEY rotation without re-encryption) must fail
 * with safe messages: no ciphertext, no decryption internals, no bank
 * context. Operational key-rotation requirements are documented in
 * docs/architecture/phase-15.1-financial-integrity-closure.md.
 */
class PayoutEncryptionFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrupted_details_fail_safely_through_the_secure_view(): void
    {
        $method = InstructorPayoutMethod::factory()->pendingVerification()->create();
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Permission::firstOrCreate(['name' => 'ViewSensitive:InstructorPayoutMethod', 'guard_name' => 'web']);
        $admin->givePermissionTo('ViewSensitive:InstructorPayoutMethod');

        $ciphertext = 'tampered-ciphertext-payload';
        DB::table('instructor_payout_methods')->where('id', $method->id)->update(['encrypted_details' => $ciphertext]);

        try {
            app(InstructorPayoutMethodServiceInterface::class)->viewSensitiveDetails($method->fresh(), $admin);
            $this->fail('Corrupted payload should not decrypt.');
        } catch (PayoutMethodException $e) {
            $this->assertStringContainsString('cannot be decrypted', $e->getMessage());
            $this->assertStringNotContainsString($ciphertext, $e->getMessage());
            $this->assertStringNotContainsString('MAC', $e->getMessage());
        }
    }

    public function test_failed_decryption_logs_no_access_entry(): void
    {
        $method = InstructorPayoutMethod::factory()->pendingVerification()->create();
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Permission::firstOrCreate(['name' => 'ViewSensitive:InstructorPayoutMethod', 'guard_name' => 'web']);
        $admin->givePermissionTo('ViewSensitive:InstructorPayoutMethod');

        DB::table('instructor_payout_methods')->where('id', $method->id)->update(['encrypted_details' => 'garbage']);

        try {
            app(InstructorPayoutMethodServiceInterface::class)->viewSensitiveDetails($method->fresh(), $admin);
        } catch (PayoutMethodException) {
        }

        // No values were shown, so no "sensitive details viewed" entry
        // may claim otherwise.
        $this->assertSame(0, Activity::query()
            ->where('event', 'payout_method_sensitive_viewed')
            ->where('subject_id', $method->id)
            ->count());
    }

    public function test_raw_cast_access_of_corrupted_payload_throws_the_framework_exception(): void
    {
        // Outside the guarded service path, the encrypted cast surfaces
        // Laravel's DecryptException ("The payload is invalid.") — a safe
        // message that carries neither ciphertext nor key material.
        $method = InstructorPayoutMethod::factory()->create();
        DB::table('instructor_payout_methods')->where('id', $method->id)->update(['encrypted_details' => 'garbage']);

        $this->expectException(DecryptException::class);

        $method->fresh()->encrypted_details;
    }
}
