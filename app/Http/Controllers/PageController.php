<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Faq\FaqService;
use App\Services\Instructor\InstructorService;
use App\Services\PageRenderService;
use App\Services\PageService;
use App\Services\PostService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Response;

class PageController extends Controller
{
    public function show(string $slug, PageService $pages, PageRenderService $renderer): Response
    {
        $page = $pages->getPublishedPage($slug) ?? abort(404);

        return response($renderer->render($page), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function home(
        PageService $pages,
        PostService $posts,
        PageRenderService $renderer,
        GeneralSettings $settings,
        InstructorService $instructors,
        FaqService $faqs,
    ): Response {
        // ── WordPress-style reading setting ──────────────────────────────
        if (($settings->homepage_display ?? 'template') === 'static_page' && filled($settings->homepage_id)) {
            $staticPage = $pages->getPublishedPageById($settings->homepage_id);

            if ($staticPage) {
                return response($renderer->render($staticPage), 200)
                    ->header('Content-Type', 'text/html; charset=UTF-8');
            }
        }

        if ($homepage = $pages->getHomepage()) {
            return response($renderer->render($homepage), 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // ── Default: render the custom home.blade.php template ───────────
        $recentPosts = $posts->latestPublishedPosts(3);

        return response()->view('home', [
            'appName' => $settings->app_name ?? config('app.name'),
            'appShortName' => $settings->app_short_name ?? null,
            'logo' => $settings->logo ?? null,
            'supportEmail' => $settings->support_email ?? null,
            'supportPhone' => $settings->support_phone ?? null,
            'address' => $settings->address ?? null,
            'footerText' => $settings->footer_text ?? null,
            'footerCopyright' => $settings->footer_copyright ?? null,
            'recentPosts' => $recentPosts,
            'featuredSubjects' => $instructors->featuredSubjects(),
            'featuredFaqs' => $faqs->homepage(),
        ]);
    }
}
