<?php

declare(strict_types=1);

namespace App\Notifications\Templates;

use InvalidArgumentException;

/**
 * GAP-039 requirement #1 — the single, code-owned source of truth for
 * every admin-editable template's default content and allowlisted
 * variables. Nothing here is ever written by an admin action; this is
 * what a "restore default" and an invalid-override fallback both read.
 *
 * Body text is one string with one line per `\n` — the renderer splits
 * on that, interpolates, drops any line that ends up empty (this is
 * how an optional line like a campaign name disappears cleanly when
 * its variable is blank), and the notification calls ->line() per
 * remaining line. Action buttons/URLs are never part of template text
 * — see each updated notification class's toMail().
 */
final class NotificationTemplateRegistry
{
    /** @return array<string, NotificationTemplateDefinition> keyed by NotificationTemplateKey::value */
    public static function all(): array
    {
        static $definitions = null;

        return $definitions ??= self::build();
    }

    public static function get(NotificationTemplateKey $key): NotificationTemplateDefinition
    {
        return self::all()[$key->value]
            ?? throw new InvalidArgumentException(sprintf('No template definition registered for "%s".', $key->value));
    }

    /** @return array<string, NotificationTemplateDefinition> */
    private static function build(): array
    {
        $definitions = [
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::BookingConfirmed,
                category: 'Booking',
                description: 'Sent to both student and instructor once a booking is confirmed.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'Booking {{booking_reference}} confirmed',
                        defaultBody: "Your {{booking_type}} on {{lesson_datetime}} ({{timezone}}) is confirmed.\nReference: {{booking_reference}}",
                        variables: ['booking_type', 'lesson_datetime', 'timezone', 'booking_reference'],
                    ),
                ],
            ),
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::WalletRechargeSucceeded,
                category: 'Wallet',
                description: 'Sent to the student after a wallet recharge is verified and credited.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'Wallet recharge successful',
                        defaultBody: "Your wallet was recharged with {{amount}}.\nReference: {{reference}}",
                        variables: ['amount', 'reference'],
                    ),
                    'database' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Database,
                        defaultSubject: 'Wallet Recharge Successful',
                        defaultBody: 'Your wallet was recharged with {{amount}}.',
                        variables: ['amount'],
                    ),
                ],
            ),
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::HomeworkDueReminder,
                category: 'Homework',
                description: 'Sent to a student as a homework assignment approaches its due date.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'Homework due {{due_wording}}',
                        defaultBody: "\"{{homework_title}}\" is due {{due_wording}}.\nDue: {{due_date}}\n{{context_label}}",
                        variables: ['homework_title', 'due_wording', 'due_date', 'context_label'],
                    ),
                    'database' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Database,
                        defaultSubject: 'Homework due soon',
                        defaultBody: '"{{homework_title}}" is due {{due_wording}} ({{due_date}}).',
                        variables: ['homework_title', 'due_wording', 'due_date'],
                    ),
                ],
            ),
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::MessageReceived,
                category: 'Messaging',
                description: 'Sent when a user receives a new direct message. Never includes the message body.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'New message from {{sender_name}}',
                        defaultBody: 'You have a new message from {{sender_name}}.',
                        variables: ['sender_name'],
                    ),
                    'database' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Database,
                        defaultSubject: 'New message',
                        defaultBody: 'New message from {{sender_name}}.',
                        variables: ['sender_name'],
                    ),
                ],
            ),
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::SupportCaseCreated,
                category: 'Support',
                description: 'Sent to the requester after a support case is opened.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'Support case {{case_number}} received',
                        defaultBody: "Your support case \"{{case_subject}}\" has been received.\nReference: {{case_number}}",
                        variables: ['case_subject', 'case_number'],
                    ),
                    'database' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Database,
                        defaultSubject: 'Support case created',
                        defaultBody: 'Your support case {{case_number}} has been received.',
                        variables: ['case_number'],
                    ),
                ],
            ),
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::SuspiciousActivityFlagged,
                category: 'Compliance',
                description: 'Sent to authorized administrators when a compliance flag needs review.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'New compliance flag requires review',
                        defaultBody: "A {{severity}}-severity compliance flag ({{reference}}) was recorded and needs review.\nRule: {{rule_name}}.",
                        variables: ['severity', 'reference', 'rule_name'],
                    ),
                    'database' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Database,
                        defaultSubject: 'New compliance flag',
                        defaultBody: 'A {{severity}}-severity compliance flag ({{reference}}) was recorded and needs review.',
                        variables: ['severity', 'reference'],
                    ),
                ],
            ),
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::ReferralRewardCredited,
                category: 'Referral',
                description: 'Sent to a referrer once their reward is credited to their wallet.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'Referral reward credited to your wallet',
                        defaultBody: '{{amount}} has been credited to your wallet — {{referred_name}} completed an eligible class. Thanks for spreading the word!',
                        variables: ['amount', 'referred_name'],
                    ),
                ],
            ),
            new NotificationTemplateDefinition(
                key: NotificationTemplateKey::PromotionalCreditIssued,
                category: 'Wallet',
                description: 'Sent to a student after a promotional credit is issued to their wallet.',
                content: [
                    'mail' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Mail,
                        defaultSubject: 'Promotional credit received',
                        defaultBody: "Your wallet was credited with {{amount}}.\n{{campaign_line}}",
                        variables: ['amount', 'campaign_line'],
                    ),
                    'database' => new ChannelTemplateContent(
                        channel: NotificationTemplateChannel::Database,
                        defaultSubject: 'Promotional Credit Received',
                        defaultBody: 'Your wallet was credited with {{amount}}.',
                        variables: ['amount'],
                    ),
                ],
            ),
        ];

        return collect($definitions)->keyBy(fn (NotificationTemplateDefinition $d): string => $d->key->value)->all();
    }
}
