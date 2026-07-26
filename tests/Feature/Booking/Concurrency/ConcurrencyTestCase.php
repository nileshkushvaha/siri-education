<?php

declare(strict_types=1);

namespace Tests\Feature\Booking\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Base for the Booking domain's real-MySQL concurrency tests — mirrors
 * Tests\Feature\Earnings\Concurrency\ConcurrencyTestCase's shape.
 *
 * `tearDownAfterClass()` runs `migrate:fresh` in a subprocess to rebuild
 * the database for the next test class. Its exit code must be checked:
 * a just-finished race's child processes each hold their own MySQL
 * connection, and a connection's locks are not always guaranteed
 * released the instant the client process exits, so migrate:fresh's
 * DROP TABLE/metadata-lock acquisition can transiently collide with
 * that. An unchecked failure here is invisible and silently leaves the
 * next test class to inherit a partially-rebuilt database — checking
 * the exit code makes a genuine failure fail loudly, and a bounded
 * retry absorbs the transient case.
 *
 * Do not call `DB::purge()` here on the theory that this process's own
 * connection needs refreshing: `tearDownAfterClass()` runs after
 * Laravel's per-test application container has already been torn down,
 * so any Facade call here throws `Target class [config] does not
 * exist`. Each test class gets its own fresh application/connection via
 * Laravel's normal testing bootstrap regardless, so no purge is needed.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use RefreshDatabase;

    /** Commit for real — child processes must see the fixtures. */
    protected array $connectionsToTransact = [];

    public static function tearDownAfterClass(): void
    {
        $attempts = 0;
        $exitCode = null;
        $output = [];

        while ($attempts < 3) {
            $attempts++;
            $output = [];
            exec(
                sprintf('cd %s && php artisan migrate:fresh --env=testing --force 2>&1', escapeshellarg(base_path())),
                $output,
                $exitCode,
            );

            if ($exitCode === 0) {
                break;
            }

            usleep(300_000);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                "migrate:fresh failed while restoring the testing database after %s, even after %d attempts (exit %d):\n%s",
                static::class,
                $attempts,
                $exitCode,
                implode("\n", $output),
            ));
        }

        parent::tearDownAfterClass();
    }

    /**
     * Launch every operation as its own PHP process, all synchronized on
     * one start barrier, and return the decoded JSON verdicts.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $operations  [op, args] pairs
     * @return list<array<string, mixed>>
     */
    protected function race(array $operations): array
    {
        $startAtMs = (int) (microtime(true) * 1000) + 4000;

        $processes = array_map(function (array $operation) use ($startAtMs): Process {
            [$op, $args] = $operation;

            $process = new Process([
                PHP_BINARY,
                base_path('tests/Concurrency/run-op.php'),
                $op,
                json_encode($args, JSON_THROW_ON_ERROR),
                (string) $startAtMs,
            ], base_path(), ['APP_ENV' => 'testing'], null, 60);

            $process->start();

            return $process;
        }, $operations);

        return array_map(function (Process $process): array {
            $process->wait();

            $decoded = json_decode($process->getOutput(), true);

            $this->assertIsArray($decoded, 'Concurrency worker produced no JSON verdict: '.$process->getOutput().$process->getErrorOutput());

            return $decoded;
        }, $processes);
    }
}
