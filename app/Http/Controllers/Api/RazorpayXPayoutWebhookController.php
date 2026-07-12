<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Earnings\Contracts\InstructorPayoutExecutionServiceInterface;
use App\Earnings\Contracts\InstructorPayoutProviderRegistryInterface;
use App\Earnings\Exceptions\PayoutProviderException;
use App\Earnings\Providers\RazorpayX\RazorpayXInstructorPayoutProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RazorpayX → instructor payout status notifications. Dedicated route,
 * deliberately separate from the booking-payment webhook and the
 * generic payment webhook scaffold — this is a different financial
 * domain (see docs/payment-collection-and-payout-provider-routing.md).
 * No session auth, CSRF-exempt (this route is mounted under
 * routes/api.php, which never loads the `web` middleware group). All
 * signature verification and payload parsing happens inside
 * RazorpayXInstructorPayoutProvider::normalizeEvent() — this controller
 * never inspects the raw payload itself and never logs it.
 */
final class RazorpayXPayoutWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        InstructorPayoutProviderRegistryInterface $providers,
        InstructorPayoutExecutionServiceInterface $execution,
    ): JsonResponse {
        abort_unless($providers->has(RazorpayXInstructorPayoutProvider::KEY), 404);

        $provider = $providers->get(RazorpayXInstructorPayoutProvider::KEY);

        try {
            $event = $provider->normalizeEvent($request);
        } catch (PayoutProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        $execution->handleNormalizedEvent($event);

        return response()->json(['status' => 'processed']);
    }
}
