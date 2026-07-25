<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use App\Support\Concerns\PreventsUpdates;
use App\SupportCases\Enums\SupportCaseReplyVisibility;
use Database\Factories\SupportCaseReplyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable reply or internal note against a SupportCase (§25.19-
 * §25.20). `visibility` is the single flag every query/view must
 * filter on before exposing a reply to a student or instructor.
 * Written exclusively by SupportCaseService::addReply().
 */
class SupportCaseReply extends Model
{
    /** @use HasFactory<SupportCaseReplyFactory> */
    use HasFactory, HasUuids, PreventsHardDeletion, PreventsUpdates;

    protected $fillable = [
        'support_case_id',
        'author_id',
        'visibility',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => SupportCaseReplyVisibility::class,
        ];
    }

    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isInternalNote(): bool
    {
        return $this->visibility === SupportCaseReplyVisibility::InternalNote;
    }
}
