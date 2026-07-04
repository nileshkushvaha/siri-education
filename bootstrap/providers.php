<?php

use App\Providers\AppServiceProvider;
use App\Providers\BookingServiceProvider;
use App\Providers\CmsServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FormsServiceProvider;
use App\Providers\FrontendServiceProvider;
use App\Providers\HomeworkServiceProvider;
use App\Providers\NavigationServiceProvider;

return [
    AppServiceProvider::class,
    BookingServiceProvider::class,
    CmsServiceProvider::class,
    FormsServiceProvider::class,
    FrontendServiceProvider::class,
    HomeworkServiceProvider::class,
    NavigationServiceProvider::class,
    AdminPanelProvider::class,
];
