<?php

namespace App\Http\Controllers\Admin;

use App\Content\Rendering\ContentRenderer;
use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PostPreviewController extends Controller
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(Request $request, Post $post, ContentRenderer $renderService): Response
    {
        // The framework's base controller no longer provides
        // AuthorizesRequests, so the Gate facade is the supported
        // equivalent here — same policy, same AuthorizationException.
        Gate::authorize('view', $post);

        try {
            $html = $renderService->renderPostPreview($post->load([
                'author',
                'blocks' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            ]));
            $seo = $renderService->getPostSeoMetadata($post);
            $structuredData = $renderService->getPostStructuredData($post);
        } catch (\Exception $e) {
            Log::error('Post preview rendering failed', [
                'post_id' => $post->id,
                'exception' => $e,
            ]);

            return response('Preview error. Please try again.', 500);
        }

        activity('posts')
            ->performedOn($post)
            ->causedBy(auth()->user())
            ->event('previewed')
            ->withProperties(['source' => 'admin_preview'])
            ->log('Post previewed');

        return response()->view('admin.page-preview', [
            'page' => $post,
            'post' => $post,
            'html' => $html,
            'seo' => $seo,
            'structured_data' => $structuredData,
        ]);
    }
}
