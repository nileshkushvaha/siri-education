<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Base for the Instructor domain's real-MySQL concurrency tests (Phase
 * 23D) — mirrors Tests\Feature\Reviews\Concurrency\ConcurrencyTestCase /
 * Tests\Feature\Booking\Concurrency\ConcurrencyTestCase /
 * Tests\Feature\Earnings\Concurrency\ConcurrencyTestCase's shape,
 * including the exit-code-checked migrate:fresh restore (Phase 16C.1
 * root-cause fix) so a transient teardown failure fails loudly instead
 * of silently leaking a partially-rebuilt database into the next test
 * class.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use RefreshDatabase;

    /** Commit for real — child processes must see the fixtures. */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

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
