<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Blocks physical deletion of a historical business record. Throws rather than
 * silently returning false, so a caller can never accidentally ignore
 * a blocked delete.
 *
 * A model using `Illuminate\Database\Eloquent\SoftDeletes` only has
 * `forceDelete()` blocked — its normal `delete()` legitimately sets
 * `deleted_at` and is unaffected. A model WITHOUT `SoftDeletes` has
 * its normal `delete()` blocked too, since for that model `delete()`
 * *is* the physical deletion — there is no soft alternative to fall
 * back to, by design (nothing here adds soft-delete support merely to
 * make deletion possible).
 *
 * Deliberately hooks the `deleting`/`forceDeleting` Eloquent MODEL
 * EVENTS only — raw DDL (`migrate:fresh`, `migrate:rollback`) and
 * `RefreshDatabase`'s schema truncation never pass through these
 * events at all, so this guard cannot interfere with migrations or
 * test-database cleanup.
 */
trait PreventsHardDeletion
{
    public static function bootPreventsHardDeletion(): void
    {
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleting(function (self $model): void {
                throw new HistoricalRecordCannotBeDeletedException(sprintf(
                    '%s #%s is a historical business record and cannot be permanently deleted.',
                    class_basename($model),
                    $model->getKey(),
                ));
            });

            return;
        }

        static::deleting(function (self $model): void {
            throw new HistoricalRecordCannotBeDeletedException(sprintf(
                '%s #%s is a historical business record and cannot be deleted.',
                class_basename($model),
                $model->getKey(),
            ));
        });
    }
}
