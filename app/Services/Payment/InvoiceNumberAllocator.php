<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\InvoiceNumberSequence;
use App\Settings\InvoiceSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The single authoritative invoice-number allocator (SRS §14.22).
 * Scope is annual: the sequence portion resets to 1 for a new
 * calendar year, matching the SRS's own example (STEM/INV/2026/000001
 * — the year is embedded in the number, implying a per-year counter).
 *
 * Must be called from inside the same DB::transaction() as the
 * invoice insert it numbers — allocate() takes and releases its own
 * row lock, so calling it outside a transaction that also creates the
 * Invoice row would let a second allocation observe this number as
 * "spent" before the first invoice actually commits.
 *
 * Concurrency-safe: the sequence row is guaranteed to exist via an
 * atomic INSERT ... ON DUPLICATE KEY UPDATE before it is locked and
 * read, so two concurrent first-allocations for a new year can never
 * both try to INSERT the same scope_key and race each other — one
 * lock-and-increment always waits for the other. The invoices table's
 * own unique(invoice_number) constraint is the final defense, not the
 * only one.
 */
final class InvoiceNumberAllocator
{
    public function allocate(): string
    {
        $settings = app(InvoiceSettings::class);
        $scopeKey = (string) now()->year;

        // Guarantees the row exists without racing a concurrent first
        // allocation for the same (new) scope — an ordinary
        // find-then-create here would let two transactions both see
        // "no row" and both attempt to INSERT the same scope_key.
        DB::statement(
            'INSERT INTO invoice_number_sequences (scope_key, next_number, created_at, updated_at) '
            .'VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE scope_key = scope_key',
            [$scopeKey, now(), now()],
        );

        $sequence = InvoiceNumberSequence::query()
            ->where('scope_key', $scopeKey)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $sequence->next_number;
        $sequence->increment('next_number');

        return $this->format($settings, $scopeKey, $number);
    }

    private function format(InvoiceSettings $settings, string $scopeKey, int $number): string
    {
        $template = $settings->number_format;

        if (! str_contains($template, '{sequence}')) {
            throw new RuntimeException('Invoice number format must include a {sequence} token.');
        }

        $digits = max(1, $settings->sequence_digits);

        return strtr($template, [
            '{prefix}' => $settings->number_prefix,
            '{year}' => $scopeKey,
            '{sequence}' => str_pad((string) $number, $digits, '0', STR_PAD_LEFT),
        ]);
    }
}
