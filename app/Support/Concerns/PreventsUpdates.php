<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Exceptions\ImmutableRecordCannotBeUpdatedException;

/**
 * Blocks every ordinary update to a fully immutable record — the
 * update-side counterpart to PreventsHardDeletion. Combine both traits
 * on a model that must never change or disappear after creation (e.g.
 * Invoice): no field, including a mass-assignment or forceFill(), may
 * be changed once the row exists. Throws rather than silently
 * ignoring the write, so a caller can never mistake a blocked update
 * for a successful no-op.
 *
 * Hooks the `updating` Eloquent MODEL EVENT only — raw DDL and
 * `RefreshDatabase`'s schema truncation never pass through it, so this
 * guard cannot interfere with migrations or test-database cleanup.
 */
trait PreventsUpdates
{
    public static function bootPreventsUpdates(): void
    {
        static::updating(function (self $model): void {
            throw new ImmutableRecordCannotBeUpdatedException(sprintf(
                '%s #%s is immutable and cannot be updated after creation.',
                class_basename($model),
                $model->getKey(),
            ));
        });
    }
}
