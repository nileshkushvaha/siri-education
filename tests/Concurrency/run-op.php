<?php

declare(strict_types=1);
use App\Earnings\Contracts\InstructorCompensationAgreementServiceInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\InstructorPayoutMethodServiceInterface;
use App\Earnings\Contracts\InstructorPeriodicCompensationServiceInterface;
use App\Earnings\Contracts\InstructorWithdrawalServiceInterface;
use App\Earnings\Exceptions\EarningException;
use App\Models\InstructorCompensationAgreement;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

/**
 * Child-process worker for the real-MySQL concurrency tests
 * (tests/Feature/Earnings/Concurrency). Boots the application against
 * the TESTING environment, spins on a shared start barrier so two
 * workers hit the database at the same instant, executes exactly one
 * financial operation through the real service layer, and prints a
 * JSON verdict. Never run against a non-testing environment.
 *
 * Usage: php tests/Concurrency/run-op.php <operation> <json-args> <start-at-unix-ms>
 */
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, 'SAFETY ABORT: not the testing environment.');
    exit(1);
}

[$operation, $args, $startAtMs] = [$argv[1], json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR), (int) $argv[3]];

// Barrier: both workers boot (slow, jittery), then spin until the
// agreed instant so the actual database work overlaps.
while ((int) (microtime(true) * 1000) < $startAtMs) {
    usleep(200);
}

try {
    $result = match ($operation) {
        'withdraw' => (function () use ($args) {
            $instructor = User::query()->findOrFail($args['instructor_id']);
            $method = InstructorPayoutMethod::query()->findOrFail($args['method_id']);

            $request = app(InstructorWithdrawalServiceInterface::class)
                ->requestWithdrawal($instructor, $method, (int) $args['amount_minor'], null, $args['idempotency_key'] ?? null);

            return ['request_id' => $request->id, 'reference' => $request->reference];
        })(),

        'settle' => (function () use ($args) {
            $batch = app(InstructorEarningServiceInterface::class)
                ->createSettlementBatch((int) $args['instructor_id'], $args['currency_code']);

            return ['batch_id' => $batch->id, 'reference' => $batch->batch_reference];
        })(),

        // Retry variant for the release-vs-settle race: settlement may
        // legitimately lose while the reservation is still live; it must
        // succeed once the release has committed.
        'settle-retry' => (function () use ($args) {
            $service = app(InstructorEarningServiceInterface::class);
            $deadline = microtime(true) + 10;

            while (true) {
                try {
                    $batch = $service->createSettlementBatch((int) $args['instructor_id'], $args['currency_code']);

                    return ['batch_id' => $batch->id, 'reference' => $batch->batch_reference];
                } catch (EarningException $e) {
                    if (microtime(true) > $deadline) {
                        throw $e;
                    }
                    usleep(50_000);
                }
            }
        })(),

        'cancel' => (function () use ($args) {
            $instructor = User::query()->findOrFail($args['instructor_id']);
            $request = InstructorWithdrawalRequest::query()->findOrFail($args['request_id']);

            app(InstructorWithdrawalServiceInterface::class)
                ->cancelByInstructor($request, $instructor);

            return ['cancelled' => true];
        })(),

        'activate-agreement' => (function () use ($args) {
            $admin = User::query()->findOrFail($args['admin_id']);
            $agreement = InstructorCompensationAgreement::query()->findOrFail($args['agreement_id']);

            app(InstructorCompensationAgreementServiceInterface::class)
                ->activate($agreement, $admin, 'Concurrent activation test.');

            return ['agreement_id' => $agreement->id];
        })(),

        'accrue-periodic' => (function () {
            $accrued = app(InstructorPeriodicCompensationServiceInterface::class)
                ->accrueClosedPeriods();

            return ['accrued' => $accrued];
        })(),

        'retry-blocked' => (function () use ($args) {
            $lesson = Lesson::query()->findOrFail($args['lesson_id']);

            $earning = app(InstructorEarningServiceInterface::class)
                ->createFromLesson($lesson);

            return ['earning_id' => $earning?->id];
        })(),

        'set-default' => (function () use ($args) {
            $instructor = User::query()->findOrFail($args['instructor_id']);
            $method = InstructorPayoutMethod::query()->findOrFail($args['method_id']);

            app(InstructorPayoutMethodServiceInterface::class)
                ->setDefault($method, $instructor);

            return ['method_id' => $method->id];
        })(),

        default => throw new InvalidArgumentException("Unknown operation: {$operation}"),
    };

    echo json_encode(['ok' => true, 'op' => $operation, 'result' => $result]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'op' => $operation, 'exception' => $e::class, 'message' => $e->getMessage()]);
}
