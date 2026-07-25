<?php

declare(strict_types=1);

namespace App\SupportCases\Enums;

/**
 * SRS §25.31, trimmed to types reachable without the excluded
 * financial/booking-mutation automation (§Exclusions — a resolution
 * type records what staff did through the proper module, it never
 * performs the action itself).
 */
enum SupportCaseResolutionType: string
{
    case InformationProvided = 'information_provided';
    case BookingActionTakenElsewhere = 'booking_action_taken_elsewhere';
    case WalletRefundCreditedElsewhere = 'wallet_refund_credited_elsewhere';
    case PaymentIssueResolvedElsewhere = 'payment_issue_resolved_elsewhere';
    case EarningAdjustedElsewhere = 'earning_adjusted_elsewhere';
    case WithdrawalIssueResolvedElsewhere = 'withdrawal_issue_resolved_elsewhere';
    case ReviewModerationActionTakenElsewhere = 'review_moderation_action_taken_elsewhere';
    case TechnicalIssueResolved = 'technical_issue_resolved';
    case UserAdvised = 'user_advised';
    case PolicyUpheld = 'policy_upheld';
    case CaseRejected = 'case_rejected';
    case DuplicateClosed = 'duplicate_closed';

    public function label(): string
    {
        return match ($this) {
            self::InformationProvided => 'Information Provided',
            self::BookingActionTakenElsewhere => 'Booking Action Taken (via Booking module)',
            self::WalletRefundCreditedElsewhere => 'Wallet Refund Credited (via Wallet module)',
            self::PaymentIssueResolvedElsewhere => 'Payment Issue Resolved (via Payment module)',
            self::EarningAdjustedElsewhere => 'Instructor Earning Adjusted (via Earnings module)',
            self::WithdrawalIssueResolvedElsewhere => 'Withdrawal Issue Resolved (via Withdrawal module)',
            self::ReviewModerationActionTakenElsewhere => 'Review Moderation Action Taken (via Review module)',
            self::TechnicalIssueResolved => 'Technical Issue Resolved',
            self::UserAdvised => 'User Advised',
            self::PolicyUpheld => 'Policy Upheld',
            self::CaseRejected => 'Case Rejected',
            self::DuplicateClosed => 'Duplicate — Closed',
        };
    }
}
