<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dependency-free CSV export shared by table bulk actions.
 * Columns map header label => attribute path or Closure(record): scalar.
 */
final class CsvExport
{
    /** @param array<string, string|Closure> $columns */
    public static function download(Collection $records, array $columns, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($records, $columns): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, array_keys($columns));

            foreach ($records as $record) {
                fputcsv($out, array_map(
                    fn (string|Closure $column): string => (string) ($column instanceof Closure
                        ? $column($record)
                        : data_get($record, $column)),
                    array_values($columns),
                ));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
