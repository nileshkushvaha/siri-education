<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\DTOs\StudentBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\MeetingStatus;
use App\Booking\Enums\Weekday;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Http\Resources\Student\StudentBookingResource;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Policies\BookingPolicy;
use App\Settings\BookingSettings;
use App\Settings\MeetingSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesStudentLessonPrices;
use Tests\TestCase;

class BookingMeetingTest extends TestCase
{
    use CreatesStudentLessonPrices;
    use RefreshDatabase;

    private const KEY_SECRET = 'test_key_secret';

    private const WEBHOOK_SECRET = 'test_webhook_secret';

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->profile()->update(['phone_e164' => '+9199999'.str_pad((string) $this->student->id, 5, '0', STR_PAD_LEFT), 'phone_verified_at' => now()]); // paid bookings require a verified phone (StudentFinancialVerificationGate)

        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        UserProfile::updateOrCreate(['user_id' => $this->teacher->id], ['instructor_status' => 'approved']);
        TeacherSubject::factory()->state(['teacher_id' => $this->teacher->id])->subject('maths', 1, 12)->create();
        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $this->teacher->id])
                ->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        $priced = $this->createPaidBookingTypeWithPrice('paid_one_to_one', 499.00, 'INR');
        $this->assignBillingCountry($this->student, $priced['country']);

        BookingType::query()->firstOrCreate(
            ['key' => 'free_demo'],
            ['name' => 'Free Demo', 'duration_minutes' => 30, 'requires_approval' => false, 'is_paid' => false, 'is_active' => true],
        );

        $this->enableMeetings();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function enableMeetings(bool $demo = true, bool $paid = true, string $defaultProvider = ManualMeetingProvider::KEY): void
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = true;
        $settings->manual_provider_enabled = true;
        $settings->default_provider = $defaultProvider;
        $settings->create_after_demo_booking_confirmation = $demo;
        $settings->create_after_paid_booking_confirmation = $paid;
        $settings->save();
    }

    private function disableMeetings(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->meetings_enabled = false;
        $settings->save();
    }

    private function reserveStudent(int $daysAhead = 3, int $hour = 10): Booking
    {
        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'paid_one_to_one',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays($daysAhead)->setTime($hour, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        return $booking->refresh();
    }

    private function bookFreeDemo(int $daysAhead = 3, int $hour = 10): Booking
    {
        $booking = app(StudentBookingServiceInterface::class)->book(new StudentBookingData(
            typeKey: 'free_demo',
            studentId: $this->student->id,
            teacherId: $this->teacher->id,
            startsAt: now('UTC')->addDays($daysAhead)->setTime($hour, 0)->toImmutable(),
            subject: 'maths',
            grade: 7,
        ));

        return $booking->refresh();
    }

    private function configureRazorpay(): void
    {
        $gateways = app(PaymentGatewaySettings::class);
        $gateways->razorpay_enabled = true;
        $gateways->razorpay_key_id = 'rzp_test_key_id';
        $gateways->razorpay_key_secret = Crypt::encryptString(self::KEY_SECRET);
        $gateways->razorpay_webhook_secret = Crypt::encryptString(self::WEBHOOK_SECRET);
        $gateways->save();

        app(BookingSettings::class)->payment_provider = 'razorpay';
        app(BookingSettings::class)->save();
    }

    private function fakeRazorpayOrderApi(string $orderId = 'order_TEST123'): void
    {
        $gateway = Mockery::mock(RazorpayGatewayClient::class);
        $gateway->shouldReceive('createOrder')->andReturn(['id' => $orderId, 'amount' => 49900, 'currency' => 'INR']);
        $this->app->instance(RazorpayGatewayClient::class, $gateway);
    }

    private function webhookSignature(string $body): string
    {
        return hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
    }

    private function postWebhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/bookings/payments/razorpay', [], [], [], [
            'HTTP_X_RAZORPAY_SIGNATURE' => $this->webhookSignature($body),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function capturedWebhookPayload(string $orderId, string $paymentId, string $reference): array
    {
        return [
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'amount' => 49900,
                        'currency' => 'INR',
                        'method' => 'upi',
                        'notes' => ['booking_reference' => $reference],
                    ],
                ],
            ],
        ];
    }

    // ── A. Data/model ────────────────────────────────────────────────

    public function test_booking_meeting_can_be_created_for_booking(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $meeting = app(BookingMeetingServiceInterface::class)->saveManualMeeting(
            $booking,
            new MeetingUpdateContext(joinUrl: 'https://meet.example.test/abc'),
        );

        $this->assertInstanceOf(BookingMeeting::class, $meeting);
        $this->assertSame($booking->id, $meeting->booking_id);
        $this->assertSame(MeetingStatus::Created, $meeting->status);
    }

    public function test_one_meeting_per_booking_enforced(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $service = app(BookingMeetingServiceInterface::class);
        $service->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/one'));
        $service->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/two'));

        $this->assertSame(1, BookingMeeting::query()->where('booking_id', $booking->id)->count());
        $this->assertSame('https://meet.example.test/two', $booking->fresh()->meeting?->join_url);
    }

    public function test_meeting_stores_provider_and_join_url_safely(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $meeting = app(BookingMeetingServiceInterface::class)->saveManualMeeting(
            $booking,
            new MeetingUpdateContext(joinUrl: 'https://meet.example.test/abc', password: 'secret-pass'),
        );

        $this->assertSame(ManualMeetingProvider::KEY, $meeting->provider);
        $this->assertSame('https://meet.example.test/abc', $meeting->join_url);

        // host_url/password are hidden from default serialization.
        $array = $meeting->toArray();
        $this->assertArrayNotHasKey('host_url', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    // ── B. Eligibility ───────────────────────────────────────────────

    public function test_paid_confirmed_booking_with_paid_payment_can_create_meeting(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking);

        // Manual auto-trigger with no URL yet: eligible, pending placeholder.
        $this->assertNotNull($meeting);
        $this->assertSame(MeetingStatus::Pending, $meeting->status);
    }

    public function test_paid_confirmed_booking_with_unpaid_payment_cannot_create_meeting(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
            'payment_status' => BookingPaymentStatus::Pending,
        ]);

        $meeting = app(BookingMeetingServiceInterface::class)->createMeeting($booking);

        $this->assertNull($meeting);
        $this->assertSame(0, BookingMeeting::query()->where('booking_id', $booking->id)->count());
    }

    public function test_pending_booking_cannot_create_meeting(): void
    {
        $booking = Booking::factory()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
            'status' => BookingStatus::Pending,
        ]);

        $this->assertNull(app(BookingMeetingServiceInterface::class)->createMeeting($booking));
    }

    public function test_cancelled_booking_cannot_create_meeting(): void
    {
        $booking = Booking::factory()->cancelled()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $this->assertNull(app(BookingMeetingServiceInterface::class)->createMeeting($booking));
    }

    public function test_expired_reservation_booking_cannot_create_meeting(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $booking->forceFill(['reserved_until' => now()->subMinute()])->save();

        $this->artisan('booking:release-expired')->assertSuccessful();
        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);

        $this->assertNull(app(BookingMeetingServiceInterface::class)->createMeeting($booking));
    }

    public function test_failed_or_refunded_payment_status_cannot_create_meeting(): void
    {
        $failed = Booking::factory()->confirmed()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
            'payment_status' => BookingPaymentStatus::Failed,
        ]);
        $refunded = Booking::factory()->confirmed()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
            'payment_status' => BookingPaymentStatus::Refunded,
        ]);

        $meetings = app(BookingMeetingServiceInterface::class);
        $this->assertNull($meetings->createMeeting($failed));
        $this->assertNull($meetings->createMeeting($refunded));
    }

    public function test_demo_confirmed_booking_can_create_meeting_when_enabled(): void
    {
        $booking = $this->bookFreeDemo();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $meeting = $booking->fresh()->meeting;

        $this->assertNotNull($meeting);
        $this->assertSame(MeetingStatus::Pending, $meeting->status);
    }

    public function test_demo_confirmed_booking_does_not_create_meeting_when_disabled(): void
    {
        $this->enableMeetings(demo: false, paid: true);

        $booking = $this->bookFreeDemo();

        $this->assertNull($booking->fresh()->meeting);
    }

    // ── C. Manual provider ───────────────────────────────────────────

    public function test_admin_can_create_manual_meeting_for_eligible_booking(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $meeting = app(BookingMeetingServiceInterface::class)->saveManualMeeting(
            $booking,
            new MeetingUpdateContext(joinUrl: 'https://meet.example.test/created', providerLabel: 'zoom_manual'),
        );

        $this->assertSame(MeetingStatus::Created, $meeting->status);
        $this->assertSame('zoom_manual', $meeting->metadata['manual_label'] ?? null);
    }

    public function test_admin_can_update_manual_meeting_url(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);
        $service = app(BookingMeetingServiceInterface::class);
        $service->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/v1'));

        $updated = $service->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/v2'));

        $this->assertSame('https://meet.example.test/v2', $updated->join_url);
        $this->assertSame(1, BookingMeeting::query()->where('booking_id', $booking->id)->count());
    }

    public function test_admin_cannot_create_manual_meeting_for_ineligible_booking(): void
    {
        $booking = Booking::factory()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
            'status' => BookingStatus::Pending,
        ]);

        $meeting = app(BookingMeetingServiceInterface::class)->saveManualMeeting(
            $booking,
            new MeetingUpdateContext(joinUrl: 'https://meet.example.test/nope'),
        );

        $this->assertNull($meeting);
        $this->assertSame(0, BookingMeeting::query()->where('booking_id', $booking->id)->count());
    }

    public function test_manual_provider_validates_url(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $meeting = app(BookingMeetingServiceInterface::class)->saveManualMeeting(
            $booking,
            new MeetingUpdateContext(joinUrl: 'not-a-url'),
        );

        // The provider throws; the service records it as a safe failure, never crashes.
        $this->assertSame(MeetingStatus::Failed, $meeting->status);
        $this->assertNotNull($meeting->failure_reason);
    }

    public function test_student_sees_manual_join_link_only_after_confirmed_booking(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
            // The resource releases the URL only inside the visibility window.
            'starts_at' => now()->addMinutes(10),
            'ends_at' => now()->addMinutes(40),
        ]);
        app(BookingMeetingServiceInterface::class)->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/abc'));

        // The resource releases the URL only to the booking's own
        // Active student as the request viewer.
        $viewerRequest = Request::create('/api/test');
        $viewerRequest->setUserResolver(fn () => $this->student);
        $resource = (new StudentBookingResource($booking->fresh()->load('meeting')))
            ->toArray($viewerRequest);
        $resource = collect($resource)->reject(fn ($v) => $v instanceof MissingValue)->all();
        $this->assertSame('https://meet.example.test/abc', $resource['meeting_url']);

        $pendingBooking = Booking::factory()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
            'status' => BookingStatus::Pending,
        ]);
        $pendingResource = (new StudentBookingResource($pendingBooking))
            ->toArray($viewerRequest);
        $pendingResource = collect($pendingResource)->reject(fn ($v) => $v instanceof MissingValue)->all();
        $this->assertArrayNotHasKey('meeting_url', $pendingResource);
    }

    // ── D. Trigger points ────────────────────────────────────────────

    public function test_payment_initiation_does_not_create_meeting(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi();

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $this->assertNull($booking->fresh()->meeting);
    }

    public function test_frontend_checkout_signature_verification_alone_does_not_create_meeting(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_FRONTEND');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);

        $signature = hash_hmac('sha256', 'order_FRONTEND|pay_FRONTEND', self::KEY_SECRET);
        app(RazorpayPaymentProvider::class)->verifyCheckout($booking, 'order_FRONTEND', 'pay_FRONTEND', $signature);

        $booking->refresh();
        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertNull($booking->meeting);
    }

    public function test_verified_payment_success_creates_meeting_after_booking_confirmation(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_MEETOK');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $this->postWebhook($this->capturedWebhookPayload('order_MEETOK', 'pay_MEETOK', $reference))->assertOk();

        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(BookingPaymentStatus::Paid, $booking->payment_status);
        $this->assertNotNull($booking->meeting);
        $this->assertSame(MeetingStatus::Pending, $booking->meeting->status);
    }

    public function test_duplicate_payment_webhook_does_not_create_duplicate_meeting(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_MEETDUP');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $payload = $this->capturedWebhookPayload('order_MEETDUP', 'pay_MEETDUP', $reference);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);
        $this->postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(1, BookingMeeting::query()->where('booking_id', $booking->id)->count());
    }

    public function test_option_b_late_terminal_payment_does_not_create_meeting(): void
    {
        $this->configureRazorpay();
        $this->fakeRazorpayOrderApi('order_MEETLATE');

        $booking = $this->reserveStudent();
        app(BookingPaymentServiceInterface::class)->initiate($booking);
        $reference = (string) $booking->refresh()->payment_reference;

        $booking = app(BookingServiceInterface::class)->cancel($booking, new CancelBookingData(
            BookingActor::System,
            'Payment was not completed within the reservation window.',
        ));
        $this->assertSame(BookingStatus::Cancelled, $booking->status);

        $this->postWebhook($this->capturedWebhookPayload('order_MEETLATE', 'pay_MEETLATE', $reference))->assertOk();

        $this->assertNull($booking->fresh()->meeting);
    }

    // ── E. Admin ─────────────────────────────────────────────────────

    public function test_host_can_manage_own_booking_meeting(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create(['instructor_id' => $this->teacher->id]);

        $this->assertTrue(app(BookingPolicy::class)->manageMeeting($this->teacher, $booking));
    }

    public function test_manager_with_permission_can_manage_meeting(): void
    {
        Permission::firstOrCreate(['name' => 'Manage:BookingMeeting', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->givePermissionTo('Manage:BookingMeeting');

        $booking = Booking::factory()->confirmed()->paid()->create();

        $this->assertTrue(app(BookingPolicy::class)->manageMeeting($manager, $booking));
    }

    public function test_non_permitted_admin_cannot_manage_meeting(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $booking = Booking::factory()->confirmed()->paid()->create();

        $this->assertFalse(app(BookingPolicy::class)->manageMeeting($other, $booking));
    }

    public function test_admin_can_fallback_to_manual_provider_if_google_failed(): void
    {
        $settings = app(MeetingSettings::class);
        $settings->google_meet_enabled = true; // enabled but no credentials/calendar id -> unconfigured
        $settings->save();

        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $service = app(BookingMeetingServiceInterface::class);
        $failed = $service->createMeeting($booking, 'google_meet');
        $this->assertSame(MeetingStatus::Failed, $failed->status);

        $fallback = $service->saveManualMeeting($booking, new MeetingUpdateContext(joinUrl: 'https://meet.example.test/fallback'));
        $this->assertSame(MeetingStatus::Created, $fallback->status);
        $this->assertSame(ManualMeetingProvider::KEY, $fallback->provider);
    }

    // ── F. Visibility/security ───────────────────────────────────────

    public function test_student_resource_never_includes_host_url_or_metadata(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);
        app(BookingMeetingServiceInterface::class)->saveManualMeeting($booking, new MeetingUpdateContext(
            joinUrl: 'https://meet.example.test/abc',
            password: 'p@ss',
        ));

        $resource = (new StudentBookingResource($booking->fresh()->load('meeting')))
            ->resolve();

        $this->assertArrayNotHasKey('meeting_host_url', $resource);
        $this->assertArrayNotHasKey('meeting_metadata', $resource);
        $this->assertArrayNotHasKey('host_url', $resource);
    }

    // ── G. Boundaries ────────────────────────────────────────────────

    public function test_meetings_enabled_false_blocks_creation_safely(): void
    {
        $this->disableMeetings();

        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        $this->assertNull(app(BookingMeetingServiceInterface::class)->createMeeting($booking));
    }

    public function test_fake_meeting_provider_is_testing_environment_only(): void
    {
        // FakeMeetingProvider exists for attendance simulation, but its
        // registration is wrapped in an environment('testing') guard,
        // and no production settings path can select it
        // (MeetingSettings::default_provider documents manual|google_meet).
        $registration = file_get_contents(app_path('Providers/BookingServiceProvider.php'));

        $this->assertMatchesRegularExpression(
            '/environment\(\'testing\'\)[^}]*FakeMeetingProvider/s',
            $registration,
            'FakeMeetingProvider must only ever be registered behind the testing-environment guard.',
        );

        $this->assertTrue(app(MeetingProviderRegistry::class)->has('fake'));
    }

    public function test_no_wallet_side_effects_from_meeting_creation(): void
    {
        $booking = Booking::factory()->confirmed()->paid()->create([
            'instructor_id' => $this->teacher->id,
            'student_id' => $this->student->id,
        ]);

        app(BookingMeetingServiceInterface::class)->createMeeting($booking);

        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_no_guest_payment_routes_exist(): void
    {
        $this->assertFalse(Route::has('api.guest.bookings.payments.initiate'));
        $this->assertFalse(Route::has('api.guest.bookings.payments.verify'));
    }
}
