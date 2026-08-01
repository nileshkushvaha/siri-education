<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Post;
use App\Services\CmsCacheService;
use App\Services\Instructor\InstructorService;
use App\Settings\SeoSettings;
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

                // Same eligibility as the public listing/detail
                // pages (InstructorService::sitemapEntries() reuses baseQuery()).
                $instructors = $instructorService->sitemapEntries();

                return view('seo.sitemap', [
                    'pages' => $pages,
                    'posts' => $posts,
                    'instructors' => $instructors,
                ])->render();
            }
        );

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * robots.txt, honouring the global SEO robots directive.
     *
     * Admin → Settings → SEO → Robots was previously applied to the per-page
     * `<meta name="robots">` tag only (via SeoManager), while this file stayed
     * hardcoded to `Allow: /`. Setting the site to "noindex, nofollow" — the
     * usual pre-launch or staging posture — therefore left robots.txt actively
     * inviting crawlers, which is the opposite of what the setting says.
     *
     * Meta-robots and robots.txt are different mechanisms, so this is a
     * deliberate mapping rather than a passthrough: a `noindex` directive
     * disallows the whole site, and anything else keeps the normal allow-list.
     * The private areas stay disallowed either way.
     */
    public function robots(CmsCacheService $cacheService, SeoSettings $seoSettings): Response
    {
        $noIndex = str_contains(strtolower($seoSettings->robots ?? ''), 'noindex');

        $content = Cache::remember(
            $cacheService->robotsKey(),
            now()->addSeconds(max(1, (int) config('cms.cache.robots_ttl', 3600))),
            static fn (): string => implode("\n", array_filter([
                'User-agent: *',
                $noIndex ? 'Disallow: /' : 'Allow: /',
                $noIndex ? null : 'Disallow: /admin',
                $noIndex ? null : 'Disallow: /login',
                $noIndex ? null : 'Disallow: /dashboard',
                'Sitemap: '.route('seo.sitemap'),
                '',
            ], static fn (?string $line): bool => $line !== null))
        );

        return response($content, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
