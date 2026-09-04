<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Actions;

use App\Models\Recording;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Administrative download of the original file. A plain link to the
 * application-proxied download route — RecordingPolicy::download() is
 * re-checked there on every request, so this action only decides
 * whether to SHOW the link. It never builds, and could not build, a
 * storage or provider URL.
 */
final class DownloadRecordingAction
{
    public static function make(): Action
    {
        return Action::make('downloadRecording')
            ->label('Download')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (Recording $record): bool => auth()->user()?->can('download', $record) === true)
            ->url(fn (Recording $record): string => route('admin.recordings.download', $record))
            ->openUrlInNewTab();
    }
}
