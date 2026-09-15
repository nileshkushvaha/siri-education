<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Actions;

use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

/**
 * The overflow menu next to Details, on the list and the Details page:
 * Download and Retry ingestion first, then a "Student access" section
 * holding Withhold / Restore. Each action keeps its own visibility and
 * its own server-side authorization; the group only arranges them.
 */
final class RecordingActionGroup
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            DownloadRecordingAction::make(),
            RetryRecordingIngestionAction::make(),
            AttachExternalRecordingAction::make(),
            ActionGroup::make([
                WithholdStudentAccessAction::make(),
                RestoreStudentAccessAction::make(),
            ])
                ->dropdown(false),
        ])
            ->label('Actions')
            ->icon(Heroicon::OutlinedEllipsisVertical)
            ->tooltip('Download, retry, attach and student access')
            ->dropdownPlacement('bottom-end');
    }
}
