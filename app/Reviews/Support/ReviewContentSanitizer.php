<?php

declare(strict_types=1);

namespace App\Reviews\Support;

use App\Reviews\DTOs\SanitizedReviewContent;
use App\Reviews\Enums\ReviewContentFlag;

/**
 * Turns raw submitted review text into safe plain text plus a list of
 * WHICH contact-leakage/unsafe-content categories were detected. The
 * matched substrings themselves are redacted from the returned content
 * and never appear in the flags — callers must never log or persist
 * the original raw string, only this sanitizer's output.
 *
 * Detection is deliberately conservative (favours false positives over
 * missed leaks): HTML/scripts are always stripped outright; emails,
 * phone-shaped digit runs, links, and @handles are redacted and
 * flagged; payment-solicitation and promotional-spam phrases are
 * flagged via keyword matching. Flagging never blocks submission in
 * Phase 17I — the caller marks the review Flagged for later moderation.
 */
final class ReviewContentSanitizer
{
    private const string EMAIL_PATTERN = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    private const string PHONE_PATTERN = '/(?:\+?\d[\d\-.\s()]{6,}\d)/';

    private const string URL_PATTERN = '/\b(?:https?:\/\/|www\.)\S+/i';

    private const string BARE_MEETING_DOMAIN_PATTERN = '/\b[a-z0-9-]+\.(?:zoom\.us|us\d*\.zoom\.us)\S*|\b(?:meet\.google\.com|teams\.microsoft\.com|skype\.com|wa\.me|t\.me|calendly\.com|discord\.gg)\S*/i';

    private const string HANDLE_PATTERN = '/(?<![\w.])@[A-Za-z0-9_.]{2,30}\b/';

    private const array PAYMENT_KEYWORDS = [
        'paypal', 'venmo', 'cashapp', 'cash app', 'zelle', 'upi id', 'google pay',
        'phonepe', 'pay me directly', 'pay outside', 'outside the platform',
        'outside this platform', 'bank transfer', 'wire transfer', 'crypto wallet',
        'bitcoin address',
    ];

    private const array SPAM_KEYWORDS = [
        'click here', 'buy now', 'limited time offer', 'act now',
        'subscribe to my channel', 'check out my website', 'dm me for',
        'follow me for', 'discount code', 'use code', 'guaranteed results',
    ];

    public static function sanitize(?string $raw): SanitizedReviewContent
    {
        if ($raw === null || trim($raw) === '') {
            return new SanitizedReviewContent(null, []);
        }

        $flags = [];
        $text = self::stripUnsafeMarkup($raw, $flags);

        // Links first — a phone-number scan over a raw URL would
        // otherwise partial-match its digits before the link itself is
        // redacted, producing a redundant double flag.
        $text = self::redact(self::EMAIL_PATTERN, $text, ReviewContentFlag::Email, $flags);
        $text = self::redact(self::URL_PATTERN, $text, ReviewContentFlag::ExternalLink, $flags);
        $text = self::redact(self::BARE_MEETING_DOMAIN_PATTERN, $text, ReviewContentFlag::ExternalLink, $flags);
        $text = self::redactPhones($text, $flags);
        $text = self::redact(self::HANDLE_PATTERN, $text, ReviewContentFlag::SocialHandle, $flags);
        $text = self::flagKeywords($text, self::PAYMENT_KEYWORDS, ReviewContentFlag::PaymentSolicitation, $flags);
        $text = self::flagKeywords($text, self::SPAM_KEYWORDS, ReviewContentFlag::PromotionalSpam, $flags);

        $text = trim(preg_replace('/\s{2,}/', ' ', $text) ?? $text);

        return new SanitizedReviewContent($text === '' ? null : $text, array_values(array_unique($flags, SORT_REGULAR)));
    }

    /** @param list<ReviewContentFlag> $flags */
    private static function stripUnsafeMarkup(string $raw, array &$flags): string
    {
        // Script/style blocks are removed wholesale — their inner text
        // is never preserved, not just de-tagged.
        $withoutScripts = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $raw) ?? $raw;
        $plain = strip_tags($withoutScripts);

        if ($plain !== $withoutScripts || $withoutScripts !== $raw) {
            $flags[] = ReviewContentFlag::UnsupportedHtml;
        }

        return html_entity_decode($plain, ENT_QUOTES);
    }

    /** @param list<ReviewContentFlag> $flags */
    private static function redact(string $pattern, string $text, ReviewContentFlag $flag, array &$flags): string
    {
        $result = preg_replace($pattern, '[redacted]', $text, -1, $count);

        if ($count > 0) {
            $flags[] = $flag;
        }

        return $result ?? $text;
    }

    /** Digit runs are only a phone number once separators are stripped and 7+ digits remain — avoids flagging "5 stars" or short counts. */
    private static function redactPhones(string $text, array &$flags): string
    {
        return (string) preg_replace_callback(self::PHONE_PATTERN, function (array $match) use (&$flags): string {
            $digitsOnly = preg_replace('/\D/', '', $match[0]) ?? '';

            if (mb_strlen($digitsOnly) < 7) {
                return $match[0];
            }

            $flags[] = ReviewContentFlag::PhoneNumber;

            return '[redacted]';
        }, $text);
    }

    /**
     * @param  list<string>  $keywords
     * @param  list<ReviewContentFlag>  $flags
     */
    private static function flagKeywords(string $text, array $keywords, ReviewContentFlag $flag, array &$flags): string
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($text, $keyword) !== false) {
                $flags[] = $flag;
                $text = str_ireplace($keyword, '[redacted]', $text);
            }
        }

        return $text;
    }
}
