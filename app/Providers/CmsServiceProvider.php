<?php

declare(strict_types=1);

namespace App\Providers;

use App\Content\Models\ContentBlock;
use App\Content\Rendering\ContentRenderer;
use App\Models\Page;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Redirect;
use App\Models\Tag;
use App\Observers\ContentBlockObserver;
use App\Policies\ContentBlockPolicy;
use App\Policies\RedirectPolicy;
use App\Services\PageRenderService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ContentRenderer singleton — PageRenderService extends ContentRenderer,
        // so both abstracts resolve to the same instance.
        $this->app->singleton(PageRenderService::class);
        $this->app->bind(ContentRenderer::class, PageRenderService::class);

        // Future: Menu bindings
        // $this->app->singleton(MenuService::class);

        // Future: Search bindings
        // $this->app->singleton(CmsSearchService::class);
    }

    public function boot(): void
    {
        $this->registerMorphMap();
        $this->registerObservers();
        $this->registerPolicies();
    }

    private function registerMorphMap(): void
    {
        Relation::morphMap([
            'page' => Page::class,
            'post' => Post::class,
            'category' => PostCategory::class,
            'tag' => Tag::class,
        ]);
    }

    private function registerObservers(): void
    {
        ContentBlock::observe(ContentBlockObserver::class);
    }

    private function registerPolicies(): void
    {
        Gate::policy(ContentBlock::class, ContentBlockPolicy::class);
        Gate::policy(Redirect::class, RedirectPolicy::class);
    }
}
