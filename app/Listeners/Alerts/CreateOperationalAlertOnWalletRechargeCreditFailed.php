<?php

declare(strict_types=1);

namespace App\Listeners\Alerts;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Models\WalletRecharge;
use App\Wallet\Events\WalletRechargeCreditFailed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SRS §26.39 "Failed Wallet Credit" — the provider genuinely captured
 * the recharge but the wallet credit itself could not be applied.
 * `WalletRechargeCreditFailed` already `implements
 * ShouldDispatchAfterCommit` (requirement #8's isolation is already
 * guaranteed by the event itself), and nothing consumed it before this
 * phase.
 */
final class CreateOperationalAlertOnWalletRechargeCreditFailed implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {}

    public function handle(WalletRechargeCreditFailed $event): void
    {
        $recharge = $event->recharge;

        $this->alerts->createOrMerge(new OperationalAlertSignal(
            type: OperationalAlertType::WalletRechargeCreditFailed,
            category: OperationalAlertType::WalletRechargeCreditFailed->category(),
            severity: OperationalAlertSeverity::Critical,
            title: 'Wallet recharge captured but credit failed',
            summary: sprintf(
                'Recharge %s was captured by the provider but the wallet credit could not be applied. The money is not lost — reconciliation will retry — but this needs an operator\'s attention.',
                $recharge->reference,
            ),
            subjectType: WalletRecharge::class,
            subjectId: (string) $recharge->id,
            metadata: ['reference' => $recharge->reference, 'provider' => $recharge->provider],
        ));
    }
}
