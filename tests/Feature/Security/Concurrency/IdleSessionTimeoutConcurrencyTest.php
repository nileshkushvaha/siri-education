<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Concurrency;

use App\Models\User;
use App\Models\UserSession;
use App\Settings\SessionSettings;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;
use Tests\Feature\Booking\Concurrency\ConcurrencyTestCase;

/**
 * SRS-1-23: real multi-process race proving an
 * already-expired session cannot be revived by a concurrent request.
 * Two workers evaluate TrackUserSession::expireIfIdle() for the SAME
 * tracked session at the same instant, past its expiry boundary — the
 * row lock inside expireIfIdle() must serialize them so the session is
 * expired (deleted) exactly once, never left alive by a loser reading a
 * stale copy. Reuses Tests\Feature\Booking\Concurrency\ConcurrencyTestCase
 * as-is — its race()/tearDownAfterClass() harness is fully domain-
 * agnostic.
 */
class IdleSessionTimeoutConcurrencyTest extends ConcurrencyTestCase
{
    public function test_concurrent_requests_after_expiry_cannot_revive_the_session(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $settings = app(SessionSettings::class);
        $settings->idle_timeout = 30;
        $settings->save();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('student');

        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        $sessionId = 'concurrency-test-session-id';

        UserSession::query()->create([
            'session_id' => $sessionId,
            'user_id' => $user->id,
            'last_activity_at' => $now,
            'created_at' => $now,
        ]);

        // Both workers evaluate at the same "now" — 35 minutes past the
        // 30-minute timeout — via CarbonImmutable::setTestNow() inside
        // each child process (set once before the barrier, see run-op.php
        // 'idle-session-check').
        $results = $this->race([
            ['idle-session-check', ['user_id' => $user->id, 'session_id' => $sessionId, 'now' => $now->addMinutes(35)->toIso8601String()]],
            ['idle-session-check', ['user_id' => $user->id, 'session_id' => $sessionId, 'now' => $now->addMinutes(35)->toIso8601String()]],
        ]);

        $expired = array_values(array_filter($results, fn (array $r): bool => $r['ok'] && ($r['result']['expired'] ?? false)));
        $notExpired = array_values(array_filter($results, fn (array $r): bool => $r['ok'] && ! ($r['result']['expired'] ?? true)));

        $this->assertCount(1, $expired, json_encode($results));
        $this->assertCount(1, $notExpired, json_encode($results));

        // The session was expired exactly once — the tracked row is gone,
        // never resurrected by the loser.
        $this->assertNull(UserSession::query()->find($sessionId));
    }

    public function test_concurrent_requests_before_expiry_remain_consistent(): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $settings = app(SessionSettings::class);
        $settings->idle_timeout = 30;
        $settings->save();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('student');

        $now = CarbonImmutable::parse('2026-01-01 10:00:00');
        $sessionId = 'concurrency-test-session-id-2';

        UserSession::query()->create([
            'session_id' => $sessionId,
            'user_id' => $user->id,
            'last_activity_at' => $now,
            'created_at' => $now,
        ]);

        // Both workers evaluate 10 minutes in — well before the 30-minute
        // timeout — and must consistently agree "not expired".
        $results = $this->race([
            ['idle-session-check', ['user_id' => $user->id, 'session_id' => $sessionId, 'now' => $now->addMinutes(10)->toIso8601String()]],
            ['idle-session-check', ['user_id' => $user->id, 'session_id' => $sessionId, 'now' => $now->addMinutes(10)->toIso8601String()]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
            $this->assertFalse($result['result']['expired']);
        }

        $this->assertNotNull(UserSession::query()->find($sessionId));
    }
}
