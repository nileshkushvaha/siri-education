<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/** Admin-facing result of PaymentGatewayConfigurationService's per-provider check. */
final readonly class PaymentGatewayReadiness
{
    /**
     * @param  list<string>  $issues
     */
    public function __construct(
        public string $provider,
        public string $status,
        public array $issues = [],
    ) {}

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
