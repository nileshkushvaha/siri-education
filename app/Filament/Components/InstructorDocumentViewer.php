<?php

declare(strict_types=1);

namespace App\Filament\Components;

use App\Models\UserProfile;

/**
 * Builds the read-only KYC display rows for the admin "Verification
 * Documents" section (Phase 23E) — replaces the raw
 * SpatieMediaLibraryFileUpload fields that previously exposed uploaded
 * files directly. Never returns a storage/media-library URL: the
 * "download_url" it produces always points at the app's own
 * authorization-gated route (InstructorDocumentDownloadController),
 * which re-checks InstructorDocumentPolicy on every request. This
 * class deliberately performs no authorization itself — the Filament
 * section that renders it is already gated on the same policy (see
 * UserForm.php), and the download route is the real enforcement
 * boundary regardless of whether this view was ever rendered.
 */
final class InstructorDocumentViewer
{
    public const COLLECTIONS = [
        'government_id' => 'Government ID',
        'address_proof' => 'Address Proof',
        'education_certificate' => 'Education Certificate',
        'teaching_certificate' => 'Teaching Certificate',
        'resume' => 'Resume',
        'introduction_video' => 'Introduction Video',
    ];

    /**
     * @return list<array{collection: string, label: string, uploaded: bool, download_url: ?string}>
     */
    public static function rows(UserProfile $profile): array
    {
        return collect(self::COLLECTIONS)
            ->map(function (string $label, string $collection) use ($profile): array {
                $media = $profile->getFirstMedia($collection);

                return [
                    'collection' => $collection,
                    'label' => $label,
                    'uploaded' => $media !== null,
                    'download_url' => $media !== null
                        ? route('admin.instructor-documents.download', $media)
                        : null,
                ];
            })
            ->values()
            ->all();
    }
}
