<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Reporting\Exceptions\ReportExportException;
use App\Reporting\Exports\ReportCsvExporter;
use Filament\Notifications\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared export action for report pages. The page's
 * CURRENT period and filters are passed through unchanged; all
 * authorization, auditing, masking, row-limit and injection handling
 * live in {@see ReportCsvExporter} — this trait only surfaces the
 * result or the safe rejection message. Requires the using page to
 * define period() and filters().
 */
trait ExportsReportCsv
{
    public function exportCsv(string $exportKey): ?StreamedResponse
    {
        try {
            return app(ReportCsvExporter::class)->download(
                auth()->user(),
                $exportKey,
                $this->period(),
                $this->filters(),
            );
        } catch (ReportExportException $e) {
            Notification::make()
                ->danger()
                ->title('Export rejected')
                ->body($e->getMessage())
                ->send();

            return null;
        }
    }

    public function canExportCsv(string $exportKey): bool
    {
        return app(ReportCsvExporter::class)->canExport(auth()->user(), $exportKey);
    }
}
