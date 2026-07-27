<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Models\LessonTechnicalIssueReport;
use App\Models\Message;
use App\Models\SupportCase;
use App\Models\UserEducation;
use App\Models\UserExperience;

/**
 * The allowlist backing
 * SecureMediaDownloadController: a Media row's (model class,
 * collection name) pair must appear here before that controller will
 * even attempt authorization. This is what stops a media id that
 * happens to belong to some OTHER collection on the same model (or a
 * model with no business being served through this route at all) from
 * resolving here, independent of whatever a Policy might separately
 * allow.
 *
 * Homework, Recording, and instructor KYC documents already have their
 * own dedicated, already-private, already-tested download controllers
 * — deliberately not listed here, so
 * this registry only ever grows to cover genuinely new private
 * collections, never duplicates an existing boundary.
 */
final class PrivateMediaCollectionRegistry
{
    /** @var array<class-string, list<string>> */
    private const array COLLECTIONS = [
        Message::class => ['attachment'],
        SupportCase::class => ['evidence'],
        LessonTechnicalIssueReport::class => ['evidence'],
        UserExperience::class => ['supporting_documents'],
        UserEducation::class => ['certificate', 'transcript', 'degree_document'],
    ];

    public static function allows(string $modelClass, string $collectionName): bool
    {
        return in_array($collectionName, self::COLLECTIONS[$modelClass] ?? [], true);
    }

    /** @return array<class-string, list<string>> */
    public static function all(): array
    {
        return self::COLLECTIONS;
    }
}
