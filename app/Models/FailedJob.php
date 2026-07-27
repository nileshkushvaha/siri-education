<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read/display model for the standard Laravel
 * `failed_jobs` table (created by the framework's own jobs-table
 * migration, not owned by this app). Used only by the admin Filament
 * table for listing/selecting rows — the actual retry/forget
 * operations always go through FailedJobRetryService, which uses
 * Laravel's own queue.failer provider (keyed by `uuid`), never direct
 * Eloquent writes on this model.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'failed_at' => 'datetime',
        ];
    }
}
