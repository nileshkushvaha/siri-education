<?php

namespace App\Observers;

use App\Models\Page;
use App\Services\Cms\StructuredPageContentService;
use App\Services\CmsCacheService;
use App\Services\PageRenderService;

class PageObserver
{
    public function updating(Page $page): void
    {
        app(StructuredPageContentService::class)->preserveStructureDuringUpdate($page);
    }

    /**
     * Handle the Page "created" event.
     */
    public function created(Page $page): void
    {
        app(CmsCacheService::class)->bumpDiscoveryVersion();
    }

    /**
     * Handle the Page "updated" event.
     */
    public function updated(Page $page): void
    {
        app(PageRenderService::class)->invalidateCache($page);
        app(CmsCacheService::class)->bumpDiscoveryVersion();
    }

    /**
     * Handle the Page "deleted" event.
     */
    public function deleted(Page $page): void
    {
        app(PageRenderService::class)->invalidateCache($page);
        app(CmsCacheService::class)->bumpDiscoveryVersion();
    }

    /**
     * Handle the Page "restored" event.
     */
    public function restored(Page $page): void
    {
        app(PageRenderService::class)->invalidateCache($page);
        app(CmsCacheService::class)->bumpDiscoveryVersion();
    }

    /**
     * Handle the Page "force deleted" event.
     */
    public function forceDeleted(Page $page): void
    {
        app(PageRenderService::class)->invalidateCache($page);
        app(CmsCacheService::class)->bumpDiscoveryVersion();
    }
}
