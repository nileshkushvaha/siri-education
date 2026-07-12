<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\RazorpayX;

use App\Earnings\Contracts\RazorpayXDestinationProvisioningServiceInterface;
use App\Earnings\Enums\RazorpayXProviderLinkStatus;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXContactResult;
use App\Earnings\Providers\RazorpayX\DTOs\RazorpayXFundAccountResult;
use App\Earnings\Providers\RazorpayX\RazorpayXPayoutClientInterface;
use App\Earnings\Providers\RazorpayX\RazorpayXProvisioningException;
use App\Earnings\Providers\RazorpayX\RazorpayXRequestException;
use App\Models\InstructorPayoutDestinationProviderLink;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use App\Settings\RazorpayXPayoutSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class RazorpayXDestinationProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $client;

    private RazorpayXDestinationProvisioningServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(RazorpayXPayoutClientInterface::class);
        $this->app->instance(RazorpayXPayoutClientInterface::class, $this->client);

        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_contact_provisioning_enabled = true;
        $settings->razorpayx_fund_account_provisioning_enabled = true;
        $settings->save();

        $this->service = app(RazorpayXDestinationProvisioningServiceInterface::class);
    }

    private function verifiedMethod(?User $instructor = null, array $bankDetails = []): InstructorPayoutMethod
    {
        $instructor ??= User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $method = InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
        ]);

        if ($bankDetails !== []) {
            $method->forceFill(['encrypted_details' => array_merge($method->encrypted_details, $bankDetails)])->save();
        }

        return $method;
    }

    public function test_provision_creates_a_new_contact_and_fund_account_end_to_end(): void
    {
        $method = $this->verifiedMethod();

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andReturn(new RazorpayXContactResult('cont_new1', 'active', null));
        $this->client->shouldReceive('createBankFundAccount')->once()->andReturn(new RazorpayXFundAccountResult('fa_new1', 'cont_new1', 'active'));

        $link = $this->service->provision($method, $method->instructor);

        $this->assertSame(RazorpayXProviderLinkStatus::Ready, $link->status);
        $this->assertSame('cont_new1', $link->provider_contact_id);
        $this->assertSame('fa_new1', $link->provider_fund_account_id);
    }

    public function test_provision_is_idempotent_on_an_already_ready_link(): void
    {
        $method = $this->verifiedMethod();

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andReturn(new RazorpayXContactResult('cont_new1', 'active', null));
        $this->client->shouldReceive('createBankFundAccount')->once()->andReturn(new RazorpayXFundAccountResult('fa_new1', 'cont_new1', 'active'));

        $this->service->provision($method, $method->instructor);
        // Calling again must not create a second Contact/Fund Account —
        // the mocked expectations above are ->once(), so a second call
        // reaching the client at all would fail the test.
        $link = $this->service->provision($method, $method->instructor);

        $this->assertSame(RazorpayXProviderLinkStatus::Ready, $link->status);
    }

    /** One instructor maps to exactly one RazorpayX Contact — a second payout method reuses it without a client call. */
    public function test_provision_reuses_an_existing_contact_for_the_same_instructor(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $methodA = $this->verifiedMethod($instructor);
        $methodB = $this->verifiedMethod($instructor, ['account_number' => '999888777', 'routing_number' => 'ICIC0009999']);

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andReturn(new RazorpayXContactResult('cont_shared', 'active', null));
        $this->client->shouldReceive('createBankFundAccount')->twice()->andReturn(
            new RazorpayXFundAccountResult('fa_a', 'cont_shared', 'active'),
            new RazorpayXFundAccountResult('fa_b', 'cont_shared', 'active'),
        );

        $linkA = $this->service->provision($methodA, $instructor);
        $linkB = $this->service->provision($methodB, $instructor);

        $this->assertSame('cont_shared', $linkA->provider_contact_id);
        // createContact() called exactly once (see ->once() above) even
        // though two links were provisioned for the same instructor.
        $this->assertSame('cont_shared', $linkB->provider_contact_id);
        $this->assertNotSame($linkA->provider_fund_account_id, $linkB->provider_fund_account_id);
    }

    /** Identical bank details under the same Contact reuse the Fund Account rather than creating a duplicate. */
    public function test_provision_reuses_the_fund_account_when_bank_details_are_identical(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $bankDetails = ['account_number' => '000123456789', 'routing_number' => 'HDFC0000123'];
        $methodA = $this->verifiedMethod($instructor, $bankDetails);
        $methodB = $this->verifiedMethod($instructor, $bankDetails);

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andReturn(new RazorpayXContactResult('cont_shared', 'active', null));
        $this->client->shouldReceive('createBankFundAccount')->once()->andReturn(new RazorpayXFundAccountResult('fa_shared', 'cont_shared', 'active'));

        $linkA = $this->service->provision($methodA, $instructor);
        $linkB = $this->service->provision($methodB, $instructor);

        $this->assertSame($linkA->provider_fund_account_id, $linkB->provider_fund_account_id);
    }

    public function test_provider_side_reuse_resolves_a_contact_that_already_existed(): void
    {
        $method = $this->verifiedMethod();

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([
            new RazorpayXContactResult('cont_existing', 'active', null),
        ]);
        $this->client->shouldNotReceive('createContact');
        $this->client->shouldReceive('createBankFundAccount')->once()->andReturn(new RazorpayXFundAccountResult('fa_1', 'cont_existing', 'active'));

        $link = $this->service->provision($method, $method->instructor);

        $this->assertSame('cont_existing', $link->provider_contact_id);
    }

    /** A validation-shaped 4xx never reached RazorpayX ambiguously — it's a permanent failure, not "unknown". */
    public function test_a_4xx_contact_error_is_a_permanent_failure_not_unknown(): void
    {
        $method = $this->verifiedMethod();

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andThrow(new RazorpayXRequestException('bad request', httpStatus: 422));

        $link = $this->service->provision($method, $method->instructor);

        $this->assertSame(RazorpayXProviderLinkStatus::Failed, $link->status);
    }

    /** A timeout might have reached RazorpayX — the outcome is unknown, never assumed failed. */
    public function test_a_timeout_during_contact_creation_lands_in_contact_unknown(): void
    {
        $method = $this->verifiedMethod();

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andThrow(new RazorpayXRequestException('timeout'));

        $link = $this->service->provision($method, $method->instructor);

        $this->assertSame(RazorpayXProviderLinkStatus::ContactUnknown, $link->status);
        $this->assertNotNull($link->last_provisioning_error);
    }

    public function test_refresh_resolves_a_contact_unknown_link_by_finding_it_by_reference(): void
    {
        $method = $this->verifiedMethod();

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andThrow(new RazorpayXRequestException('timeout'));
        $stuckLink = $this->service->provision($method, $method->instructor);
        $this->assertSame(RazorpayXProviderLinkStatus::ContactUnknown, $stuckLink->status);

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([
            new RazorpayXContactResult('cont_recovered', 'active', null),
        ]);
        $this->client->shouldReceive('createBankFundAccount')->once()->andReturn(new RazorpayXFundAccountResult('fa_recovered', 'cont_recovered', 'active'));

        $recovered = $this->service->refresh($stuckLink->fresh(), $method->instructor);

        $this->assertSame(RazorpayXProviderLinkStatus::Ready, $recovered->status);
        $this->assertSame('cont_recovered', $recovered->provider_contact_id);
    }

    public function test_a_timeout_during_fund_account_creation_lands_in_fund_account_unknown(): void
    {
        $method = $this->verifiedMethod();

        $this->client->shouldReceive('findContactsByReference')->once()->andReturn([]);
        $this->client->shouldReceive('createContact')->once()->andReturn(new RazorpayXContactResult('cont_new1', 'active', null));
        $this->client->shouldReceive('createBankFundAccount')->once()->andThrow(new RazorpayXRequestException('timeout'));

        $link = $this->service->provision($method, $method->instructor);

        $this->assertSame(RazorpayXProviderLinkStatus::FundAccountUnknown, $link->status);
    }

    public function test_mark_stale_requires_a_reason(): void
    {
        $link = InstructorPayoutDestinationProviderLink::factory()->ready()->create();

        $this->expectException(RazorpayXProvisioningException::class);
        $this->service->markStale($link, User::factory()->create(), '');
    }

    public function test_mark_stale_transitions_a_ready_link(): void
    {
        $link = InstructorPayoutDestinationProviderLink::factory()->ready()->create();

        $updated = $this->service->markStale($link, User::factory()->create(), 'Reconciliation found a mismatch.');

        $this->assertSame(RazorpayXProviderLinkStatus::Stale, $updated->status);
    }

    public function test_mark_stale_rejects_a_link_that_is_not_ready(): void
    {
        $link = InstructorPayoutDestinationProviderLink::factory()->create(['status' => RazorpayXProviderLinkStatus::Pending]);

        $this->expectException(RazorpayXProvisioningException::class);
        $this->service->markStale($link, User::factory()->create(), 'reason');
    }

    public function test_disable_transitions_a_ready_link(): void
    {
        $link = InstructorPayoutDestinationProviderLink::factory()->ready()->create();

        $updated = $this->service->disable($link, User::factory()->create());

        $this->assertSame(RazorpayXProviderLinkStatus::Disabled, $updated->status);
        $this->assertNotNull($updated->disabled_at);
    }

    public function test_disable_is_rejected_from_an_already_disabled_link(): void
    {
        $link = InstructorPayoutDestinationProviderLink::factory()->disabled()->create();

        $this->expectException(RazorpayXProvisioningException::class);
        $this->service->disable($link, User::factory()->create());
    }

    public function test_provisioning_is_refused_when_contact_provisioning_is_disabled(): void
    {
        $settings = app(RazorpayXPayoutSettings::class);
        $settings->razorpayx_contact_provisioning_enabled = false;
        $settings->save();

        $method = $this->verifiedMethod();

        $this->expectException(RazorpayXProvisioningException::class);
        $this->service->provision($method, $method->instructor);
    }
}
