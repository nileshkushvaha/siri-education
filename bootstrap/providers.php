<?php

use App\Providers\AppServiceProvider;
use App\Providers\BookingServiceProvider;
use App\Providers\CmsServiceProvider;
use App\Providers\ComplianceServiceProvider;
use App\Providers\EarningServiceProvider;
use App\Providers\FeedbackServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FrontendServiceProvider;
use App\Providers\HomeworkServiceProvider;
use App\Providers\LessonServiceProvider;
use App\Providers\NavigationServiceProvider;
use App\Providers\QualityServiceProvider;
use App\Providers\ReferralServiceProvider;
use App\Providers\ReportingServiceProvider;
use App\Providers\ReviewServiceProvider;

return [
    AppServiceProvider::class,
    BookingServiceProvider::class,
    CmsServiceProvider::class,
    ComplianceServiceProvider::class,
    EarningServiceProvider::class,
    FrontendServiceProvider::class,
    HomeworkServiceProvider::class,
    LessonServiceProvider::class,
    NavigationServiceProvider::class,
    ReviewServiceProvider::class,
    QualityServiceProvider::class,
    FeedbackServiceProvider::class,
    ReferralServiceProvider::class,
    ReportingServiceProvider::class,
    AdminPanelProvider::class,
];
