<?php

declare(strict_types=1);

namespace App\Providers;

use App\Booking\Assignment\Scorers\PriorityScorer;
use App\Booking\Assignment\Scorers\TimezoneScorer;
use App\Booking\Assignment\Scorers\WorkloadScorer;
use App\Booking\Assignment\Strategies\BestScoreStrategy;
use App\Booking\Assignment\Strategies\LeastLoadedStrategy;
use App\Booking\Contracts\AvailabilityRepositoryInterface;
use App\Booking\Contracts\AvailabilityServiceInterface;
use App\Booking\Contracts\BookingAnalyticsRepositoryInterface;
use App\Booking\Contracts\BookingAnalyticsServiceInterface;
use App\Booking\Contracts\BookingMeetingServiceInterface;
use App\Booking\Contracts\BookingPaymentServiceInterface;
use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Contracts\BookingTypeRepositoryInterface;
use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Contracts\GuestBookingServiceInterface;
use App\Booking\Contracts\RazorpayGatewayClient;
use App\Booking\Contracts\StripeGatewayClient;
use App\Booking\Contracts\StudentBookingServiceInterface;
use App\Booking\Contracts\StudentLessonPriceRepositoryInterface;
use App\Booking\Contracts\TeacherAssignmentServiceInterface;
use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\Gateways\GoogleCalendarSdkClient;
use App\Booking\Gateways\RazorpaySdkClient;
use App\Booking\Gateways\StripeSdkClient;
use App\Booking\Gateways\ZoomApiClient;
use App\Booking\Meetings\GoogleCalendarMeetProvider;
use App\Booking\Meetings\ManualMeetingProvider;
use App\Booking\Meetings\ZoomMeetingProvider;
use App\Booking\Payments\FakePaymentProvider;
use App\Booking\Payments\RazorpayPaymentProvider;
use App\Booking\Payments\StripePaymentProvider;
use App\Booking\Registry\AssignmentStrategyRegistry;
use App\Booking\Registry\BookingTypeRegistry;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Registry\PaymentProviderRegistry;
use App\Booking\Repositories\AvailabilityRepository;
use App\Booking\Repositories\BookingAnalyticsRepository;
use App\Booking\Repositories\BookingRepository;
use App\Booking\Repositories\BookingTypeRepository;
use App\Booking\Repositories\StudentLessonPriceRepository;
use App\Booking\Repositories\TeacherCandidateRepository;
use App\Booking\Services\AvailabilityService;
use App\Booking\Services\BookingAnalyticsService;
use App\Booking\Services\BookingMeetingService;
use App\Booking\Services\BookingPaymentService;
use App\Booking\Services\BookingService;
use App\Booking\Services\GuestBookingService;
use App\Booking\Services\MeetingProviderResolver;
use App\Booking\Services\PaymentProviderResolver;
use App\Booking\Services\StudentBookingService;
use App\Booking\Services\TeacherAssignmentService;
use App\Booking\Types\CounsellingType;
use App\Booking\Types\FreeDemoType;
use App\Booking\Types\PaidOneToOneType;
use App\Booking\Types\ParentMeetingType;
use App\Booking\Types\WebinarType;
use App\Booking\Validation\BookingValidationPipeline;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindInterfaces();
        $this->bindSingletons();
        $this->registerBookingTypes();
        $this->registerAssignmentEngine();
    }

    private function bindInterfaces(): void
    {
        $this->app->bind(BookingRepositoryInterface::class, BookingRepository::class);
        $this->app->bind(AvailabilityRepositoryInterface::class, AvailabilityRepository::class);
        $this->app->bind(BookingTypeRepositoryInterface::class, BookingTypeRepository::class);
        $this->app->bind(TeacherCandidateRepositoryInterface::class, TeacherCandidateRepository::class);
        $this->app->bind(BookingServiceInterface::class, BookingService::class);
        $this->app->bind(AvailabilityServiceInterface::class, AvailabilityService::class);
        $this->app->bind(TeacherAssignmentServiceInterface::class, TeacherAssignmentService::class);
        $this->app->bind(GuestBookingServiceInterface::class, GuestBookingService::class);
        $this->app->bind(StudentBookingServiceInterface::class, StudentBookingService::class);
        $this->app->bind(BookingPaymentServiceInterface::class, BookingPaymentService::class);
        $this->app->bind(BookingMeetingServiceInterface::class, BookingMeetingService::class);
        $this->app->bind(BookingAnalyticsRepositoryInterface::class, BookingAnalyticsRepository::class);
        $this->app->bind(BookingAnalyticsServiceInterface::class, BookingAnalyticsService::class);
        $this->app->bind(StudentLessonPriceRepositoryInterface::class, StudentLessonPriceRepository::class);

        // Official SDKs (razorpay/razorpay, stripe/stripe-php) are isolated
        // behind these two adapters — RazorpayPaymentProvider/StripePaymentProvider
        // never instantiate \Razorpay\Api\Api or \Stripe\StripeClient directly.
        // Tests bind a fake implementation instead of stubbing HTTP/cURL.
        $this->app->bind(RazorpayGatewayClient::class, RazorpaySdkClient::class);
        $this->app->bind(StripeGatewayClient::class, StripeSdkClient::class);

        // Same isolation for google/apiclient — GoogleCalendarMeetProvider
        // never instantiates \Google\Client or \Google\Service\Calendar
        // directly. Tests bind a fake implementation instead of stubbing
        // HTTP/the SDK.
        $this->app->bind(GoogleCalendarClient::class, GoogleCalendarSdkClient::class);

        // Zoom REST API (no third-party SDK — Laravel HTTP client only).
        // ZoomMeetingProvider never builds HTTP requests or sees tokens;
        // tests bind a fake ZoomMeetingClient.
        $this->app->bind(ZoomMeetingClient::class, ZoomApiClient::class);
    }

    private function bindSingletons(): void
    {
        $this->app->singleton(BookingTypeRegistry::class);
        $this->app->singleton(BookingValidationPipeline::class);
        $this->app->singleton(AssignmentStrategyRegistry::class);
        $this->app->singleton(PaymentProviderRegistry::class);
        $this->app->singleton(PaymentProviderResolver::class);
        $this->app->singleton(MeetingProviderRegistry::class);
        $this->app->singleton(MeetingProviderResolver::class);

        // New gateways: implement PaymentProviderInterface, register here,
        // switch via BookingSettings::payment_provider. Selection safety
        // (fake outside local/testing, misconfigured credentials) lives
        // in PaymentProviderResolver, not here — this only wires up what
        // exists.
        $this->app->afterResolving(PaymentProviderRegistry::class, function (PaymentProviderRegistry $registry, Application $app): void {
            $registry->register($app->make(FakePaymentProvider::class));
            $registry->register($app->make(RazorpayPaymentProvider::class));
            $registry->register($app->make(StripePaymentProvider::class));
        });

        // New meeting providers: implement MeetingProviderInterface,
        // register here, switch via MeetingSettings::default_provider (or
        // an explicit admin choice). Selection safety (misconfigured
        // credentials, manual_provider_enabled, the meetings_enabled kill
        // switch) lives in MeetingProviderResolver, not here. No
        // FakeMeetingProvider this phase — business decision.
        $this->app->afterResolving(MeetingProviderRegistry::class, function (MeetingProviderRegistry $registry, Application $app): void {
            $registry->register($app->make(ManualMeetingProvider::class));
            $registry->register($app->make(GoogleCalendarMeetProvider::class));
            $registry->register($app->make(ZoomMeetingProvider::class));
        });
    }

    private function registerAssignmentEngine(): void
    {
        // New scorers extend BestScoreStrategy by adding to this tag.
        $this->app->tag([
            WorkloadScorer::class,
            PriorityScorer::class,
            TimezoneScorer::class,
        ], 'booking.assignment_scorers');

        $this->app->bind(BestScoreStrategy::class, fn (Application $app): BestScoreStrategy => new BestScoreStrategy(
            $app->tagged('booking.assignment_scorers'),
        ));

        // New algorithms: implement AssignmentStrategyInterface, register here,
        // switch via BookingSettings::assignment_strategy.
        $this->app->afterResolving(AssignmentStrategyRegistry::class, function (AssignmentStrategyRegistry $registry, Application $app): void {
            $registry->register($app->make(BestScoreStrategy::class));
            $registry->register($app->make(LeastLoadedStrategy::class));
        });
    }

    private function registerBookingTypes(): void
    {
        $this->app->afterResolving(BookingTypeRegistry::class, function (BookingTypeRegistry $registry, Application $app): void {
            $registry->register($app->make(FreeDemoType::class));
            $registry->register($app->make(PaidOneToOneType::class));
            $registry->register($app->make(CounsellingType::class));
            $registry->register($app->make(ParentMeetingType::class));
            $registry->register($app->make(WebinarType::class));
        });
    }
}
