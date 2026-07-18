<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Services\CmsCacheService;
use App\Services\Instructor\InstructorService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SeoController extends Controller
{
    public function sitemap(CmsCacheService $cacheService, InstructorService $instructorService): Response
    {
        $xml = Cache::remember(
            $cacheService->sitemapKey(),
            now()->addSeconds(max(1, (int) config('cms.cache.sitemap_ttl', 900))),
            function () use ($instructorService): string {
                $pages = Page::query()
                    ->published()
                    ->select(['slug', 'updated_at'])
                    ->latest('updated_at')
                    ->get();

                $posts = Post::query()
                    ->published()
                    ->select(['slug', 'updated_at'])
                    ->latest('updated_at')
                    ->get();

                // Public form pages have no CMS-managed content, so they carry
                // no updated_at — they're listed with a static low priority.
                $staticRoutes = [
                    'forms.callback',
                    'forms.feedback',
                    'forms.support',
                    'forms.inquiry',
                ];

                // Phase 23F — same eligibility as the public listing/detail
                // pages (InstructorService::sitemapEntries() reuses baseQuery()).
                $instructors = $instructorService->sitemapEntries();

                return view('seo.sitemap', [
                    'pages' => $pages,
                    'posts' => $posts,
                    'staticRoutes' => $staticRoutes,
                    'instructors' => $instructors,
                ])->render();
            }
        );

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(CmsCacheService $cacheService): Response
    {
        $content = Cache::remember(
            $cacheService->robotsKey(),
            now()->addSeconds(max(1, (int) config('cms.cache.robots_ttl', 3600))),
            static fn (): string => implode("\n", [
                'User-agent: *',
                'Allow: /',
                'Disallow: /admin',
                'Disallow: /login',
                'Disallow: /dashboard',
                'Sitemap: '.route('seo.sitemap'),
                '',
            ])
        );

        return response($content, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
