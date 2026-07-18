<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Components\InstructorDocumentViewer;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The real security boundary for KYC documents (Phase 23E) — every
 * request re-checks InstructorDocumentPolicy live; there is no
 * pre-generated signed/temporary URL to leak or bookmark. A bookmarked
 * link to this route is worthless without a currently authenticated,
 * currently authorized admin session, which is strictly tighter than a
 * time-boxed signed URL would be.
 */
final class InstructorDocumentDownloadController extends Controller
{
    public function __construct(
        private readonly AuditTrailService $auditTrail,
    ) {}

    public function __invoke(Media $media): StreamedResponse
    {
        $admin = auth()->user();
        abort_unless($admin instanceof User, 403);

        // Only the recognized KYC collections may be served through this
        // route — an avatar/cover media id (or any other model's media)
        // must never resolve here, even if View:User is held.
        abort_unless(array_key_exists($media->collection_name, InstructorDocumentViewer::COLLECTIONS), 403);

        $profile = $media->model;
        abort_unless($profile instanceof UserProfile, 403);

        Gate::authorize('instructor.viewDocuments', $profile);

        $this->auditTrail->logUser(
            $admin,
            'instructor',
            'instructor_document_downloaded',
            'Instructor document downloaded',
            $profile->user,
            [
                'instructor_id' => $profile->user_id,
                'collection' => $media->collection_name,
                'media_id' => $media->id,
            ],
        );

        return Storage::disk($media->disk)->download(
            $media->getPathRelativeToRoot(),
            $media->getDownloadFilename(),
        );
    }
}
