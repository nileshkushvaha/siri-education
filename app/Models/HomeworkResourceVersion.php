<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Media\Concerns\HasStandardImageConversions;
use Database\Factories\HomeworkResourceVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * SRS-7-8: one immutable, published version of a
 * HomeworkResource. The file never changes after publish — a "file
 * update" always creates a new HomeworkResourceVersion row (and a new
 * media attachment) instead, so assignments already linked to an
 * earlier version keep their exact historical content.
 */
class HomeworkResourceVersion extends Model implements HasMedia
{
    /** @use HasFactory<HomeworkResourceVersionFactory> */
    use HasFactory, HasStandardImageConversions, HasUuids, InteractsWithMedia;

    protected $fillable = [
        'homework_resource_id',
        'version_number',
        'created_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    }

    /** GAP-037 — small private preview for image resource versions; PDFs are never converted (mime-guarded), stay download-only. */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->skipStandardImageConversions($media)) {
            return;
        }

        $this->addPreviewConversion('file');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(HomeworkResource::class, 'homework_resource_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(HomeworkAssignment::class, 'homework_assignment_resources')
            ->withPivot('attached_by')
            ->withTimestamps();
    }
}
