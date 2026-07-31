<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Settings\WhatsAppSettings;

/**
 * The only path from WhatsAppSettings + page context to a safe wa.me
 * click-to-chat URL. Click-to-chat only — no message is ever sent
 * automatically and no conversation is stored.
 */
final class WhatsAppUrlBuilder
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
    ) {}

    /** Digits only — the shape wa.me requires (country code, no symbols). */
    public static function normalizeNumber(string $number): string
    {
        return (string) preg_replace('/[^0-9]/', '', $number);
    }

    public function isEnabled(): bool
    {
        return $this->settings->enabled && self::normalizeNumber($this->settings->number) !== '';
    }

    /** @param  ?string  $path  Path segment (no leading slash), e.g. request()->path() */
    public function isVisibleForPath(?string $path): bool
    {
        $path = trim((string) $path, '/');

        if ($this->settings->excluded_pages !== [] && $this->matchesAny($path, $this->settings->excluded_pages)) {
            return false;
        }

        if ($this->settings->allowed_pages !== [] && ! $this->matchesAny($path, $this->settings->allowed_pages)) {
            return false;
        }

        return true;
    }

    /**
     * Builds a safe wa.me URL, or null when WhatsApp is disabled/unconfigured
     * or the current page is not eligible. The message is always URL-encoded
     * via http_build_query — never hand-concatenated into the query string.
     */
    public function url(?string $path = null): ?string
    {
        if (! $this->isEnabled() || ! $this->isVisibleForPath($path)) {
            return null;
        }

        $number = self::normalizeNumber($this->settings->number);
        $message = $this->settings->default_message;

        $query = $message !== '' ? '?'.http_build_query(['text' => $message]) : '';

        return "https://wa.me/{$number}{$query}";
    }

    /** @param  list<string>  $patterns  Wildcard page patterns, e.g. "dashboard/*" */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch(trim((string) $pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }
}
