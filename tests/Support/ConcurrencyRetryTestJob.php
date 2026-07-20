<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 24N — GAP-034: a trivial job used only by
 * FailedJobRetryConcurrencyTest. Must live in its own PSR-4-autoloadable
 * file (not inline in the test file) — the concurrency harness's child
 * processes (tests/Concurrency/run-op.php) resolve classes purely via
 * Composer's autoloader, which cannot locate a second class declared
 * inside another file.
 */
final class ConcurrencyRetryTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void {}
}
