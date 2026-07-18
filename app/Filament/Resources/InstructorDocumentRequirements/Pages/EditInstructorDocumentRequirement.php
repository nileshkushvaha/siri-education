<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorDocumentRequirements\Pages;

use App\Filament\Resources\InstructorDocumentRequirements\InstructorDocumentRequirementResource;
use App\Models\User;
use App\Services\Instructor\InstructorDocumentRequirementService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditInstructorDocumentRequirement extends EditRecord
{
    protected static string $resource = InstructorDocumentRequirementResource::class;

    /** Routed through the service so every update is audited (see Part 9) — no delete action registered (see InstructorDocumentRequirementResource::canDelete()). */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(InstructorDocumentRequirementService::class)->update($actor, $record, $data);
    }
}
