<?php

declare(strict_types=1);

namespace App\Models;

use App\Content\Redirects\Enums\RedirectType;
use Database\Factories\RedirectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GAP-036 (SRS §22.25/26): a managed 301/302 redirect. Deactivated
 * (never hard-deleted in normal operation) rows are kept for historical
 * auditability — `active_source_path` (a DB-generated column, see the
 * migration) is what actually enforces "unique among active sources,"
 * not `source_path` itself, so a deactivated row never blocks a new
 * active redirect from reusing the same source.
 *
 * No LogsActivity trait here (unlike Page/Post's legacy pattern) —
 * CLAUDE.md's audit rule is authoritative project-wide: RedirectService
 * writes every lifecycle event through AuditTrailService exclusively,
 * so a passive trait hook would only produce duplicate entries.
 */
class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'source_path',
        'target_path',
        'type',
        'is_active',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => RedirectType::class,
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
