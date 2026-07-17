<?php

declare(strict_types=1);

namespace App\Providers;

use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Contracts\MetricRegistryInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\Registry\MetricRegistry;
use App\Reporting\Registry\ReportRegistry;
use App\Reporting\Services\BookingLessonMeetingOperationsReportService;
use App\Reporting\Services\InstructorPerformanceReportService;
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
    }
}
