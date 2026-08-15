<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Jobs\Payments\ProcessPaymentWebhookJob;
use App\Services\Payment\PaymentWebhookProcessor;
use App\Services\Payment\PaymentWebhookSignatureService;
use App\Settings\PaymentAdvancedSettings;
use App\Settings\PaymentGatewaySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PaymentWebhookController extends Controller
{
    public function __invoke(
        string $gateway,
        Request $request,
        PaymentWebhookSignatureService $signatureService,
        PaymentWebhookProcessor $processor,
    ): JsonResponse {
        abort_unless(in_array($gateway, $this->supportedGateways(), true), 404);

        $gatewaySettings = app(PaymentGatewaySettings::class);
        $advancedSettings = app(PaymentAdvancedSettings::class);

        if (! $this->isGatewayEnabled($gateway, $gatewaySettings)) {
            return response()->json(['message' => 'Gateway webhook is disabled.'], 403);
        }

        if (! $signatureService->isValid($gateway, $request, $gatewaySettings)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($request->json()->all()) ? $request->json()->all() : [];
        /** @var array<string, string> $headers */
        $headers = Arr::map($request->headers->all(), fn (array $value): string => (string) ($value[0] ?? ''));

        if ($advancedSettings->queue_payment_events) {
            ProcessPaymentWebhookJob::dispatch($gateway, $payload, $headers);

            return response()->json(['status' => 'queued'], 202);
        }

        $processor->process($gateway, $payload, $headers);

        return response()->json(['status' => 'processed']);
    }

    /**
     * @return array<string>
     */
    private function supportedGateways(): array
    {
        return [
            'stripe',
            'razorpay',
            'paypal',
            'applepay',
            'manual',
        ];
    }

    private function isGatewayEnabled(string $gateway, PaymentGatewaySettings $settings): bool
    {
        $field = "{$gateway}_enabled";

        return (bool) ($settings->{$field} ?? false);
    }
}
