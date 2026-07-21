<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Services\AuditTrailService;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Settings;
use Throwable;

/**
 * Shared audit-logging behaviour for settings pages. Spatie Settings
 * objects are plain property bags with no model events of their own, so
 * pages must snapshot state themselves: call snapshotSettings() before
 * mutating the object, then logSettingsUpdate() after ->save().
 *
 * Only fields that actually changed are recorded — an unchanged save
 * (form submitted with no edits) logs nothing, keeping this quiet for
 * the common "opened the page, clicked save anyway" case.
 */
trait LogsSettingsUpdates
{
    /**
     * Bank/payment identifiers that must never be stored as plaintext
     * before/after values in the general audit log — an explicit
     * allowlist rather than substring matching, since names like "iban"
     * or "upi_id" share no common fragment with each other or with a
     * generic secret/password naming convention, so substring matching
     * alone would either miss them or risk over-matching an unrelated
     * field by accident.
     *
     * account_holder_name is included too: it's a personal name, and
     * absent any explicit project policy permitting plaintext personal
     * names inside settings-change audit metadata (the closest existing
     * convention, docs/architecture/activity-audit-foundation.md, says
     * not to log sensitive content), it gets the same presence-only
     * treatment as a financial identifier.
     *
     * @var array<string>
     */
    private const array FINANCIAL_IDENTIFIER_FIELDS = [
        'account_number',
        'account_holder_name',
        'upi_id',
        'ifsc_code',
        'swift_code',
        'bic',
        'bic_code',
        'iban',
        'routing_number',
        'sort_code',
        'beneficiary_account_number',
        'razorpayx_account_number',
    ];

    /**
     * Settings classes lazily hydrate: Spatie's Settings::__get()/__set()
     * only populate real object properties on first access (via
     * loadValues()), so a raw get_object_vars() called immediately after
     * app($class) — before anything has touched a property — silently
     * returns an EMPTY array. In production this is exactly what happens
     * on every save: the "save" Livewire request never runs mount()
     * (that only fires once, on the page's initial load, in a separate
     * request), so app($settingsClass) here is genuinely the first touch
     * in this request. An empty $before makes every field look
     * unrecognized to logSettingsUpdate()'s array_key_exists() check, so
     * the diff is always empty and no audit record is ever written —
     * confirmed empirically to reproduce with the trait's original
     * get_object_vars() implementation. Settings::toArray() forces
     * hydration correctly (it reads through __get()) and is used here
     * instead.
     *
     * @return array<string, mixed>
     */
    protected function snapshotSettings(Settings $settings): array
    {
        return $settings->toArray();
    }

    /**
     * Persists a settings mutation and its audit record as one atomic
     * unit. Settings classes are bound scoped() in the container (Spatie
     * SettingsContainer — confirmed empirically: app($class) returns the
     * same in-memory instance for the life of the request/test) and the
     * activity_log table uses the same default database connection as
     * everything else here, and AuditTrailService::logUser() writes
     * synchronously (no queue) — so wrapping mutate + save + audit in one
     * DB::transaction() means a failure anywhere inside rolls back
     * everything: a settings change can never commit without its audit
     * event, and no audit event is ever written for a mutation that
     * didn't actually commit.
     *
     * The after-state used for the audit diff is derived from the same
     * settings instance immediately after ->save() (via toArray(), which
     * forces hydration — see snapshotSettings()), rather than forcing a
     * container-bypassing reload from the database: ->save() persists
     * these properties as-is with no transform on the way back, so a
     * forced forgetInstance()+re-resolve round-trip would just rebuild
     * an identical object from the exact rows ->save() wrote moments
     * earlier — no correctness benefit, only a redundant query.
     *
     * On failure, the original exception is sent to the application log
     * (report()) for operator debugging, but never re-thrown or shown to
     * the user directly — a QueryException's message can include raw
     * bound values (e.g. an account number), so only a fixed, generic
     * notification is surfaced.
     *
     * @param  class-string  $settingsClass
     * @param  Closure(Settings): void  $mutate  mutates settings fields only — must never call ->save() itself
     * @param  array<string, mixed>  $extraProperties  merged into the audit event's properties (e.g. a change reason) — never used for field values themselves
     * @return bool true if the change (if any) committed; false if the save failed and was rolled back
     */
    protected function saveSettingsWithAudit(string $settingsClass, string $logName, Closure $mutate, array $extraProperties = []): bool
    {
        $settings = app($settingsClass);
        $before = $this->snapshotSettings($settings);

        try {
            DB::transaction(function () use ($settings, $logName, $before, $mutate, $extraProperties): void {
                $mutate($settings);
                $settings->save();
                $this->logSettingsUpdate($logName, $settings, $before, $extraProperties);
            });

            return true;
        } catch (Throwable $e) {
            // $settings may already have been mutated in memory before
            // the failure (e.g. by the audit write, which runs after
            // ->save()). Settings classes are bound scoped() — the same
            // instance lives for the rest of this request — so without
            // this, a rolled-back database row would leave behind an
            // in-memory object holding values that were never actually
            // persisted. Forgetting the scoped instance forces the next
            // app($settingsClass) resolution to rebuild fresh from the
            // database, so callers only ever observe committed state.
            app()->forgetInstance($settingsClass);

            report($e);

            Notification::make()
                ->title('Settings not saved')
                ->body('An error occurred while saving. No changes were made.')
                ->danger()
                ->send();

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $extraProperties  merged alongside settings_class/changed — e.g. ['reason' => $reason]
     */
    protected function logSettingsUpdate(string $logName, Settings $settings, array $before, array $extraProperties = []): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $changed = [];

        foreach ($settings->toArray() as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] === $value) {
                continue;
            }

            $changed[$key] = $this->isSensitiveField($key)
                ? $this->redactedChange($before[$key], $value)
                : ['from' => $before[$key], 'to' => $value];
        }

        if ($changed === []) {
            return;
        }

        app(AuditTrailService::class)->logUser(
            $user,
            $logName,
            'settings_updated',
            class_basename($settings).' updated',
            null,
            [...['settings_class' => $settings::class, 'changed' => $changed], ...$extraProperties],
        );
    }

    /**
     * Never records the old or new value itself (plaintext or
     * ciphertext) — only whether a secret was present before/after and
     * what kind of change occurred, which is all a reviewer needs
     * without the audit log becoming a second place a credential is
     * stored.
     *
     * @return array{changed: true, previously_set: bool, now_set: bool, action: string}
     */
    private function redactedChange(mixed $before, mixed $after): array
    {
        $wasSet = filled($before);
        $isSet = filled($after);

        return [
            'changed' => true,
            'previously_set' => $wasSet,
            'now_set' => $isSet,
            'action' => match (true) {
                ! $wasSet && $isSet => 'set',
                $wasSet && ! $isSet => 'cleared',
                default => 'replaced',
            },
        ];
    }

    private function isSensitiveField(string $key): bool
    {
        if (in_array($key, self::FINANCIAL_IDENTIFIER_FIELDS, true)) {
            return true;
        }

        foreach (['password', 'secret', 'token', 'api_key', 'private_key', 'signature', 'salt_key', 'credentials_json'] as $needle) {
            if (str_contains(strtolower($key), $needle)) {
                return true;
            }
        }

        return false;
    }
}
