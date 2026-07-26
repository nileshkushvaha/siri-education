<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GAP-039 requirement #2 — one row per (template_key, channel). Every
 * write goes through NotificationTemplateService — this model has no
 * own business logic beyond casts/relations, matching CLAUDE.md
 * (Services own business logic, models stay thin). subject/body being
 * null means "no override — use the code-owned default from
 * NotificationTemplateRegistry".
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'template_key',
        'channel',
        'subject',
        'body',
        'is_active',
        'version',
        'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'template_key' => NotificationTemplateKey::class,
            'channel' => NotificationTemplateChannel::class,
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function hasOverride(): bool
    {
        return $this->subject !== null || $this->body !== null;
    }
}
