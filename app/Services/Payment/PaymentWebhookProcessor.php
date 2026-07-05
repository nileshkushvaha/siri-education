<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\AuditTrailService;
use App\Settings\PaymentAdvancedSettings;
use Illuminate\Support\Facades\Log;

final class PaymentWebhookProcessor
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function process(string $gateway, array $payload, array $headers): void
    {
        $advanced = app(PaymentAdvancedSettings::class);
        $event = (string) ($payload['type'] ?? $payload['event'] ?? 'unknown');

        if ($advanced->payment_logging) {
            Log::channel(config('logging.default'))->info('Payment webhook received', [
                'gateway' => $gateway,
                'event' => $event,
                'headers' => $headers,
                'payload' => $payload,
            ]);
        }

        // Audit trail only — never the raw payload. The Activity Log is
        // admin-browsable; gateway payloads can carry tokens/PII the debug
        // log above (engineers-only, not exposed via any admin UI) may.
        if ($advanced->enable_audit_log) {
            $this->audit->logSystem(
                logName: 'payments',
                event: 'webhook_received',
                description: "Payment webhook received from {$gateway} ({$event})",
                properties: [
                    'gateway' => $gateway,
                    'event' => $event,
                    'reference' => $payload['id'] ?? $payload['reference'] ?? null,
                ],
            );
        }

        // Hook point: domain-specific payment reconciliation can be added here.
    }
}
