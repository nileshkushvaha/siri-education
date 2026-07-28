<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorDocumentRequirements\Pages;

use App\Filament\Navigation\Concerns\HasSectionBreadcrumb;
use App\Filament\Resources\InstructorDocumentRequirements\InstructorDocumentRequirementResource;
use App\Filament\Support\Presentation\BackAction;
use App\Models\User;
use App\Services\Instructor\InstructorDocumentRequirementService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditInstructorDocumentRequirement extends EditRecord
{
    use HasSectionBreadcrumb;

    protected static string $resource = InstructorDocumentRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return array_filter([
            BackAction::toResourceIndex(static::getResource(), 'Back to Document Requirements'),
        ]);
    }

    /** Routed through the service so every update is audited (see Part 9) — no delete action registered (see InstructorDocumentRequirementResource::canDelete()). */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(InstructorDocumentRequirementService::class)->update($actor, $record, $data);
    }
}
