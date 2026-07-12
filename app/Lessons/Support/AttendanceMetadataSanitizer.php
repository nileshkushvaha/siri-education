<?php

declare(strict_types=1);

namespace App\Lessons\Support;

/**
 * The single sanitization rule for attendance metadata, shared by
 * RecordAttendanceAction and the provider ingestion layer: scalar
 * values only, sensitive keys (tokens, secrets, emails, phones, links,
 * addresses, IPs) always excluded, strings capped, entry count capped.
 * Raw provider payloads must never survive past this filter.
 */
final class AttendanceMetadataSanitizer
{
    private const string SENSITIVE_KEY_PATTERN = '/token|secret|password|passcode|authorization|api[_-]?key|email|phone|address|url|link|transcript|ip/i';

    private const int MAX_ENTRIES = 20;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar>
     */
    public static function sanitize(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $clean[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;

            if (count($clean) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return $clean;
    }
}
