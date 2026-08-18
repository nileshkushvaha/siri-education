<?php

declare(strict_types=1);

namespace App\Ai\Support;

use Illuminate\Support\Str;

/**
 * Makes a provider error message safe to carry in an exception, a log
 * line or an admin notification.
 *
 * Two independent problems, both real for AI providers:
 *
 *  1. Key-shaped material. An SDK or HTTP error can echo the request,
 *     including the Authorization header. Any long unbroken
 *     token/key-like run is replaced, the same rule the meeting
 *     providers already apply.
 *  2. Prompt echo. Provider validation errors routinely quote the input
 *     back ("your message ... exceeded"), and for this application the
 *     input is student content. Length-capping alone would still leak a
 *     fragment, so quoted spans are dropped entirely.
 */
trait RedactsProviderMessages
{
    private function redact(string $message): string
    {
        $withoutQuotes = preg_replace('/(["\x{201C}\x{201D}]).{0,}?\1/u', '[redacted]', $message) ?? $message;

        $withoutTokens = preg_replace('/[A-Za-z0-9_\-]{20,}/', '[redacted]', $withoutQuotes) ?? $withoutQuotes;

        return Str::limit(trim($withoutTokens) !== '' ? $withoutTokens : 'Unknown AI provider error.', 200);
    }
}
