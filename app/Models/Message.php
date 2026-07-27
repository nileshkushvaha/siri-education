<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use App\Support\Media\Concerns\HasStandardImageConversions;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One message, immutable in content (SRS §17.31/§17.41 — no edit
 * path exists anywhere for `body`). Written exclusively by
 * MessagingService: `send()` creates the row; the only other writer
 * is `markRead()`, which touches `read_at` alone. Not
 * PreventsUpdates-guarded (unlike SupportCaseReply) precisely because
 * that legitimate `read_at` mutation must remain possible —
 * immutability of `body` is a service-boundary discipline, not a
 * blanket model guard. `flagged_leakage`/`flagged_leakage_reasons`
 * are deterministic policy flags set once at creation — never mutated
 * afterwards (no auto-redaction of `body`, per requirement #6).
 */
class Message extends Model implements HasMedia
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasStandardImageConversions, HasUuids, InteractsWithMedia, PreventsHardDeletion;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'sent_at',
        'read_at',
        'flagged_leakage',
        'flagged_leakage_reasons',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'flagged_leakage' => 'boolean',
            'flagged_leakage_reasons' => 'array',
        ];
    }

    /** Private: messaging attachments may contain personal content and must never sit on the public disk. */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachment')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    }

    /** Small private preview for image attachments; PDFs are never converted (mime-guarded), stay download-link-only. */
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($this->skipStandardImageConversions($media)) {
            return;
        }

        $this->addPreviewConversion('attachment');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(MessageReport::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
