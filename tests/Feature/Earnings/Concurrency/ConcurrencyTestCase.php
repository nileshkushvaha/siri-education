<?php

declare(strict_types=1);

namespace Tests\Feature\Earnings\Concurrency;

use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Support\FinancialFeatureToggle;
use App\Enums\InstructorStatus;
use App\Models\Currency;
use App\Models\InstructorEarning;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use App\Settings\InstructorEarningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Base for the real-MySQL concurrency tests. These are TRUE
 * multi-process races: each scenario launches two independent PHP
 * worker processes (tests/Concurrency/run-op.php) that boot the app
 * against the testing database, spin on a shared time barrier, and hit
 * the service layer simultaneously on separate MySQL connections.
 *
 * Test data therefore has to be COMMITTED, not transaction-wrapped:
 * `$connectionsToTransact = []` disables RefreshDatabase's per-test
 * transaction, and tearDownAfterClass() re-freshes the database so the
 * committed leftovers never leak into the rest of the suite. Every
 * assertion in these tests is scoped to the instructor created for
 * that test.
 *
 * Known limitation (documented, not hidden): the time barrier makes
 * overlap overwhelmingly likely but cannot force MySQL to interleave at
 * a specific statement. The scenarios are therefore designed so that
 * EVERY interleaving of a correct implementation satisfies the
 * assertions, while an implementation without the owner-row lock or
 * in-transaction revalidation admits interleavings that violate them.
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
        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $settings = app(InstructorEarningSettings::class);
        $settings->withdrawals_enabled = true;
        $settings->minimum_withdrawal_minor = 10000;
        // High enough that the active-request limit never masks the
        // balance/locking behavior under test.
        $settings->maximum_active_requests_per_instructor = 5;
        FinancialFeatureToggle::unguarded(fn () => $settings->save());
    }

    public static function tearDownAfterClass(): void
    {
        // Committed fixtures must not leak into the transaction-wrapped
        // remainder of the suite.
        //
        // This call used to discard exec()'s exit code entirely — a
        // transient migration failure (the just-finished race's child
        // processes each hold their own MySQL connection; a connection's
        // locks are not always guaranteed released the instant the
        // client process exits, and migrate:fresh's DROP TABLE/metadata-
        // lock acquisition can transiently collide with that) was
        // completely invisible, silently leaving a later, unrelated test
        // class to inherit a partially-rebuilt database. The exit code
        // is now checked, a genuine failure fails loudly instead of
        // silently, and a bounded retry absorbs the transient case.
        // (`DB::purge()` was tried here too and reverted — it throws
        // "Target class [config] does not exist", since tearDownAfterClass()
        // runs after Laravel's per-test application container is already
        // torn down; each test class gets its own fresh connection via
        // the normal testing bootstrap regardless, so no purge is needed.)
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
        // Generous barrier: both workers must finish booting before it.
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

    protected function makeInstructor(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('instructor');
        $user->profile->update(['instructor_status' => InstructorStatus::Active]);

        return $user;
    }

    protected function verifiedMethod(User $instructor): InstructorPayoutMethod
    {
        return InstructorPayoutMethod::factory()->verified()->create([
            'instructor_id' => $instructor->id,
            'currency_code' => 'INR',
            'currency_id' => Currency::query()->where('code', 'INR')->value('id'),
        ]);
    }

    protected function releasableEarning(User $instructor, int $amountMinor): InstructorEarning
    {
        return InstructorEarning::factory()->releasable()->create([
            'instructor_id' => $instructor->id,
            'earning_amount_minor' => $amountMinor,
            'currency_code' => 'INR',
            'status' => InstructorEarningStatus::Releasable,
        ]);
    }
}
