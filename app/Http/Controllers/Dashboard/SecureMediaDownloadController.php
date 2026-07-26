<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\Media\PrivateMediaCollectionRegistry;
use App\Support\Media\StandardImageConversion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 41A requirement #4 — the one reusable, policy-authorized
 * download boundary for every genuinely private, non-Homework/
 * Recording/KYC media collection (Message, SupportCase,
 * LessonTechnicalIssueReport, UserExperience::supporting_documents,
 * UserEducation). Every request:
 *
 *  1. authenticates (route sits behind the dashboard 'auth' group);
 *  2. verifies the media's (model class, collection) pair is an
 *     expected one (PrivateMediaCollectionRegistry) — never resolves
 *     for a foreign/unexpected collection even if the id is valid;
 *  3. authorizes against the OWNING record via Gate::authorize('view', ...) —
 *     resolves to whichever Policy is registered for that model class,
 *     so cross-user and foreign-media access is denied by the same
 *     rules the rest of the app already enforces for that model;
 *  4. returns a sanitized filename (Media::getDownloadFilename()) and
 *     never a raw storage path.
 *
 * `?preview=1` serves the (already-private, same-disk) 'preview'
 * conversion inline instead of forcing a download — this is what keeps
 * Phase 41's homework/message inline thumbnails behind the exact same
 * authorization check as the full download, per requirement #5.
 */
final class SecureMediaDownloadController extends Controller
{
    public function __invoke(Request $request, Media $media): Response
    {
        $model = $media->model;
        abort_if($model === null, 404);

        abort_unless(PrivateMediaCollectionRegistry::allows($model::class, $media->collection_name), 403);

        Gate::authorize('view', $model);

        if ($request->boolean('preview') && $media->hasGeneratedConversion(StandardImageConversion::Preview->value)) {
            return response()->file(
                Storage::disk($media->disk)->path($media->getPathRelativeToRoot(StandardImageConversion::Preview->value)),
            );
        }

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->getDownloadFilename(),
        );
    }
}
