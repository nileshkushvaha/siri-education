<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

/**
 * The visually subordinate super-admin system strip. Each field is
 * nullable and populated only when the viewer holds that specific
 * system permission — a manager without `queue_monitor.view` never
 * causes the failed-job query to run, because the composition service
 * checks the permission before reading.
 */
final readonly class SystemHealthData
{
    /**
     * @param  list<ProviderActivationState>  $providers
     * @param  list<array{label: string, url: string, icon: string, description: string}>  $links
     */
    public function __construct(
        public ?int $failedJobCount,
        public ?int $criticalJobAlertCount,
        public ?string $schedulerLastRunLabel,
        public ?bool $schedulerHealthy,
        public array $providers,
        public array $links,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'failed_job_count' => $this->failedJobCount,
            'critical_job_alert_count' => $this->criticalJobAlertCount,
            'scheduler_last_run_label' => $this->schedulerLastRunLabel,
            'scheduler_healthy' => $this->schedulerHealthy,
            'providers' => array_map(static fn (ProviderActivationState $p): array => $p->toArray(), $this->providers),
            'links' => $this->links,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            failedJobCount: $data['failed_job_count'] === null ? null : (int) $data['failed_job_count'],
            criticalJobAlertCount: $data['critical_job_alert_count'] === null ? null : (int) $data['critical_job_alert_count'],
            schedulerLastRunLabel: $data['scheduler_last_run_label'] === null ? null : (string) $data['scheduler_last_run_label'],
            schedulerHealthy: $data['scheduler_healthy'] === null ? null : (bool) $data['scheduler_healthy'],
            providers: array_map(ProviderActivationState::fromArray(...), $data['providers']),
            links: $data['links'],
        );
    }

    public function hasAnything(): bool
    {
        return $this->failedJobCount !== null
            || $this->criticalJobAlertCount !== null
            || $this->schedulerLastRunLabel !== null
            || $this->providers !== []
            || $this->links !== [];
    }
}
