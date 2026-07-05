<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Services\AuditTrailService;

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
    /** @return array<string, mixed> */
    protected function snapshotSettings(object $settings): array
    {
        return get_object_vars($settings);
    }

    /** @param array<string, mixed> $before */
    protected function logSettingsUpdate(string $logName, object $settings, array $before): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $changed = [];

        foreach (get_object_vars($settings) as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] === $value) {
                continue;
            }

            $changed[$key] = $this->isSensitiveField($key)
                ? ['from' => '(redacted)', 'to' => '(redacted)']
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
            ['settings_class' => $settings::class, 'changed' => $changed],
        );
    }

    private function isSensitiveField(string $key): bool
    {
        foreach (['password', 'secret', 'token', 'api_key'] as $needle) {
            if (str_contains(strtolower($key), $needle)) {
                return true;
            }
        }

        return false;
    }
}
