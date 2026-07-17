<?php

declare(strict_types=1);

namespace App\Providers;

use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\MarketplaceExecutiveReportServiceInterface;
use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\Contracts\ReferralCommunicationReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\Registry\MetricRegistry;
use App\Reporting\Registry\ReportRegistry;
use App\Reporting\Services\BookingLessonMeetingOperationsReportService;
use App\Reporting\Services\FinancialReportsService;
use App\Reporting\Services\InstructorPerformanceReportService;
use App\Reporting\Services\LearningAnalyticsReportService;
use App\Reporting\Services\MarketplaceExecutiveReportService;
use App\Reporting\Services\ReferralCommunicationReportService;
use App\Reporting\Services\ReportAccessContext;
use App\Reporting\Services\StudentEngagementReportService;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportAccessContextInterface::class, ReportAccessContext::class);
        $this->app->singleton(ReportRegistryInterface::class, ReportRegistry::class);
        $this->app->singleton(MetricRegistryInterface::class, MetricRegistry::class);
        $this->app->singleton(BookingLessonMeetingOperationsReportServiceInterface::class, BookingLessonMeetingOperationsReportService::class);
        $this->app->singleton(StudentEngagementReportServiceInterface::class, StudentEngagementReportService::class);
        $this->app->singleton(InstructorPerformanceReportServiceInterface::class, InstructorPerformanceReportService::class);
        $this->app->singleton(FinancialReportsServiceInterface::class, FinancialReportsService::class);
        $this->app->singleton(LearningAnalyticsReportServiceInterface::class, LearningAnalyticsReportService::class);
        $this->app->singleton(ReferralCommunicationReportServiceInterface::class, ReferralCommunicationReportService::class);
        $this->app->singleton(MarketplaceExecutiveReportServiceInterface::class, MarketplaceExecutiveReportService::class);
    }
}
