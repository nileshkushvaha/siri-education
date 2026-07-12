<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\AuditTrailService;
use App\Settings\PaymentAdvancedSettings;
use Illuminate\Support\Facades\Log;

/**
 * The generic gateway webhook path (routes/api.php:
 * `/webhooks/payments/generic/{gateway}`) — logs/audits only. It never
 * settles a booking payment, never credits a wallet, never touches any
 * domain model. It belongs to the pre-booking generic-invoicing
 * settings scaffold (`PaymentGatewaySettings`/`PaymentSettingsPage`),
 * not to the Booking payment pipeline. The real, authoritative
 * settlement route for Stripe/Razorpay is
 * `BookingPaymentWebhookController` at
 * `/webhooks/bookings/payments/{provider}` — never point a real
 * gateway's webhook configuration at this class instead (see the
 * default-URL comment in PaymentSettingsPage::mount()).
 */
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

        // This is a dead end by design — see the class docblock. Domain-
        // specific reconciliation lives in BookingPaymentReconciliationService
        // (Phase 16C), which never reads from this generic/inert path.
    }
}
