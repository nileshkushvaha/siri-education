<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Homework\Enums\HomeworkResourceCollection;
use App\Http\Controllers\Controller;
use App\Models\HomeworkAssignment;
use App\Models\HomeworkResourceVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GAP-022 — the real security boundary for homework resources,
 * submission attachments, AND (37A) library resource-version files,
 * mirroring InstructorDocumentDownloadController: every request
 * re-checks the owning model's policy live, so a bookmarked link is
 * worthless without a currently authenticated, currently authorized
 * session. There is no signed/pre-generated URL to leak, and a removed
 * resource's media row no longer exists, so a stale link 404s rather
 * than ever serving content. One shared route/controller for both
 * media-owning models — not a second download system (37A requirement #2).
 */
final class HomeworkResourceDownloadController extends Controller
{
    public function __invoke(Media $media): StreamedResponse
    {
        $model = $media->model;

        if ($model instanceof HomeworkAssignment) {
            // Only the two recognized homework collections may be served
            // through this route — a media id belonging to any other
            // collection on this model must never resolve here.
            abort_unless(HomeworkResourceCollection::tryFrom($media->collection_name) !== null, 403);

            Gate::authorize('view', $model);
        } elseif ($model instanceof HomeworkResourceVersion) {
            abort_unless($media->collection_name === 'file', 403);

            Gate::authorize('view', $model);
        } else {
            abort(403);
        }

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->getDownloadFilename(),
        );
    }
}
