<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What would stop this user being permanently deleted, phrased for an
 * operator — checked before the attempt, not after it fails.
 *
 * Ordinary deletion never reaches this: User soft deletes, so delete() is an
 * UPDATE. This governs force deletion, the only operation the database can
 * still refuse (1451 integrity violation, previously surfaced as a bare
 * "Error while loading page").
 *
 * The constraint list is read from information_schema so a future migration
 * adding another RESTRICT reference is covered automatically.
 */
final class UserDeletionGuard
{
    /** Constraint metadata changes only on migration. */
    private const int SCHEMA_CACHE_SECONDS = 3600;

    /**
     * Not exhaustive: anything absent falls back to a headline of the table name.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'bookings' => 'Bookings',
        'lessons' => 'Lessons',
        'conversations' => 'Message conversations',
        'instructor_earnings' => 'Instructor earnings',
        'instructor_compensation_agreements' => 'Compensation agreements',
        'instructor_compensation_periods' => 'Compensation periods',
        'instructor_payout_attempts' => 'Payout attempts',
        'instructor_payout_methods' => 'Payout methods',
        'instructor_withdrawal_requests' => 'Withdrawal requests',
        'instructor_settlement_batches' => 'Settlement batches',
        'instructor_package_proposals' => 'Package proposals',
        'instructor_rating_aggregates' => 'Rating aggregates',
        'instructor_student_feedback' => 'Lesson feedback',
        'payments' => 'Payment attempts',
        'support_cases' => 'Support cases',
        'homework_due_reminders' => 'Homework reminders',
    ];

    /**
     * Human-readable reasons this user cannot be permanently deleted.
     *
     * @return list<string> empty when a force delete would succeed
     */
    public function blockers(User $user): array
    {
        $counts = [];

        foreach ($this->blockingReferences() as [$table, $column]) {
            try {
                $n = DB::table($table)->where($column, $user->getKey())->count();
            } catch (\Throwable) {
                continue;
            }

            if ($n > 0) {
                $label = self::LABELS[$table] ?? Str::headline($table);
                // Max, not sum: several columns of one table can reference the
                // same row (created_by AND instructor_id).
                $counts[$label] = max($counts[$label] ?? 0, $n);
            }
        }

        arsort($counts);

        return array_map(
            fn (string $label, int $n): string => sprintf('%s (%d)', $label, $n),
            array_keys($counts),
            $counts,
        );
    }

    public function canForceDelete(User $user): bool
    {
        return $this->blockers($user) === [];
    }

    /** Written for an admin: never leaks SQL, constraint names, or raw table names. */
    public function refusalMessage(User $user): string
    {
        $blockers = $this->blockers($user);

        if ($blockers === []) {
            return '';
        }

        $shown = array_slice($blockers, 0, 4);
        $rest = count($blockers) - count($shown);

        return sprintf(
            '%s still has linked records that must be kept for audit and financial history: %s%s. '
            .'The account has been moved to Deleted instead, which hides it everywhere while preserving those records. '
            .'Permanent deletion is only possible for an account with no history.',
            $user->name,
            implode(', ', $shown),
            $rest > 0 ? sprintf(' and %d more', $rest) : '',
        );
    }

    /**
     * Foreign keys a DELETE cannot satisfy. CASCADE and SET NULL are excluded —
     * the database resolves those itself.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function blockingReferences(): array
    {
        return Cache::remember(
            'users:blocking-fk-references',
            self::SCHEMA_CACHE_SECONDS,
            function (): array {
                $rows = DB::select('
                    SELECT k.TABLE_NAME AS tbl, k.COLUMN_NAME AS col
                    FROM information_schema.KEY_COLUMN_USAGE k
                    JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                      ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                     AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                    WHERE k.TABLE_SCHEMA = ?
                      AND k.REFERENCED_TABLE_NAME = ?
                      AND r.DELETE_RULE IN (?, ?)
                ', [DB::getDatabaseName(), 'users', 'RESTRICT', 'NO ACTION']);

                return array_map(
                    fn (object $r): array => [(string) $r->tbl, (string) $r->col],
                    $rows,
                );
            },
        );
    }
}
