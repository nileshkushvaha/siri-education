<?php

declare(strict_types=1);

namespace App\Filament\Resources\Recordings\Pages;

use App\Filament\Resources\Recordings\Actions\DownloadRecordingAction;
use App\Filament\Resources\Recordings\Actions\RestoreStudentAccessAction;
use App\Filament\Resources\Recordings\Actions\RetryRecordingIngestionAction;
use App\Filament\Resources\Recordings\Actions\WithholdStudentAccessAction;
use App\Filament\Resources\Recordings\RecordingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRecording extends ViewRecord
{
    protected static string $resource = RecordingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadRecordingAction::make(),
            WithholdStudentAccessAction::make(),
            RestoreStudentAccessAction::make(),
            RetryRecordingIngestionAction::make(),
        ];
    }
}
