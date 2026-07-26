<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AuditTrailService;
use App\Support\Media\PrivateMediaCollectionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Phase 41A requirement #6 — one-time (safely rerunnable) cleanup for
 * historical media rows that predate the private-disk collection
 * change: any row still on the 'public' disk for a now-private
 * collection gets its bytes (original + any generated conversions)
 * copied to the 'local' disk, verified by size, and only then does the
 * Media row's `disk` column flip and the old public copy get deleted.
 *
 * Naturally idempotent: the query only ever selects `disk = 'public'`
 * rows, so an already-migrated row simply never matches again on a
 * rerun — no separate "already done" bookkeeping needed. Bounded via
 * --batch-size so a large historical table is never swept in one run.
 * One row's failure is reported and skipped — it never aborts the
 * batch or touches that row's data.
 */
final class MigrateMediaToPrivateStorage extends Command
{
    protected $signature = 'media:migrate-to-private
        {--dry-run : Report what would be migrated without changing anything}
        {--batch-size=100 : Maximum number of media rows to process in this run}';

    protected $description = 'Move historical media for now-private collections off the public disk';

    public function handle(AuditTrailService $audit): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch-size'));

        $migrated = 0;
        $failed = 0;
        $processed = 0;

        foreach (PrivateMediaCollectionRegistry::all() as $modelClass => $collections) {
            if ($processed >= $batchSize) {
                break;
            }

            $query = Media::query()
                ->where('model_type', $modelClass)
                ->whereIn('collection_name', $collections)
                ->where('disk', 'public')
                ->limit($batchSize - $processed);

            foreach ($query->cursor() as $media) {
                $processed++;

                if ($dryRun) {
                    $this->line(sprintf('[dry-run] would migrate media #%s (%s / %s)', $media->id, class_basename($modelClass), $media->collection_name));

                    continue;
                }

                try {
                    $this->migrateOne($media);
                    $migrated++;

                    $audit->logSystem(
                        'media',
                        'media_privatized',
                        'Historical media moved from public to private storage.',
                        $media,
                        ['model_type' => $modelClass, 'collection_name' => $media->collection_name, 'from_disk' => 'public', 'to_disk' => 'local'],
                    );
                } catch (Throwable $e) {
                    $failed++;
                    $this->error(sprintf('Media #%s failed to migrate: %s', $media->id, $e->getMessage()));
                }

                if ($processed >= $batchSize) {
                    break;
                }
            }
        }

        $this->info($dryRun
            ? sprintf('Dry run complete. %d media row(s) would be processed.', $processed)
            : sprintf('Migrated %d media row(s), %d failed, %d processed.', $migrated, $failed, $processed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migrateOne(Media $media): void
    {
        $paths = [$media->getPathRelativeToRoot(), ...$this->conversionPaths($media)];

        foreach ($paths as $path) {
            $this->copyAndVerify($path);
        }

        // Only after every file (original + conversions) is copied and
        // verified does the row flip — never a partial state where the
        // DB says 'local' but a conversion file is still only public.
        $media->forceFill(['disk' => 'local'])->save();

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return list<string> */
    private function conversionPaths(Media $media): array
    {
        return array_map(
            fn (string $conversionName): string => $media->getPathRelativeToRoot($conversionName),
            array_keys(array_filter($media->generated_conversions ?? [])),
        );
    }

    private function copyAndVerify(string $path): void
    {
        if (! Storage::disk('public')->exists($path)) {
            throw new RuntimeException('source file missing on public disk');
        }

        $stream = Storage::disk('public')->readStream($path);

        if ($stream === null) {
            throw new RuntimeException('could not open source file stream');
        }

        Storage::disk('local')->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $sourceSize = Storage::disk('public')->size($path);
        $destSize = Storage::disk('local')->size($path);

        if ($sourceSize !== $destSize) {
            Storage::disk('local')->delete($path);

            throw new RuntimeException('copy verification failed: size mismatch');
        }
    }
}
