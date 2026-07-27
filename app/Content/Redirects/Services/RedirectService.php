<?php

declare(strict_types=1);

namespace App\Content\Redirects\Services;

use App\Content\Redirects\Enums\RedirectType;
use App\Content\Redirects\Exceptions\RedirectException;
use App\Models\Redirect;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SRS §22.25/26 — the single authoritative boundary for
 * managed redirects: normalization, duplicate/loop/target-safety
 * validation, CRUD, and resolution. Filament and the public routing
 * boundary both call this service; neither writes to the `redirects`
 * table directly.
 */
final class RedirectService
{
    private const int MAX_CHAIN_HOPS = 10;

    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly RedirectDestinationGuard $destinationGuard,
    ) {}

    public function normalizePath(string $value): string
    {
        $path = parse_url(trim($value), PHP_URL_PATH) ?? trim($value);
        $path = '/'.ltrim($path, '/');
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : strtolower($path);
    }

    /**
     * @param  array{source_path: string, target_path: string, type: RedirectType|string, description?: ?string, is_active?: bool}  $attributes
     *
     * @throws RedirectException
     */
    public function create(User $actor, array $attributes): Redirect
    {
        [$source, $target, $type, $isActive] = $this->prepare($attributes);

        return DB::transaction(function () use ($actor, $source, $target, $type, $isActive, $attributes): Redirect {
            if ($isActive) {
                $this->assertNoActiveDuplicate($source);
                $this->assertNoLoop($source, $target);
            }

            try {
                $redirect = Redirect::query()->create([
                    'source_path' => $source,
                    'target_path' => $target,
                    'type' => $type,
                    'is_active' => $isActive,
                    'description' => $attributes['description'] ?? null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                throw $this->translateDuplicateException($e);
            }

            $this->audit->logUser(
                $actor,
                'redirects',
                'redirect_created',
                'Redirect created.',
                $redirect,
                ['source_path' => $source, 'target_path' => $target, 'type' => $type->value, 'is_active' => $isActive],
            );

            return $redirect;
        });
    }

    /**
     * @param  array{source_path?: string, target_path?: string, type?: RedirectType|string, description?: ?string}  $attributes
     *
     * @throws RedirectException
     */
    public function update(User $actor, Redirect $redirect, array $attributes): Redirect
    {
        return DB::transaction(function () use ($actor, $redirect, $attributes): Redirect {
            /** @var Redirect $fresh */
            $fresh = Redirect::query()->whereKey($redirect->getKey())->lockForUpdate()->firstOrFail();

            $source = array_key_exists('source_path', $attributes)
                ? $this->normalizePath($attributes['source_path'])
                : $fresh->source_path;

            $target = array_key_exists('target_path', $attributes)
                ? $this->normalizeAndAssertTarget($attributes['target_path'])
                : $fresh->target_path;

            $type = array_key_exists('type', $attributes)
                ? $this->normalizeType($attributes['type'])
                : $fresh->type;

            $this->assertWellFormedPath($source, 'source');
            $this->assertNotSelfReferential($source, $target);

            $targetChanged = $target !== $fresh->target_path;
            $sourceChanged = $source !== $fresh->source_path;

            if ($fresh->is_active && ($sourceChanged || $targetChanged)) {
                $this->assertNoActiveDuplicate($source, ignoreId: $fresh->id);
                $this->assertNoLoop($source, $target, ignoreId: $fresh->id);
            }

            try {
                $fresh->fill([
                    'source_path' => $source,
                    'target_path' => $target,
                    'type' => $type,
                    'description' => array_key_exists('description', $attributes) ? $attributes['description'] : $fresh->description,
                    'updated_by' => $actor->id,
                ])->save();
            } catch (QueryException $e) {
                throw $this->translateDuplicateException($e);
            }

            $this->audit->logUser(
                $actor,
                'redirects',
                'redirect_updated',
                'Redirect updated.',
                $fresh,
                [
                    'source_path' => $source,
                    'target_path' => $target,
                    'type' => $type->value,
                    'target_changed' => $targetChanged,
                ],
            );

            return $fresh->refresh();
        });
    }

    /** @throws RedirectException */
    public function activate(User $actor, Redirect $redirect): Redirect
    {
        return DB::transaction(function () use ($actor, $redirect): Redirect {
            /** @var Redirect $fresh */
            $fresh = Redirect::query()->whereKey($redirect->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->is_active) {
                return $fresh;
            }

            // The graph may have changed while this row was inactive —
            // re-validate exactly as if it were being created fresh.
            $this->assertNoActiveDuplicate($fresh->source_path, ignoreId: $fresh->id);
            $this->assertNoLoop($fresh->source_path, $fresh->target_path, ignoreId: $fresh->id);

            try {
                $fresh->fill(['is_active' => true, 'updated_by' => $actor->id])->save();
            } catch (QueryException $e) {
                throw $this->translateDuplicateException($e);
            }

            $this->audit->logUser(
                $actor,
                'redirects',
                'redirect_activated',
                'Redirect activated.',
                $fresh,
                ['source_path' => $fresh->source_path],
            );

            return $fresh->refresh();
        });
    }

    public function deactivate(User $actor, Redirect $redirect): Redirect
    {
        return DB::transaction(function () use ($actor, $redirect): Redirect {
            /** @var Redirect $fresh */
            $fresh = Redirect::query()->whereKey($redirect->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->is_active) {
                return $fresh;
            }

            $fresh->fill(['is_active' => false, 'updated_by' => $actor->id])->save();

            $this->audit->logUser(
                $actor,
                'redirects',
                'redirect_deactivated',
                'Redirect deactivated.',
                $fresh,
                ['source_path' => $fresh->source_path],
            );

            return $fresh->refresh();
        });
    }

    /**
     * Bounded, single-indexed-lookup resolution (requirement #4), with
     * a chain walk so a request never bounces through more than one
     * HTTP redirect when the service can resolve the final destination
     * itself. The hop bound is defense in depth only — assertNoLoop()
     * makes an actual cycle impossible to create.
     *
     * @return array{url: string, status: int}|null
     */
    public function resolve(string $requestPath): ?array
    {
        $path = $this->normalizePath($requestPath);

        $entry = Redirect::query()->where('active_source_path', $path)->first();

        if ($entry === null) {
            return null;
        }

        $status = $entry->type->httpStatus();
        $finalTarget = $entry->target_path;
        $visited = [$path, $finalTarget];

        for ($hops = 0; $hops < self::MAX_CHAIN_HOPS; $hops++) {
            $next = Redirect::query()->where('active_source_path', $finalTarget)->first();

            if ($next === null) {
                break;
            }

            if (in_array($next->target_path, $visited, true)) {
                break; // safety net — should be unreachable given creation-time loop validation
            }

            $finalTarget = $next->target_path;
            $visited[] = $finalTarget;
        }

        return ['url' => $finalTarget, 'status' => $status];
    }

    // ── Validation ─────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: RedirectType, 3: bool} */
    private function prepare(array $attributes): array
    {
        $source = $this->normalizePath($attributes['source_path'] ?? '');
        $target = $this->normalizeAndAssertTarget($attributes['target_path'] ?? '');
        $type = $this->normalizeType($attributes['type'] ?? RedirectType::Permanent);
        $isActive = (bool) ($attributes['is_active'] ?? true);

        $this->assertWellFormedPath($source, 'source');
        $this->assertNotSelfReferential($source, $target);

        return [$source, $target, $type, $isActive];
    }

    private function normalizeType(RedirectType|string|int $type): RedirectType
    {
        // Filament/HTML form values for a numeric-looking option key
        // ('301'/'302') arrive PHP-cast to int — string keys that look
        // like integers are always coerced to int array keys.
        return $type instanceof RedirectType ? $type : RedirectType::from((string) $type);
    }

    private function normalizeAndAssertTarget(string $rawTarget): string
    {
        $trimmed = trim($rawTarget);

        if ($trimmed === '') {
            throw new RedirectException('The target path is required.');
        }

        if (str_starts_with($trimmed, '//')) {
            throw new RedirectException('Protocol-relative target URLs are not allowed.');
        }

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            $host = parse_url($trimmed, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if ($host === null || strcasecmp($host, (string) $appHost) !== 0) {
                throw new RedirectException('External redirect targets are not supported.');
            }
        } elseif (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $trimmed) === 1) {
            // Any other scheme (javascript:, data:, mailto:, ftp:, ...).
            throw new RedirectException('The target uses an unsafe or unsupported URL scheme.');
        }

        $target = $this->normalizePath($trimmed);
        $this->assertWellFormedPath($target, 'target');

        if ($this->destinationGuard->isProhibited($target)) {
            throw new RedirectException('Redirects to admin, API, authentication, storage, asset, webhook, or signed-download routes are not allowed.');
        }

        return $target;
    }

    private function assertWellFormedPath(string $path, string $field): void
    {
        if ($path === '' || str_contains($path, '..')) {
            throw new RedirectException("The {$field} path is malformed.");
        }

        if (preg_match('#^/[a-z0-9/_-]*$#', $path) !== 1) {
            throw new RedirectException("The {$field} path contains characters that are not allowed.");
        }
    }

    private function assertNotSelfReferential(string $source, string $target): void
    {
        if ($source === $target) {
            throw new RedirectException('A redirect cannot point to itself.');
        }
    }

    private function assertNoActiveDuplicate(string $source, ?string $ignoreId = null): void
    {
        $exists = Redirect::query()
            ->where('active_source_path', $source)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new RedirectException('An active redirect for this source already exists.');
        }
    }

    /**
     * Walks the ACTIVE-redirect graph from $target, following further
     * active sources, up to a bounded depth. If $source is ever
     * encountered again, the candidate edge would close a cycle
     * (direct if it happens on the first hop, indirect otherwise).
     */
    private function assertNoLoop(string $source, string $target, ?string $ignoreId = null): void
    {
        $current = $target;
        $visited = [$source];

        for ($hops = 0; $hops < self::MAX_CHAIN_HOPS; $hops++) {
            if ($current === $source) {
                throw new RedirectException('This redirect would create a loop.');
            }

            if (in_array($current, $visited, true)) {
                // A cycle exists among OTHER already-active redirects,
                // not involving $source — not this call's concern, but
                // stop walking rather than looping forever here.
                return;
            }

            $visited[] = $current;

            $next = Redirect::query()
                ->where('active_source_path', $current)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->first();

            if ($next === null) {
                return;
            }

            $current = $next->target_path;
        }
    }

    private function translateDuplicateException(QueryException $e): RedirectException
    {
        if (str_contains($e->getMessage(), 'redirects_active_source_path_unique')) {
            return new RedirectException('An active redirect for this source already exists.');
        }

        throw $e;
    }
}
