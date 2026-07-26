<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Booking\Services\RecordingService;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GAP-028 requirement #6 — the real security boundary for lesson
 * recordings, mirroring HomeworkResourceDownloadController exactly:
 * every request re-checks RecordingPolicy::view() live via
 * RecordingService::assertCanAccess(). No signed/pre-generated URL to
 * leak; an expired recording's media row no longer exists, so a stale
 * link 404s rather than ever serving content.
 */
final class RecordingDownloadController extends Controller
{
    public function __invoke(Media $media, RecordingService $recordings): StreamedResponse
    {
        abort_unless($media->collection_name === 'file', 403);

        $recording = $media->model;
        abort_unless($recording instanceof Recording, 403);

        $recordings->assertCanAccess(auth()->user(), $recording);

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->getDownloadFilename(),
        );
    }
}
