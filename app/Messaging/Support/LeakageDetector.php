<?php

declare(strict_types=1);

namespace App\Messaging\Support;

/**
 * SRS §17.32/§17.33: "Version 1 may rely on policy, reporting, and
 * admin review" — this is deterministic, advisory flagging only.
 * No AI, no blocking, and critically: never mutates the message body
 * (requirement #6 "Do not silently alter message text"). A flagged
 * message is still sent; the flags exist purely so admin oversight
 * (§17.36 "Search flagged messages") can surface it.
 */
final class LeakageDetector
{
    private const array OFF_PLATFORM_KEYWORDS = [
        'whatsapp', 'telegram', 'skype', 'signal app', 'wechat',
        'venmo', 'paypal', 'zelle', 'cash app', 'cashapp', 'google pay', 'upi id',
    ];

    /** @return list<string> flag keys, empty when nothing was detected */
    public function detect(string $body): array
    {
        $flags = [];

        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $body) === 1) {
            $flags[] = 'email_address';
        }

        if ($this->containsPhoneNumber($body)) {
            $flags[] = 'phone_number';
        }

        if ($this->containsExternalLink($body)) {
            $flags[] = 'external_link';
        }

        if ($this->containsOffPlatformKeyword($body)) {
            $flags[] = 'off_platform_keyword';
        }

        return $flags;
    }

    /** A run of phone-like characters containing 7+ digits total, tolerant of spaces/dashes/dots/parens/leading +. */
    private function containsPhoneNumber(string $body): bool
    {
        if (preg_match_all('/[0-9()+\-.\s]{7,}/', $body, $matches) === 0) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            if (mb_strlen((string) preg_replace('/\D/', '', $candidate)) >= 7) {
                return true;
            }
        }

        return false;
    }

    private function containsExternalLink(string $body): bool
    {
        if (preg_match_all('/https?:\/\/\S+/i', $body, $matches) === 0) {
            return false;
        }

        $ownHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ($matches[0] as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if ($host !== null && $host !== $ownHost) {
                return true;
            }
        }

        return false;
    }

    private function containsOffPlatformKeyword(string $body): bool
    {
        $lower = mb_strtolower($body);

        foreach (self::OFF_PLATFORM_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
