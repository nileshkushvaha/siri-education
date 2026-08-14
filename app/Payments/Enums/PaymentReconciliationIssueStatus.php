<?php

declare(strict_types=1);

namespace App\Payments\Enums;

/**
 * Phase 4E.2 — deliberately two states, not a workflow.
 *
 * This queue exists so a financial discrepancy is VISIBLE, not so an
 * operator can process money through it. There is no
 * acknowledged/in-progress/escalated ladder because none of those would
 * change what the platform is allowed to do: settlement remains
 * reachable only through verified provider evidence, whatever an
 * operator marks here.
 *
 * `Resolved` is reached in exactly two ways:
 *  - automatically, when a later verified event settles the attempt
 *    successfully (the discrepancy was transient or corrected upstream);
 *  - manually, when an operator with the explicit resolve permission
 *    records that it was handled out-of-band, with a reason.
 *
 * Neither path touches Payment, StudentPackagePurchase, or any
 * entitlement.
 */
enum PaymentReconciliationIssueStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Resolved => 'success',
        };
    }
}
