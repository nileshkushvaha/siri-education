<?php

declare(strict_types=1);

namespace App\SupportCases\Services;

use App\Models\SupportCaseNumberSequence;
use App\Settings\SupportCaseSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single authoritative case-number allocator (SRS §25.12), mirroring
 * InvoiceNumberAllocator. Scope is annual. Must be called from inside the
 * same DB::transaction() as the support_cases insert it numbers.
 */
final class SupportCaseNumberAllocator
{
    public function allocate(): string
    {
        $settings = app(SupportCaseSettings::class);
        $scopeKey = (string) now()->year;

        DB::statement(
            'INSERT INTO support_case_number_sequences (scope_key, next_number, created_at, updated_at) '
            .'VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE scope_key = scope_key',
            [$scopeKey, now(), now()],
        );

        $sequence = SupportCaseNumberSequence::query()
            ->where('scope_key', $scopeKey)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $sequence->next_number;
        $sequence->increment('next_number');

        return $this->format($settings, $scopeKey, $number);
    }

    private function format(SupportCaseSettings $settings, string $scopeKey, int $number): string
    {
        $template = $settings->number_format;

        if (! str_contains($template, '{sequence}')) {
            throw new RuntimeException('Support case number format must include a {sequence} token.');
        }

        $digits = max(1, $settings->sequence_digits);

        return strtr($template, [
            '{prefix}' => $settings->number_prefix,
            '{year}' => $scopeKey,
            '{sequence}' => str_pad((string) $number, $digits, '0', STR_PAD_LEFT),
        ]);
    }
}
