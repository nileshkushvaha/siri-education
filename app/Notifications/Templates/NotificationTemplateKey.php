<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

/**
 * The closed, stable set of admin-editable
 * template keys. Every case must have a matching definition in
 * NotificationTemplateRegistry. Deliberately a small, representative
 * slice across categories (booking, wallet/payment, homework,
 * messaging, support case, compliance, referral, promotional credit)
 * rather than every one of the ~50 existing notification classes.
 */
enum NotificationTemplateKey: string
{
    case BookingConfirmed = 'booking.confirmed';
    case WalletRechargeSucceeded = 'wallet.recharge_succeeded';
    case HomeworkDueReminder = 'homework.due_reminder';
    case MessageReceived = 'messaging.message_received';
    case SupportCaseCreated = 'support_case.created';
    case SuspiciousActivityFlagged = 'compliance.suspicious_activity_flagged';
    case ReferralRewardCredited = 'referral.reward_credited';
    case PromotionalCreditIssued = 'promotional_credit.issued';
}
