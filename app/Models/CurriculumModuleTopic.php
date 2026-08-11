<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps an existing SubjectTopic into a CurriculumModule, ordered
 * within the module (SRS Book 2 §4.11). Deliberately a mapping row,
 * not a duplicate topic record — see
 * docs/architecture/phase-12.5-academic-taxonomy-subject-topics.md.
 * The referenced topic must belong to the same Subject as the owning
 * Curriculum; that rule is enforced in CurriculumService, not here.
 */
class CurriculumModuleTopic extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected $fillable = [
        'curriculum_module_id',
        'subject_topic_id',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CurriculumModuleTopic $pivot): void {
            $pivot->created_by ??= auth()->id();
        });
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CurriculumModule::class, 'curriculum_module_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SubjectTopic::class, 'subject_topic_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
