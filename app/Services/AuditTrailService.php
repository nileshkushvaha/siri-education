<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityActorType;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Single entry point for recording audit trail entries.
 *
 * Architecture rule:
 *  - Business services NEVER call activity() directly.
 *  - They call one of the three methods here, which sets the correct actor type,
 *    captures request context, and persists through Spatie's Activity model.
 *
 * The ActivityObserver fires ActivityCreated after every save, so the notification
 * pipeline and any other downstream listeners receive the fully populated model.
 */
final class AuditTrailService
{
    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Record an action performed by an authenticated user.
     */
    public function logUser(
        User $user,
        string $logName,
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
    ): Activity {
        $ctx = $this->requestContext();

        $logger = activity($logName)
            ->causedBy($user)
            ->event($event)
            ->withProperties(array_merge($properties, array_filter($ctx)));

        if ($subject !== null) {
            $logger = $logger->performedOn($subject);
        }

        return $logger
            ->tap(fn (Activity $a) => $this->fillContext($a, ActivityActorType::User, $ctx))
            ->log($description);
    }

    /**
     * Record an admin override — a manual, critical action that bypasses
     * the normal lifecycle/workflow for a subject (e.g. force-approving an
     * instructor application still under review, manually correcting a
     * financial record). A reason is mandatory and always stored in
     * properties under 'override_reason', and 'is_override' is always
     * true, so these entries can be found/filtered independently of
     * whatever log_name the calling feature uses.
     */
    public function logOverride(
        User $admin,
        string $logName,
        string $event,
        string $description,
        string $reason,
        ?Model $subject = null,
        array $properties = [],
    ): Activity {
        return $this->logUser(
            $admin,
            $logName,
            $event,
            $description,
            $subject,
            [...$properties, 'override_reason' => $reason, 'is_override' => true],
        );
    }

    /**
     * Record an action performed by an anonymous visitor (no authenticated user).
     * Captures guest identity fields and request context.
     */
    public function logGuest(
        string $logName,
        string $event,
        string $description,
        ?Model $subject = null,
        string $guestName = '',
        string $guestEmail = '',
        string $guestPhone = '',
        array $properties = [],
    ): Activity {
        $ctx = $this->requestContext();

        $logger = activity($logName)
            ->event($event)
            ->withProperties(array_merge($properties, array_filter($ctx)));

        if ($subject !== null) {
            $logger = $logger->performedOn($subject);
        }

        return $logger
            ->tap(function (Activity $a) use ($guestName, $guestEmail, $guestPhone, $ctx): void {
                $this->fillContext($a, ActivityActorType::Guest, $ctx);
                $a->guest_name = $guestName ?: null;
                $a->guest_email = $guestEmail ?: null;
                $a->guest_phone = $guestPhone ?: null;
            })
            ->log($description);
    }

    /**
     * Record an action performed by the system (queue jobs, scheduler, CLI, etc.).
     * No user or guest identity; marks the activity as automated.
     */
    public function logSystem(
        string $logName,
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
    ): Activity {
        $logger = activity($logName)
            ->event($event)
            ->withProperties($properties);

        if ($subject !== null) {
            $logger = $logger->performedOn($subject);
        }

        return $logger
            ->tap(fn (Activity $a) => $a->actor_type = ActivityActorType::System)
            ->log($description);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function fillContext(Activity $activity, ActivityActorType $type, array $ctx): void
    {
        $activity->actor_type = $type;
        $activity->ip_address = $ctx['ip_address'] ?? null;
        $activity->user_agent = $ctx['user_agent'] ?? null;
        $activity->route = $ctx['route'] ?? null;
        $activity->method = $ctx['method'] ?? null;
        $activity->session_id = $ctx['session_id'] ?? null;
    }

    /**
     * Capture HTTP request context. Returns empty values for CLI/queue contexts
     * so callers never have to guard against a missing request.
     */
    private function requestContext(): array
    {
        try {
            $request = request();

            return [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
                    ? mb_substr($request->userAgent(), 0, 500)
                    : null,
                'route' => $request->path() !== '/' ? $request->path() : null,
                'method' => $request->method(),
                'session_id' => $request->hasSession()
                    ? $request->session()->getId()
                    : null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
