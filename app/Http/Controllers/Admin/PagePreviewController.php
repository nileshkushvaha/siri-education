<?php

namespace App\Http\Controllers\Admin;

use App\Content\Rendering\ContentRenderer;
use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PagePreviewController extends Controller
{
    /**
     * Preview a page (draft, scheduled, or archived)
     *
     * @throws AuthorizationException
     */
    public function __invoke(Request $request, Page $page, ContentRenderer $renderService): Response
    {
        // The framework's base controller no longer provides
        // AuthorizesRequests, so the Gate facade is the supported
        // equivalent here — same policy, same AuthorizationException.
        Gate::authorize('view', $page);

        // Render the page using the same service
        try {
            $html = $renderService->renderPreview($page);
            $seo = $renderService->getSeoMetadata($page);
            $structuredData = $renderService->getStructuredData($page);
        } catch (\Exception $e) {
            Log::error('Page preview rendering failed', [
                'page_id' => $page->id,
                'exception' => $e,
            ]);

            return response('Preview error. Please try again.', 500);
        }

        activity('pages')
            ->performedOn($page)
            ->causedBy(auth()->user())
            ->event('previewed')
            ->withProperties(['source' => 'admin_preview'])
            ->log('Page previewed');

        // Return preview response (this will be wrapped in a modal by Filament)
        return response()->view('admin.page-preview', [
            'page' => $page,
            'html' => $html,
            'seo' => $seo,
            'structured_data' => $structuredData,
        ]);
    }
}
