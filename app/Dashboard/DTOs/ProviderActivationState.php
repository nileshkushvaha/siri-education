<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

/**
 * Honest activation state for one real-money provider, read from the
 * authoritative settings written by the domain's own configuration
 * service (`*_config_status` — credential validation — combined with
 * `*_enabled`, the admin's on/off switch). Never contains a credential,
 * key fragment, account number or webhook secret.
 *
 * This exists so the dashboard can distinguish "no financial activity"
 * from "this provider has never been activated": rendering an empty
 * collections figure as though it were evidence of zero business would
 * be a factual misstatement while activation is still pending.
 */
final readonly class ProviderActivationState
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $enabled,
        public bool $credentialsReady,
        public string $statusLabel,
        public string $detail,
        public ?string $settingsUrl = null,
    ) {}

    /** Activated means both switched on AND credential-validated — either alone is not enough. */
    public function isActivated(): bool
    {
        return $this->enabled && $this->credentialsReady;
    }

    public function severityColor(): string
    {
        return $this->isActivated() ? 'success' : 'warning';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'credentials_ready' => $this->credentialsReady,
            'status_label' => $this->statusLabel,
            'detail' => $this->detail,
            'settings_url' => $this->settingsUrl,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            label: (string) $data['label'],
            enabled: (bool) $data['enabled'],
            credentialsReady: (bool) $data['credentials_ready'],
            statusLabel: (string) $data['status_label'],
            detail: (string) $data['detail'],
            settingsUrl: $data['settings_url'] === null ? null : (string) $data['settings_url'],
        );
    }
}
